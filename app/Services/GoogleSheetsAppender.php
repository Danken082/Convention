<?php

namespace App\Services;

use App\Models\Registration;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use JsonException;
use OpenSSLAsymmetricKey;

class GoogleSheetsAppender
{
    private const API_BASE_URL = 'https://sheets.googleapis.com/v4';

    private const HEADERS = [
        'District',
        'Surname',
        'Given name',
        'Middle initial',
        'Nickname',
        'Age',
        'Gender',
        'Shirt size',
        'Division',
        'Ministry',
        'Email',
        'Proof of payment',
        'Submitted at',
        'Birthdate',
    ];

    public function appendRegistrations(Collection $registrations): void
    {
        $spreadsheetId = config('services.google_sheets.spreadsheet_id');
        $tabId = (string) config('services.google_sheets.tab_id', '0');
        $credentialsPath = config('services.google_sheets.credentials_path');

        if (! is_string($spreadsheetId) || $spreadsheetId === ''
            || ! is_string($credentialsPath) || $credentialsPath === '') {
            throw new GoogleSheetsException('Google Sheets is not configured.');
        }

        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $credentialsPath) !== 1) {
            $credentialsPath = base_path($credentialsPath);
        }

        try {
            $credentials = $this->readCredentials($credentialsPath);
            $accessToken = isset($credentials['web'])
                ? app(GoogleSheetsOAuth::class)->accessToken()
                : $this->accessToken($credentials);
            $sheet = $this->sheetProperties($spreadsheetId, $tabId, $accessToken);
            $this->ensureHeaders($spreadsheetId, $sheet['title'], $accessToken);
            $this->appendRows(
                $spreadsheetId,
                $sheet['sheetId'],
                $registrations,
                $accessToken,
            );
        } catch (ConnectionException $exception) {
            throw new GoogleSheetsException('Google Sheets could not complete the requested operation.', previous: $exception);
        } catch (RequestException $exception) {
            throw new GoogleSheetsException($this->requestFailureMessage($exception), previous: $exception);
        }
    }

    private function requestFailureMessage(RequestException $exception): string
    {
        $response = $exception->response;
        $message = $response->json('error.message');

        if (! is_string($message) || $message === '') {
            $message = trim($response->body());
        }

        if ($message === '') {
            $message = 'No error details were returned by Google.';
        }

        return sprintf('Google Sheets API returned HTTP %d: %s', $response->status(), $message);
    }

    private function readCredentials(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new GoogleSheetsException(
                'GOOGLE_SHEETS_CREDENTIALS_JSON points to a missing or unreadable Google credentials file.',
            );
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new GoogleSheetsException('The Google service account credentials file could not be read.');
        }

        try {
            $credentials = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GoogleSheetsException('The Google service account credentials file is invalid JSON.', previous: $exception);
        }

        if (is_array($credentials) && isset($credentials['web'])) {
            return $credentials;
        }

        if (is_array($credentials) && isset($credentials['installed'])) {
            throw new GoogleSheetsException(
                'Google OAuth desktop-client JSON is not supported. Configure an OAuth web client JSON file.',
            );
        }

        if (! is_array($credentials)
            || ! is_string($credentials['client_email'] ?? null)
            || ! is_string($credentials['private_key'] ?? null)
            || ! is_string($credentials['token_uri'] ?? null)) {
            throw new GoogleSheetsException('The Google service account credentials are missing required fields.');
        }

        return $credentials;
    }

    private function accessToken(array $credentials): string
    {
        $cacheKey = 'google_sheets_token_'.hash('sha256', $credentials['client_email']);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($credentials): string {
            $issuedAt = now()->timestamp;
            $claims = [
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/spreadsheets',
                'aud' => $credentials['token_uri'],
                'iat' => $issuedAt,
                'exp' => $issuedAt + 3600,
            ];

            $unsignedToken = $this->base64UrlEncode(json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ], JSON_THROW_ON_ERROR)).'.'.$this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));

            $privateKey = openssl_pkey_get_private($credentials['private_key']);
            if (! $privateKey instanceof OpenSSLAsymmetricKey
                || ! openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                throw new GoogleSheetsException('The Google service account private key could not sign a token.');
            }

            $response = Http::asForm()->post($credentials['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsignedToken.'.'.$this->base64UrlEncode($signature),
            ])->throw();

            $token = $response->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new GoogleSheetsException('Google did not return an access token.');
            }

            return $token;
        });
    }

    /**
     * @return array{sheetId: int, title: string}
     */
    private function sheetProperties(string $spreadsheetId, string $tabId, string $accessToken): array
    {
        $response = Http::acceptJson()->withToken($accessToken)
            ->get(self::API_BASE_URL.'/spreadsheets/'.rawurlencode($spreadsheetId), [
                'fields' => 'sheets(properties(sheetId,title))',
            ])
            ->throw();

        foreach ($response->json('sheets', []) as $sheet) {
            $properties = $sheet['properties'] ?? [];
            if (is_int($properties['sheetId'] ?? null)
                && (string) $properties['sheetId'] === $tabId
                && is_string($properties['title'] ?? null)) {
                return [
                    'sheetId' => $properties['sheetId'],
                    'title' => $properties['title'],
                ];
            }
        }

        throw new GoogleSheetsException('The configured Google Sheets tab ID was not found.');
    }

    private function ensureHeaders(string $spreadsheetId, string $sheetTitle, string $accessToken): void
    {
        $range = $this->quotedRange($sheetTitle, 'A1:N1');
        $response = Http::acceptJson()->withToken($accessToken)
            ->get($this->valuesUrl($spreadsheetId, $range))
            ->throw();

        $existingHeaders = $response->json('values.0');
        if ($existingHeaders) {
            if (! in_array('Birthdate', $existingHeaders, true)) {
                Http::acceptJson()->withToken($accessToken)
                    ->put($this->valuesUrl($spreadsheetId, $this->quotedRange($sheetTitle, 'N1:N1')).'?valueInputOption=RAW', [
                        'values' => [['Birthdate']],
                    ])
                    ->throw();
            }

            return;
        }

        Http::acceptJson()->withToken($accessToken)
            ->put($this->valuesUrl($spreadsheetId, $range).'?valueInputOption=RAW', [
                'values' => [self::HEADERS],
            ])
            ->throw();
    }

    private function appendRows(
        string $spreadsheetId,
        int $sheetId,
        Collection $registrations,
        string $accessToken,
    ): void {
        $proofPaths = $registrations->pluck('proof_of_payment_path')->unique()->all();
        $previouslySyncedProofPaths = Registration::query()
            ->whereIn('proof_of_payment_path', $proofPaths)
            ->whereNotNull('google_sheets_synced_at')
            ->pluck('proof_of_payment_path')
            ->flip()
            ->all();
        $includedProofPaths = [];

        $rows = $registrations->map(function (Registration $registration) use (
            &$includedProofPaths,
            $previouslySyncedProofPaths,
        ): array {
            $values = [
                $registration->district,
                $registration->surname,
                $registration->given_name,
                $registration->middle_initial ?? '',
                $registration->nickname ?? '',
                (string) $registration->age,
                $registration->gender,
                $registration->shirt_size,
                $registration->division,
                $registration->ministry,
                $registration->email,
                basename($registration->proof_of_payment_path),
                $registration->created_at
                    ->copy()
                    ->timezone(config('registrations.display_timezone'))
                    ->format('F j, Y g:i A'),
                $registration->birthdate?->format('F j, Y') ?? '',
            ];
            $proofPath = $registration->proof_of_payment_path;
            if (isset($previouslySyncedProofPaths[$proofPath]) || isset($includedProofPaths[$proofPath])) {
                $values[11] = ['stringValue' => ''];
            } else {
                $includedProofPaths[$proofPath] = true;
                $values[11] = $this->proofCellValue($registration);
            }

            return [
                'values' => array_map(
                    static fn (string|array $value): array => [
                        'userEnteredValue' => is_array($value)
                            ? $value
                            : ['stringValue' => $value],
                    ],
                    $values,
                ),
            ];
        })->all();

        Http::acceptJson()->withToken($accessToken)
            ->post(self::API_BASE_URL.'/spreadsheets/'.rawurlencode($spreadsheetId).':batchUpdate', [
                'requests' => [[
                    'appendCells' => [
                        'sheetId' => $sheetId,
                        'rows' => $rows,
                        'fields' => 'userEnteredValue',
                    ],
                ]],
            ])
            ->throw();
    }

    private function proofCellValue(Registration $registration): array
    {
        $proofPath = $registration->proof_of_payment_path;
        $proofExtension = strtolower(pathinfo($proofPath, PATHINFO_EXTENSION));

        if (! in_array($proofExtension, ['jpg', 'jpeg', 'png'], true)) {
            return ['stringValue' => basename($proofPath)];
        }

        $imageUrl = rtrim((string) config('app.url'), '/').URL::signedRoute(
            'registrations.proof-image',
            ['registration' => $registration],
            absolute: false,
        );

        $linkText = 'See proof of payment ('.str_replace('"', '""', $registration->district).')';

        return [
            'formulaValue' => '=HYPERLINK("'.str_replace('"', '""', $imageUrl).'","'.$linkText.'")',
        ];
    }

    private function valuesUrl(string $spreadsheetId, string $range): string
    {
        return self::API_BASE_URL.'/spreadsheets/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode($range);
    }

    private function quotedRange(string $sheetTitle, string $cells): string
    {
        return "'".str_replace("'", "''", $sheetTitle)."'!".$cells;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
