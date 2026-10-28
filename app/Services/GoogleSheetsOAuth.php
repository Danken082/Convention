<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JsonException;

class GoogleSheetsOAuth
{
    private const TOKEN_PATH = 'google-sheets/oauth-token';

    public function isOAuthClientConfigured(): bool
    {
        try {
            $this->oauthClient();

            return true;
        } catch (GoogleSheetsException) {
            return false;
        }
    }

    public function isConnected(): bool
    {
        return Storage::disk('local')->exists(self::TOKEN_PATH);
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $client = $this->oauthClient();

        return $client['auth_uri'].'?'.http_build_query([
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function connect(string $authorizationCode, string $redirectUri): void
    {
        $client = $this->oauthClient();

        try {
            $response = Http::acceptJson()->asForm()->post($client['token_uri'], [
                'code' => $authorizationCode,
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ])->throw();
        } catch (ConnectionException $exception) {
            throw new GoogleSheetsException('Google could not complete OAuth authorization.', previous: $exception);
        } catch (RequestException $exception) {
            throw new GoogleSheetsException($this->requestFailureMessage($exception), previous: $exception);
        }

        $refreshToken = $response->json('refresh_token');
        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new GoogleSheetsException(
                'Google did not return a refresh token. Retry authorization and approve offline access.',
            );
        }

        $tokenData = json_encode([
            'client_id' => $client['client_id'],
            'refresh_token' => $refreshToken,
        ], JSON_THROW_ON_ERROR);

        if (! Storage::disk('local')->put(self::TOKEN_PATH, Crypt::encryptString($tokenData))) {
            throw new GoogleSheetsException('The Google authorization token could not be saved to private storage.');
        }

        Cache::forget($this->cacheKey($client['client_id']));
    }

    public function accessToken(): string
    {
        $client = $this->oauthClient();
        if (! $this->isConnected()) {
            throw new GoogleSheetsException(
                'Google Sheets is not connected. Sign in to the admin dashboard and choose Connect Google Sheets.',
            );
        }

        $encryptedToken = Storage::disk('local')->get(self::TOKEN_PATH);
        if (! is_string($encryptedToken) || $encryptedToken === '') {
            throw new GoogleSheetsException('The saved Google authorization is missing. Reconnect Google Sheets.');
        }

        try {
            $tokenData = json_decode(
                Crypt::decryptString($encryptedToken),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException|DecryptException $exception) {
            throw new GoogleSheetsException('The saved Google authorization is unreadable. Reconnect Google Sheets.', previous: $exception);
        }

        if (! is_array($tokenData)
            || ($tokenData['client_id'] ?? null) !== $client['client_id']
            || ! is_string($tokenData['refresh_token'] ?? null)) {
            throw new GoogleSheetsException('The saved Google authorization does not match this OAuth client. Reconnect Google Sheets.');
        }

        return Cache::remember($this->cacheKey($client['client_id']), now()->addMinutes(50), function () use ($client, $tokenData): string {
            try {
                $response = Http::acceptJson()->asForm()->post($client['token_uri'], [
                    'client_id' => $client['client_id'],
                    'client_secret' => $client['client_secret'],
                    'refresh_token' => $tokenData['refresh_token'],
                    'grant_type' => 'refresh_token',
                ])->throw();
            } catch (ConnectionException $exception) {
                throw new GoogleSheetsException('Google could not refresh its authorization. Reconnect Google Sheets.', previous: $exception);
            } catch (RequestException $exception) {
                throw new GoogleSheetsException($this->requestFailureMessage($exception).' Reconnect Google Sheets.', previous: $exception);
            }

            $accessToken = $response->json('access_token');
            if (! is_string($accessToken) || $accessToken === '') {
                throw new GoogleSheetsException('Google did not return an access token. Reconnect Google Sheets.');
            }

            return $accessToken;
        });
    }

    public function clearAuthorization(): void
    {
        $client = $this->oauthClient();
        Storage::disk('local')->delete(self::TOKEN_PATH);
        Cache::forget($this->cacheKey($client['client_id']));
    }

    private function oauthClient(): array
    {
        $path = config('services.google_sheets.credentials_path');
        if (is_string($path) && $path !== ''
            && preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) !== 1) {
            $path = base_path($path);
        }

        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new GoogleSheetsException(
                'GOOGLE_SHEETS_CREDENTIALS_JSON must point to a readable Google OAuth web client JSON file.',
            );
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new GoogleSheetsException('The Google OAuth client JSON file could not be read.');
        }

        try {
            $credentials = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GoogleSheetsException('The Google OAuth client file is invalid JSON.', previous: $exception);
        }

        $client = $credentials['web'] ?? null;
        if (! is_array($client)
            || ! is_string($client['client_id'] ?? null)
            || ! is_string($client['client_secret'] ?? null)
            || ! is_string($client['auth_uri'] ?? null)
            || ! is_string($client['token_uri'] ?? null)) {
            throw new GoogleSheetsException(
                'The configured Google JSON must be an OAuth web client with client ID, client secret, auth URI, and token URI.',
            );
        }

        return $client;
    }

    private function cacheKey(string $clientId): string
    {
        return 'google_sheets_token_'.hash('sha256', $clientId);
    }

    private function requestFailureMessage(RequestException $exception): string
    {
        $response = $exception->response;
        $message = $response->json('error_description')
            ?? $response->json('error.message');

        if (! is_string($message) || $message === '') {
            $message = trim($response->body());
        }

        if ($message === '') {
            $message = 'No error details were returned by Google.';
        }

        return sprintf('Google OAuth returned HTTP %d: %s', $response->status(), $message);
    }
}
