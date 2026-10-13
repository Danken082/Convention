<?php

namespace App\Http\Controllers;

use App\Models\Registration;
use App\Services\GoogleSheetsAppender;
use App\Services\GoogleSheetsException;
use App\Services\GoogleSheetsOAuth;
use App\Services\RegistrationWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    public function login(): View
    {
        return view('admin.login');
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $accessKey = config('registrations.admin_export_key');
        abort_unless(is_string($accessKey) && $accessKey !== '', 503, 'Admin access is not configured.');

        $validated = $request->validate([
            'access_key' => ['required', 'string', 'max:255'],
        ]);

        if (! hash_equals($accessKey, $validated['access_key'])) {
            return back()->withErrors(['access_key' => 'The access key is incorrect.']);
        }

        $request->session()->regenerate();
        $request->session()->put('admin_authenticated', true);

        return redirect()->route('admin.index');
    }

    public function index(): View
    {
        return view('admin.index', [
            'registrations' => Registration::query()->latest()->paginate(25),
            'pendingSheetSyncCount' => Registration::query()->whereNull('google_sheets_synced_at')->count(),
            'googleSheetsOAuthConfigured' => app(GoogleSheetsOAuth::class)->isOAuthClientConfigured(),
            'googleSheetsConnected' => app(GoogleSheetsOAuth::class)->isConnected(),
            'googleSheetsRedirectUri' => config('services.google_sheets.redirect_uri'),
        ]);
    }

    public function connectGoogleSheets(Request $request, GoogleSheetsOAuth $googleSheetsOAuth): RedirectResponse
    {
        $state = Str::random(48);
        $redirectUri = config('services.google_sheets.redirect_uri');
        abort_unless(is_string($redirectUri) && $redirectUri !== '', 503, 'Google Sheets callback URL is not configured.');
        $request->session()->put('google_sheets_oauth_state', $state);
        $request->session()->put('google_sheets_oauth_redirect_uri', $redirectUri);

        try {
            return redirect()->away($googleSheetsOAuth->authorizationUrl($state, $redirectUri));
        } catch (GoogleSheetsException $exception) {
            $request->session()->forget(['google_sheets_oauth_state', 'google_sheets_oauth_redirect_uri']);

            return back()->with('syncError', $exception->getMessage());
        }
    }

    public function googleSheetsCallback(Request $request, GoogleSheetsOAuth $googleSheetsOAuth): RedirectResponse
    {
        if ($request->filled('error')) {
            $request->session()->forget(['google_sheets_oauth_state', 'google_sheets_oauth_redirect_uri']);

            return redirect()->route('admin.index')->with(
                'syncError',
                'Google Sheets authorization was not completed: '.$request->string('error'),
            );
        }

        $expectedState = $request->session()->pull('google_sheets_oauth_state');
        $redirectUri = $request->session()->pull('google_sheets_oauth_redirect_uri');
        $receivedState = $request->query('state');

        if (! is_string($expectedState) || ! is_string($receivedState)
            || ! hash_equals($expectedState, $receivedState)
            || ! is_string($redirectUri)) {
            return redirect()->route('admin.index')->with('syncError', 'Google Sheets authorization state did not match. Try connecting again.');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return redirect()->route('admin.index')->with('syncError', 'Google did not return an authorization code.');
        }

        try {
            $googleSheetsOAuth->connect($code, $redirectUri);
        } catch (GoogleSheetsException $exception) {
            Log::error('Google Sheets OAuth authorization failed.', [
                'exception' => $exception->getMessage(),
            ]);

            return redirect()->route('admin.index')->with('syncError', $exception->getMessage());
        }

        return redirect()->route('admin.index')->with('syncSuccess', 'Google Sheets connected. You can sync pending registrations now.');
    }

    public function syncGoogleSheets(): RedirectResponse
    {
        $pendingQuery = Registration::query()->whereNull('google_sheets_synced_at');

        if (! $pendingQuery->exists()) {
            return back()->with('syncSuccess', 'All registrations are already synced to Google Sheets.');
        }

        try {
            $pendingQuery->chunkById(50, function ($registrations): void {
                app(GoogleSheetsAppender::class)->appendRegistrations($registrations);
                Registration::query()
                    ->whereIn('id', $registrations->modelKeys())
                    ->whereNull('google_sheets_synced_at')
                    ->update(['google_sheets_synced_at' => now()]);
            });
        } catch (GoogleSheetsException $exception) {
            Log::error('Pending convention registrations could not be synced to Google Sheets.', [
                'exception' => $exception->getMessage(),
            ]);

            return back()->with('syncError', 'Google Sheets sync failed: '.$exception->getMessage());
        }

        return back()->with('syncSuccess', 'All pending registrations were added to Google Sheets.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('admin_authenticated');
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function proof(Registration $registration): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($registration->proof_of_payment_path), 404);

        return Storage::disk('local')->download($registration->proof_of_payment_path);
    }

    public function export(): StreamedResponse
    {
        $spreadsheet = app(RegistrationWorkbook::class)->create(
            Registration::query()->oldest()->cursor(),
        );
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            static fn () => $writer->save('php://output'),
            'convention-registrations.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
