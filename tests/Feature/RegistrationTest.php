<?php

namespace Tests\Feature;

use App\Mail\RegistrationReceived;
use App\Models\AllowedEmail;
use App\Models\Registration;
use App\Services\GoogleSheetsOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['registrations.admin_email' => 'organizer@example.com']);
        config(['registrations.allowed_emails' => ['alex@example.com', 'sam@example.com']]);
        config(['services.google_sheets.spreadsheet_id' => 'test-spreadsheet-id']);
        $this->withSession(['registration_verified_email' => 'alex@example.com']);
    }

    public function test_registration_is_saved_and_email_is_sent_to_submitter_and_admin(): void
    {
        Mail::fake();

        $response = $this->post(route('registrations.store'), $this->registrationData());

        $response->assertRedirect(route('registrations.create'));
        $response->assertSessionHas('success', '1 attendee registration(s) saved.');
        $this->get(route('registrations.create'))
            ->assertOk()
            ->assertSee('id="registration-success-dialog"', false)
            ->assertSee('Please check your email to see your registration details. Thank you!')
            ->assertSee('src="'.asset('images/unstoppable-2027-banner.png').'"', false);
        $this->assertDatabaseHas('registrations', [
            'district' => 'CD',
            'surname' => 'Santos',
            'given_name' => 'Alex',
            'email' => 'alex@example.com',
        ]);

        $registration = Registration::firstOrFail();
        $this->assertSame('2000-01-15', $registration->birthdate->format('Y-m-d'));
        Storage::disk('local')->assertExists($registration->proof_of_payment_path);
        Mail::assertSent(
            RegistrationReceived::class,
            fn (RegistrationReceived $mail) => $mail->hasTo('alex@example.com')
                && ! $mail->includeProof
                && str_contains($mail->render(), 'Birthdate')
                && str_contains($mail->render(), 'January 15, 2000')
                && str_contains($mail->render(), 'Submitted:')
                && str_contains($mail->render(), 'Philippine Time')
                && str_contains($mail->render(), asset('images/unstoppable-2027-banner.png'))
                && str_contains($mail->render(), 'FOURSQUARE GLOBAL CONFERENCE FOR YOUNG ADULTS 2027!')
                && str_contains($mail->render(), 'https://www.facebook.com/FoursquareYouthPhilippines'),
        );
        Mail::assertSent(
            RegistrationReceived::class,
            fn (RegistrationReceived $mail) => $mail->hasTo('organizer@example.com')
                && $mail->includeProof
                && str_contains($mail->render(), 'https://docs.google.com/spreadsheets/d/test-spreadsheet-id/edit'),
        );
    }

    public function test_invalid_district_is_rejected_and_not_saved(): void
    {
        $data = $this->registrationData();
        $data['district'] = 'NOT A DISTRICT';

        $this->post(route('registrations.store'), $data)
            ->assertSessionHasErrors('district');

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_birthdate_is_required(): void
    {
        $data = $this->registrationData();
        unset($data['attendees'][0]['birthdate']);

        $this->post(route('registrations.store'), $data)
            ->assertSessionHasErrors('attendees.0.birthdate');

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_birthdate_must_be_a_valid_date_not_in_the_future(): void
    {
        $data = $this->registrationData();
        $data['attendees'][0]['birthdate'] = 'not-a-date';

        $this->post(route('registrations.store'), $data)
            ->assertSessionHasErrors('attendees.0.birthdate');

        $data['attendees'][0]['birthdate'] = now()->addDay()->toDateString();
        $this->post(route('registrations.store'), $data)
            ->assertSessionHasErrors('attendees.0.birthdate');

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_attendees_under_16_cannot_register(): void
    {
        $data = $this->registrationData();
        $data['attendees'][0]['birthdate'] = '2011-07-23';

        $this->post(route('registrations.store'), $data)
            ->assertSessionHasErrors([
                'attendees.0.birthdate' => 'Attendees born on or after July 23, 2011 cannot register.',
            ]);

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_birthdate_before_july_23_2011_can_register_at_age_16(): void
    {
        Mail::fake();
        $data = $this->registrationData();
        $data['attendees'][0]['birthdate'] = '2011-07-22';
        $data['attendees'][0]['age'] = '16';

        $this->post(route('registrations.store'), $data)
            ->assertRedirect(route('registrations.create'))
            ->assertSessionHas('success', '1 attendee registration(s) saved.');

        $this->assertDatabaseCount('registrations', 1);
    }

    public function test_attendee_age_must_be_at_least_16(): void
    {
        $data = $this->registrationData();
        $data['attendees'][0]['age'] = '15';

        $this->post(route('registrations.store'), $data)
            ->assertSessionHasErrors([
                'attendees.0.age' => 'Each attendee must be at least 16 years old to register.',
            ]);

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_attendee_can_submit_a_custom_shirt_size(): void
    {
        Mail::fake();
        $data = $this->registrationData();
        $data['attendees'][0]['shirt_size'] = 'Other';
        $data['attendees'][0]['shirt_size_other'] = 'Youth Large';

        $this->post(route('registrations.store'), $data)
            ->assertRedirect(route('registrations.create'));

        $this->assertDatabaseHas('registrations', ['shirt_size' => 'Youth Large']);
    }

    public function test_other_shirt_size_requires_a_custom_value(): void
    {
        $data = $this->registrationData();
        $data['attendees'][0]['shirt_size'] = 'Other';
        $data['attendees'][0]['shirt_size_other'] = '';

        $this->post(route('registrations.store'), $data)
            ->assertSessionHasErrors('attendees.0.shirt_size_other');

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_attendee_can_register_on_their_16th_birthday(): void
    {
        Mail::fake();
        $data = $this->registrationData();
        $data['attendees'][0]['birthdate'] = now()->subYears(16)->toDateString();
        $data['attendees'][0]['age'] = '16';

        $this->post(route('registrations.store'), $data)
            ->assertRedirect(route('registrations.create'))
            ->assertSessionHas('success', '1 attendee registration(s) saved.');

        $this->assertDatabaseCount('registrations', 1);
    }

    public function test_each_submission_creates_a_separate_registration(): void
    {
        Mail::fake();

        $first = $this->registrationData();
        $second = $this->registrationData();
        $second['attendees'][0]['surname'] = 'Reyes';
        $second['email'] = 'sam@example.com';

        $this->post(route('registrations.store'), $first)->assertRedirect(route('registrations.create'));
        $this->withSession(['registration_verified_email' => 'sam@example.com']);
        $this->post(route('registrations.store'), $second)->assertRedirect(route('registrations.create'));

        $this->assertDatabaseCount('registrations', 2);
        $this->assertDatabaseHas('registrations', ['surname' => 'Reyes', 'email' => 'sam@example.com']);
    }

    public function test_multiple_attendee_forms_are_saved_as_separate_registrations(): void
    {
        Mail::fake();

        $data = $this->registrationData();
        $second = $data['attendees'][0];
        $second['surname'] = 'Reyes';
        $second['given_name'] = 'Sam';
        $data['attendees'][] = $second;

        $this->post(route('registrations.store'), $data)
            ->assertRedirect(route('registrations.create'))
            ->assertSessionHas('success', '2 attendee registration(s) saved.');

        $this->assertDatabaseCount('registrations', 2);
        $this->assertDatabaseHas('registrations', [
            'surname' => 'Santos',
            'email' => 'alex@example.com',
        ]);
        $this->assertDatabaseHas('registrations', [
            'surname' => 'Reyes',
            'email' => 'alex@example.com',
            'district' => 'CD',
        ]);
        $this->assertSame(
            ['CD'],
            Registration::query()->distinct()->pluck('district')->all(),
        );
        $this->assertCount(1, Registration::query()->distinct()->pluck('proof_of_payment_path')->all());
        $this->assertSame(
            Registration::firstOrFail()->proof_of_payment_path,
            Registration::latest('id')->firstOrFail()->proof_of_payment_path,
        );
        $this->assertNull(Registration::firstOrFail()->google_sheets_synced_at);
        Mail::assertSent(RegistrationReceived::class, 2);

        $submitterMail = null;
        Mail::assertSent(
            RegistrationReceived::class,
            function (RegistrationReceived $mail) use (&$submitterMail): bool {
                if (! $mail->hasTo('alex@example.com')) {
                    return false;
                }

                $submitterMail = $mail;

                return true;
            },
        );
        $this->assertNotNull($submitterMail);
        $this->assertCount(2, $submitterMail->registrations);
        $this->assertCount(1, $submitterMail->attachments());
        $this->assertSame(
            basename(Registration::firstOrFail()->proof_of_payment_path),
            $submitterMail->attachments()[0]->as,
        );

        Mail::assertSent(
            RegistrationReceived::class,
            function (RegistrationReceived $mail): bool {
                return $mail->hasTo('organizer@example.com')
                    && $mail->includeProof
                    && count($mail->registrations) === 2;
            },
        );

    }

    public function test_batch_is_appended_to_the_configured_google_sheet(): void
    {
        Mail::fake();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/*:batchUpdate' => Http::response([], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/*/values/*' => Http::response([
                'values' => [['District']],
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
                'sheets' => [[
                    'properties' => ['sheetId' => 0, 'title' => 'Attendee List'],
                ]],
            ]),
        ]);

        $credentialsPath = $this->createGoogleCredentialsFile();
        Cache::put(
            'google_sheets_token_'.hash('sha256', 'convention-test@example.iam.gserviceaccount.com'),
            'test-access-token',
            now()->addMinutes(10),
        );
        config([
            'services.google_sheets.spreadsheet_id' => 'test-spreadsheet-id',
            'services.google_sheets.tab_id' => '0',
            'services.google_sheets.credentials_path' => $credentialsPath,
        ]);

        try {
            $data = $this->registrationData();
            $pngContents = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/vGkAAAAASUVORK5CYII=',
                true,
            );
            $this->assertNotFalse($pngContents);
            $data['proof_of_payment'] = UploadedFile::fake()->createWithContent('payment-proof.png', $pngContents);
            $data['attendees'][0]['surname'] = '=1+1';
            $second = $data['attendees'][0];
            $second['surname'] = 'Reyes';
            $data['attendees'][] = $second;

            $this->post(route('registrations.store'), $data)
                ->assertRedirect(route('registrations.create'))
                ->assertSessionHas('success', '2 attendee registration(s) saved.')
                ->assertSessionMissing('emailWarning');

            $this->assertNotNull(Registration::firstOrFail()->google_sheets_synced_at);
            $recordedRequests = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
            $appendRequests = Http::recorded()
                ->filter(fn ($pair) => $pair[0]->method() === 'POST')
                ->values();

            $this->assertCount(1, $appendRequests, json_encode($recordedRequests));
            $this->assertStringContainsString(':batchUpdate', $appendRequests->first()[0]->url());
            $sheetUpdate = $appendRequests->first()[0]->data()['requests'][0]['appendCells'];
            $this->assertSame(0, $sheetUpdate['sheetId']);
            $this->assertSame('userEnteredValue', $sheetUpdate['fields']);
            $rows = $sheetUpdate['rows'];
            $birthdateHeaderRequests = Http::recorded()->filter(
                fn ($pair) => $pair[0]->method() === 'PUT'
                    && $pair[0]->data() === ['values' => [['Birthdate']]],
            );
            $this->assertCount(2, $rows);
            $this->assertSame('=1+1', $rows[0]['values'][1]['userEnteredValue']['stringValue']);
            $proofFormula = $rows[0]['values'][11]['userEnteredValue']['formulaValue'];
            $this->assertStringStartsWith(
                '=HYPERLINK("'.rtrim((string) config('app.url'), '/').'/registration/',
                $proofFormula,
            );
            $this->assertStringEndsWith(
                '","See proof of payment (CD)")',
                $proofFormula,
            );
            $this->assertSame('', $rows[1]['values'][11]['userEnteredValue']['stringValue']);
            $this->assertSame('January 15, 2000', $rows[0]['values'][13]['userEnteredValue']['stringValue']);
            $this->assertMatchesRegularExpression(
                '/^[A-Z][a-z]+ \d{1,2}, \d{4} \d{1,2}:\d{2} [AP]M$/',
                $rows[0]['values'][12]['userEnteredValue']['stringValue'],
            );
            $this->assertSame('alex@example.com', $rows[0]['values'][10]['userEnteredValue']['stringValue']);
            $this->assertCount(1, $birthdateHeaderRequests);
            Http::assertSent(fn ($request) => $request->hasHeader('Accept', 'application/json'));
        } finally {
            unlink($credentialsPath);
        }
    }

    public function test_missing_google_credentials_are_reported_and_registration_is_preserved(): void
    {
        Mail::fake();
        config([
            'services.google_sheets.spreadsheet_id' => 'test-spreadsheet-id',
            'services.google_sheets.credentials_path' => null,
        ]);

        $this->post(route('registrations.store'), $this->registrationData())
            ->assertRedirect(route('registrations.create'))
            ->assertSessionHas('success', '1 attendee registration(s) saved.')
            ->assertSessionHas(
                'emailWarning',
                'The registrations were saved, but some notifications could not be completed: Google Sheets sync: Google Sheets is not configured.',
            );

        $this->assertDatabaseCount('registrations', 1);
    }

    public function test_google_sheets_api_failure_reports_the_google_error_and_preserves_registration(): void
    {
        Mail::fake();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
                'error' => ['message' => 'The caller does not have permission'],
            ], 403),
        ]);

        $credentialsPath = $this->createGoogleCredentialsFile();
        Cache::put(
            'google_sheets_token_'.hash('sha256', 'convention-test@example.iam.gserviceaccount.com'),
            'test-access-token',
            now()->addMinutes(10),
        );
        config([
            'services.google_sheets.credentials_path' => $credentialsPath,
        ]);

        try {
            $this->post(route('registrations.store'), $this->registrationData())
                ->assertRedirect(route('registrations.create'))
                ->assertSessionHas(
                    'emailWarning',
                    'The registrations were saved, but some notifications could not be completed: Google Sheets sync: Google Sheets API returned HTTP 403: The caller does not have permission.',
                );

            $this->assertDatabaseCount('registrations', 1);
        } finally {
            unlink($credentialsPath);
        }
    }

    public function test_oauth_web_client_requires_admin_authorization_before_sync(): void
    {
        Mail::fake();
        $credentialsPath = $this->createOAuthClientFile();
        config(['services.google_sheets.credentials_path' => $credentialsPath]);

        try {
            $this->post(route('registrations.store'), $this->registrationData())
                ->assertRedirect(route('registrations.create'))
                ->assertSessionHas(
                    'emailWarning',
                    'The registrations were saved, but some notifications could not be completed: Google Sheets sync: Google Sheets is not connected. Sign in to the admin dashboard and choose Connect Google Sheets.',
                );

            $this->assertDatabaseCount('registrations', 1);
        } finally {
            unlink($credentialsPath);
        }
    }

    public function test_admin_can_sync_previously_saved_registrations(): void
    {
        $registrationData = $this->registrationData()['attendees'][0];
        Registration::create([
            ...$registrationData,
            'district' => 'CD',
            'email' => 'alex@example.com',
            'proof_of_payment_path' => 'proof-of-payment/payment-proof.pdf',
        ]);

        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/*/values/*' => Http::response([
                'values' => [['District']],
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
                'sheets' => [[
                    'properties' => ['sheetId' => 0, 'title' => 'Attendee List'],
                ]],
            ]),
        ]);

        $credentialsPath = $this->createGoogleCredentialsFile();
        Cache::put(
            'google_sheets_token_'.hash('sha256', 'convention-test@example.iam.gserviceaccount.com'),
            'test-access-token',
            now()->addMinutes(10),
        );
        config([
            'services.google_sheets.spreadsheet_id' => 'test-spreadsheet-id',
            'services.google_sheets.tab_id' => '0',
            'services.google_sheets.credentials_path' => $credentialsPath,
        ]);

        try {
            $this->withSession(['admin_authenticated' => true])
                ->post(route('admin.sync'))
                ->assertRedirect()
                ->assertSessionHas('syncSuccess', 'All pending registrations were added to Google Sheets.');

            $this->assertNotNull(Registration::firstOrFail()->fresh()->google_sheets_synced_at);
            $this->assertCount(1, Http::recorded()->filter(
                fn ($pair) => $pair[0]->method() === 'POST',
            ));
        } finally {
            unlink($credentialsPath);
        }
    }

    public function test_admin_sync_adds_a_shared_payment_proof_only_once_across_batches(): void
    {
        $registrationData = $this->registrationData()['attendees'][0];
        foreach (range(1, 51) as $attendeeNumber) {
            Registration::create([
                ...$registrationData,
                'surname' => 'Attendee '.$attendeeNumber,
                'district' => 'CD',
                'email' => 'alex@example.com',
                'proof_of_payment_path' => 'proof-of-payment/shared-payment-proof.pdf',
            ]);
        }

        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/*:batchUpdate' => Http::response([], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/*/values/*' => Http::response([
                'values' => [['District']],
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
                'sheets' => [[
                    'properties' => ['sheetId' => 0, 'title' => 'Attendee List'],
                ]],
            ]),
        ]);

        $credentialsPath = $this->createGoogleCredentialsFile();
        Cache::put(
            'google_sheets_token_'.hash('sha256', 'convention-test@example.iam.gserviceaccount.com'),
            'test-access-token',
            now()->addMinutes(10),
        );
        config([
            'services.google_sheets.spreadsheet_id' => 'test-spreadsheet-id',
            'services.google_sheets.tab_id' => '0',
            'services.google_sheets.credentials_path' => $credentialsPath,
        ]);

        try {
            $this->withSession(['admin_authenticated' => true])
                ->post(route('admin.sync'))
                ->assertRedirect()
                ->assertSessionHas('syncSuccess', 'All pending registrations were added to Google Sheets.');

            $appendRequests = Http::recorded()->filter(
                fn ($pair) => $pair[0]->method() === 'POST'
                    && str_contains($pair[0]->url(), ':batchUpdate'),
            );
            $this->assertCount(2, $appendRequests);
            $rows = $appendRequests->values()->flatMap(
                fn ($pair) => $pair[0]->data()['requests'][0]['appendCells']['rows'],
            );
            $proofCells = $rows->map(
                fn (array $row) => $row['values'][11]['userEnteredValue']['stringValue'],
            );
            $this->assertSame(1, $proofCells->filter(
                fn (string $proofCell) => $proofCell !== '',
            )->count());
            $this->assertSame('shared-payment-proof.pdf', $proofCells->first());
        } finally {
            unlink($credentialsPath);
        }
    }

    public function test_admin_sync_failure_keeps_registration_pending(): void
    {
        $registrationData = $this->registrationData()['attendees'][0];
        Registration::create([
            ...$registrationData,
            'district' => 'CD',
            'email' => 'alex@example.com',
            'proof_of_payment_path' => 'proof-of-payment/payment-proof.pdf',
        ]);
        config(['services.google_sheets.credentials_path' => null]);

        $this->withSession(['admin_authenticated' => true])
            ->post(route('admin.sync'))
            ->assertRedirect()
            ->assertSessionHas('syncError', 'Google Sheets sync failed: Google Sheets is not configured.');

        $this->assertNull(Registration::firstOrFail()->fresh()->google_sheets_synced_at);
    }

    public function test_admin_can_connect_google_sheets_using_oauth_client(): void
    {
        config([
            'services.google_sheets.redirect_uri' => 'http://127.0.0.1:8000/admin/google-sheets/callback',
        ]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'oauth-access-token',
                'refresh_token' => 'oauth-refresh-token',
                'expires_in' => 3600,
            ]),
        ]);
        $credentialsPath = $this->createOAuthClientFile();
        config(['services.google_sheets.credentials_path' => $credentialsPath]);

        try {
            $connectResponse = $this->withSession(['admin_authenticated' => true])
                ->get(route('admin.google-sheets.connect'))
                ->assertRedirect();

            $state = session('google_sheets_oauth_state');
            $this->assertIsString($state);
            parse_str((string) parse_url((string) $connectResponse->headers->get('Location'), PHP_URL_QUERY), $authorizationQuery);
            $this->assertSame(
                'http://127.0.0.1:8000/admin/google-sheets/callback',
                $authorizationQuery['redirect_uri'] ?? null,
            );
            $response = $this->get(route('admin.google-sheets.callback', [
                'code' => 'test-authorization-code',
                'state' => $state,
            ]));

            $response->assertRedirect(route('admin.index'))
                ->assertSessionHas('syncSuccess');

            $this->assertTrue(app(GoogleSheetsOAuth::class)->isConnected());
            Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'test-authorization-code'
                && $request['redirect_uri'] === 'http://127.0.0.1:8000/admin/google-sheets/callback');
        } finally {
            unlink($credentialsPath);
        }
    }

    public function test_admin_dashboard_offers_google_connect_for_the_configured_oauth_client(): void
    {
        config([
            'services.google_sheets.redirect_uri' => 'http://127.0.0.1:8000/admin/google-sheets/callback',
        ]);
        $credentialsPath = $this->createOAuthClientFile();
        config(['services.google_sheets.credentials_path' => $credentialsPath]);

        try {
            $this->withSession(['admin_authenticated' => true])
                ->get(route('admin.index'))
                ->assertOk()
                ->assertSee('Connect Google Sheets')
                ->assertSee(route('admin.google-sheets.connect'))
                ->assertSee('http://127.0.0.1:8000/admin/google-sheets/callback');
        } finally {
            unlink($credentialsPath);
        }
    }

    public function test_admin_dashboard_displays_registration_birthdate(): void
    {
        Registration::create([
            ...$this->registrationData()['attendees'][0],
            'district' => 'CD',
            'email' => 'alex@example.com',
            'proof_of_payment_path' => 'proof-of-payment/payment-proof.pdf',
        ]);

        $this->withSession(['admin_authenticated' => true])
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('Birthdate')
            ->assertSee('January 15, 2000')
            ->assertSee('Philippine Time');
    }

    public function test_oauth_client_appends_registration_to_google_sheet_after_authorization(): void
    {
        Mail::fake();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'oauth-access-token',
                'expires_in' => 3600,
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/*:batchUpdate' => Http::response([], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/*/values/*' => Http::response([
                'values' => [['District']],
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
                'sheets' => [[
                    'properties' => ['sheetId' => 0, 'title' => 'Attendee List'],
                ]],
            ]),
        ]);

        $credentialsPath = $this->createOAuthClientFile();
        config([
            'services.google_sheets.credentials_path' => $credentialsPath,
            'services.google_sheets.spreadsheet_id' => 'test-spreadsheet-id',
            'services.google_sheets.tab_id' => '0',
        ]);
        Storage::disk('local')->put('google-sheets/oauth-token', Crypt::encryptString(json_encode([
            'client_id' => 'test-oauth-client-id',
            'refresh_token' => 'test-refresh-token',
        ], JSON_THROW_ON_ERROR)));

        try {
            $this->post(route('registrations.store'), $this->registrationData())
                ->assertRedirect(route('registrations.create'))
                ->assertSessionHas('success', '1 attendee registration(s) saved.')
                ->assertSessionMissing('emailWarning');

            $this->assertNotNull(Registration::firstOrFail()->fresh()->google_sheets_synced_at);
            $appendRequests = Http::recorded()->filter(
                fn ($pair) => $pair[0]->method() === 'POST'
                    && str_contains($pair[0]->url(), ':batchUpdate'),
            );
            $this->assertCount(1, $appendRequests);
            $proofCell = $appendRequests->first()[0]->data()['requests'][0]['appendCells']['rows'][0]['values'][11]['userEnteredValue'];
            $this->assertStringEndsWith('.pdf', $proofCell['stringValue']);
        } finally {
            unlink($credentialsPath);
        }
    }

    public function test_registration_form_is_available(): void
    {
        $response = $this->get(route('registrations.create'))
            ->assertOk()
            ->assertSee('Section 1 of 2')
            ->assertSee('Email verification')
            ->assertSee('Continue to Section 2')
            ->assertDontSee('id="registration-form"', false);

        $html = $response->getContent();
        $this->assertStringNotContainsString('name="district"', $html);

        $detailsResponse = $this->get(route('registrations.details'))
            ->assertOk()
            ->assertSee('Section 2 of 2')
            ->assertSee('Submission details')
            ->assertSee('<h3 class="section-title">Attendee</h3>', false)
            ->assertDontSee('Attendee 1')
            ->assertSee('id="attendee-pagination"', false)
            ->assertSee('aria-label="Delegate pages"', false)
            ->assertSee('novalidate', false)
            ->assertSee('<div class="registration-action-group">', false)
            ->assertSee('Add another Delegate')
            ->assertSee('>Back</a>', false)
            ->assertSee('Submit registrations')
            ->assertSee('Review your registration')
            ->assertSee('id="registration-confirmation"', false)
            ->assertSee('Confirm and submit')
            ->assertSee('Go back and edit')
            ->assertSee('id="registration-confirmation-summary"', false)
            ->assertSee('id="proof-preview-dialog"', false)
            ->assertSee('id="proof-preview-image"', false)
            ->assertSee('Submission details')
            ->assertSee('Email')
            ->assertSee('District')
            ->assertSee('Proof of payment')
            ->assertSee('NORSD');

        $detailsHtml = $detailsResponse->getContent();
        $this->assertSame(1, substr_count($detailsHtml, 'name="district"'));
        $this->assertSame(1, substr_count($detailsHtml, 'name="proof_of_payment"'));
        $this->assertStringNotContainsString('attendees[0][district]', $detailsHtml);
        $this->assertStringNotContainsString('attendees[0][proof_of_payment]', $detailsHtml);
        $this->assertStringNotContainsString('attendees[__INDEX__][district]', $detailsHtml);
        $this->assertStringNotContainsString('attendees[__INDEX__][proof_of_payment]', $detailsHtml);
    }

    public function test_registration_details_are_hidden_until_an_approved_email_is_entered(): void
    {
        $response = $this->withSession(['registration_verified_email' => null])
            ->get(route('registrations.create'))
            ->assertOk()
            ->assertSee('Enter your approved email to continue')
            ->assertSee('Verify email and continue')
            ->assertDontSee('id="registration-form"', false);

        $response->assertSee('id="email-verification-form"', false);
        $this->get(route('registrations.details'))
            ->assertRedirect(route('registrations.create'));
    }

    public function test_approved_email_unlocks_registration_details(): void
    {
        $this->withSession(['registration_verified_email' => null])
            ->post(route('registrations.verify-email'), ['email' => ' ALEX@EXAMPLE.COM '])
            ->assertRedirect(route('registrations.details'))
            ->assertSessionHas('registration_verified_email', 'alex@example.com');

        $this->get(route('registrations.details'))
            ->assertOk()
            ->assertSee('id="registration-form"', false)
            ->assertSee('name="attendees[0][birthdate]"', false)
            ->assertSee('type="date"', false)
            ->assertSee('Email:')
            ->assertSee('alex@example.com');
    }

    public function test_email_not_in_the_env_allowlist_cannot_continue(): void
    {
        $this->post(route('registrations.verify-email'), ['email' => 'unapproved@example.com'])
            ->assertRedirect(route('registrations.create'))
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('registration_verified_email');

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_email_must_have_valid_syntax_even_if_listed(): void
    {
        config(['registrations.allowed_emails' => ['not-an-email']]);

        $this->withSession(['registration_verified_email' => null])
            ->post(route('registrations.verify-email'), ['email' => 'not-an-email'])
            ->assertRedirect(route('registrations.create'))
            ->assertSessionHasErrors('email');
    }

    public function test_registration_stays_closed_when_the_email_allowlist_is_empty(): void
    {
        config(['registrations.allowed_emails' => []]);

        $this->withSession(['registration_verified_email' => null])
            ->get(route('registrations.create'))
            ->assertOk()
            ->assertSee('Registration is not available until approved email addresses are configured.')
            ->assertSee('Verify email and continue')
            ->assertSee('disabled', false);

        $this->post(route('registrations.verify-email'), ['email' => 'alex@example.com'])
            ->assertRedirect(route('registrations.create'))
            ->assertSessionHasErrors('email');
    }

    public function test_registration_submission_requires_the_verified_session_email(): void
    {
        $this->withSession(['registration_verified_email' => null])
            ->post(route('registrations.store'), $this->registrationData())
            ->assertRedirect(route('registrations.create'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_registration_draft_is_restored_after_refreshing_details_page(): void
    {
        $this->postJson(route('registrations.save-draft'), [
            'district' => 'CD',
            'attendees' => [
                ['surname' => 'Draft Santos', 'given_name' => 'Alex', 'birthdate' => '2000-01-15'],
                ['surname' => 'Draft Reyes'],
            ],
        ])
            ->assertOk()
            ->assertJson(['saved' => true]);

        $this->get(route('registrations.details'))
            ->assertOk()
            ->assertSee('value="Draft Santos"', false)
            ->assertSee('value="Draft Reyes"', false)
            ->assertSee('value="Alex"', false)
            ->assertSee('value="2000-01-15"', false)
            ->assertSee('<option value="CD" selected>', false);
    }

    public function test_registration_draft_cannot_be_saved_without_a_verified_email(): void
    {
        $this->withSession(['registration_verified_email' => null])
            ->postJson(route('registrations.save-draft'), [
                'attendees' => [['surname' => 'Draft Santos']],
            ])
            ->assertForbidden();

        $this->assertFalse(session()->has('registration_draft'));
    }

    public function test_admin_export_requires_a_signed_in_admin(): void
    {
        $this->get(route('admin.export'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_download_an_excel_workbook(): void
    {
        $registrationData = $this->registrationData()['attendees'][0];
        $registrationData['email'] = 'alex@example.com';

        Registration::create([
            ...$registrationData,
            'district' => 'CD',
            'proof_of_payment_path' => 'proof-of-payment/payment-proof.pdf',
        ]);

        $response = $this->withSession(['admin_authenticated' => true])
            ->get(route('admin.export'));

        $response->assertOk();
        $response->assertDownload('convention-registrations.xlsx');
        $file = tempnam(sys_get_temp_dir(), 'convention-export-');
        $this->assertNotFalse($file);

        try {
            file_put_contents($file, $response->streamedContent());
            $spreadsheet = IOFactory::load($file);
            $this->assertSame('District', $spreadsheet->getActiveSheet()->getCell('A1')->getValue());
            $this->assertSame('28', $spreadsheet->getActiveSheet()->getCell('F2')->getValue());
            $this->assertSame('alex@example.com', $spreadsheet->getActiveSheet()->getCell('K2')->getValue());
            $this->assertSame('January 15, 2000', $spreadsheet->getActiveSheet()->getCell('N2')->getValue());
            $this->assertMatchesRegularExpression(
                '/^[A-Z][a-z]+ \d{1,2}, \d{4} \d{1,2}:\d{2} [AP]M$/',
                $spreadsheet->getActiveSheet()->getCell('M2')->getValue(),
            );
        } finally {
            unlink($file);
        }
    }

    public function test_admin_excel_export_embeds_uploaded_payment_images(): void
    {
        $proofPath = 'proof-of-payment/payment-proof.png';
        $imageContents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/vGkAAAAASUVORK5CYII=',
            true,
        );
        $this->assertNotFalse($imageContents);
        Storage::disk('local')->put($proofPath, $imageContents);

        Registration::create([
            ...$this->registrationData()['attendees'][0],
            'district' => 'CD',
            'email' => 'alex@example.com',
            'proof_of_payment_path' => $proofPath,
        ]);

        $response = $this->withSession(['admin_authenticated' => true])
            ->get(route('admin.export'))
            ->assertOk()
            ->assertDownload('convention-registrations.xlsx');

        $file = tempnam(sys_get_temp_dir(), 'convention-image-export-');
        $this->assertNotFalse($file);

        try {
            file_put_contents($file, $response->streamedContent());
            $spreadsheet = IOFactory::load($file);
            $drawings = $spreadsheet->getActiveSheet()->getDrawingCollection();

            $this->assertCount(1, $drawings);
            $this->assertSame('L2', $drawings[0]->getCoordinates());
            $this->assertGreaterThan(0, $drawings[0]->getWidth());
            $this->assertGreaterThan(0, $drawings[0]->getHeight());
            $this->assertSame(150.0, $spreadsheet->getActiveSheet()->getRowDimension(2)->getRowHeight());
            $this->assertSame(50.0, $spreadsheet->getActiveSheet()->getColumnDimension('L')->getWidth());
        } finally {
            unlink($file);
        }
    }

    public function test_signed_image_proof_url_is_public_but_cannot_be_modified(): void
    {
        $proofPath = 'proof-of-payment/payment-proof.png';
        $imageContents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/vGkAAAAASUVORK5CYII=',
            true,
        );
        $this->assertNotFalse($imageContents);
        Storage::disk('local')->put($proofPath, $imageContents);

        $registration = Registration::create([
            ...$this->registrationData()['attendees'][0],
            'district' => 'CD',
            'email' => 'alex@example.com',
            'proof_of_payment_path' => $proofPath,
        ]);
        $signedUrl = URL::signedRoute(
            'registrations.proof-image',
            ['registration' => $registration],
            absolute: false,
        );

        $this->get($signedUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->get($signedUrl.'&modified=1')->assertForbidden();
    }

    public function test_admin_can_sign_in_with_the_configured_access_key(): void
    {
        config(['registrations.admin_export_key' => 'test-admin-key']);

        $this->post(route('admin.authenticate'), ['access_key' => 'test-admin-key'])
            ->assertRedirect(route('admin.index'));

        $this->get(route('admin.index'))->assertOk();
    }

    public function test_admin_can_add_an_access_email(): void
    {
        $response = $this->withSession(['admin_authenticated' => true])
            ->post(route('admin.access-emails.store'), ['email' => ' NewUser@Example.com ']);

        $response->assertRedirect();
        $response->assertSessionHas('accessEmailSuccess', 'Access email added.');
        $this->assertDatabaseHas('allowed_emails', ['email' => 'newuser@example.com']);

        $this->post(route('registrations.verify-email'), ['email' => 'newuser@example.com'])
            ->assertRedirect(route('registrations.details'));
    }

    public function test_admin_can_add_multiple_access_emails(): void
    {
        $response = $this->withSession(['admin_authenticated' => true])
            ->post(route('admin.access-emails.store'), ['emails' => "one@example.com\ntwo@example.com"]);

        $response->assertSessionHas('accessEmailSuccess', '2 access emails added.');
        $this->assertDatabaseHas('allowed_emails', ['email' => 'one@example.com']);
        $this->assertDatabaseHas('allowed_emails', ['email' => 'two@example.com']);
    }

    public function test_adding_an_access_email_requires_admin_access(): void
    {
        $this->post(route('admin.access-emails.store'), ['email' => 'newuser@example.com'])
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseCount('allowed_emails', 0);
    }

    public function test_admin_can_remove_a_database_access_email(): void
    {
        $this->withSession(['admin_authenticated' => true])
            ->post(route('admin.access-emails.store'), ['email' => 'newuser@example.com']);

        $response = $this->delete(
            route('admin.access-emails.destroy', ['email' => 'newuser@example.com']),
            [],
            ['Referer' => route('admin.index')],
        );

        $response->assertRedirect();
        $response->assertSessionHas('accessEmailSuccess', 'Access email removed.');
        $this->assertDatabaseMissing('allowed_emails', ['email' => 'newuser@example.com']);
    }

    public function test_removing_an_access_email_requires_admin_access(): void
    {
        AllowedEmail::query()->create(['email' => 'newuser@example.com']);

        $this->delete(route('admin.access-emails.destroy', ['email' => 'newuser@example.com']))
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('allowed_emails', ['email' => 'newuser@example.com']);
    }

    public function test_admin_can_remove_multiple_database_access_emails(): void
    {
        AllowedEmail::query()->create(['email' => 'one@example.com']);
        AllowedEmail::query()->create(['email' => 'two@example.com']);

        $response = $this->withSession(['admin_authenticated' => true])
            ->post(route('admin.access-emails.destroy-many'), [
                'emails' => ['one@example.com', 'two@example.com'],
            ]);

        $response->assertSessionHas('accessEmailSuccess', '2 access emails removed.');
        $this->assertDatabaseMissing('allowed_emails', ['email' => 'one@example.com']);
        $this->assertDatabaseMissing('allowed_emails', ['email' => 'two@example.com']);
    }

    public function test_adding_an_access_email_validates_the_email_address(): void
    {
        $this->withSession(['admin_authenticated' => true])
            ->post(route('admin.access-emails.store'), ['email' => 'not-an-email'])
            ->assertSessionHasErrors('emails.0');

        $this->assertDatabaseCount('allowed_emails', 0);
    }

    private function registrationData(): array
    {
        return [
            'email' => 'alex@example.com',
            'district' => 'CD',
            'proof_of_payment' => UploadedFile::fake()->createWithContent('payment-proof.pdf', "%PDF-1.4\nSample payment receipt"),
            'attendees' => [[
                'surname' => 'Santos',
                'given_name' => 'Alex',
                'middle_initial' => 'M',
                'nickname' => 'Al',
                'birthdate' => '2000-01-15',
                'age' => '28',
                'gender' => 'Prefer not to say',
                'shirt_size' => 'M',
                'division' => 'Youth',
                'ministry' => 'Music',
            ]],
        ];
    }

    private function createGoogleCredentialsFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'google-service-account-');
        $this->assertNotFalse($path);
        file_put_contents($path, json_encode([
            'client_email' => 'convention-test@example.iam.gserviceaccount.com',
            'private_key' => 'not-used-while-the-token-is-cached',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    private function createOAuthClientFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'google-oauth-client-');
        $this->assertNotFalse($path);
        file_put_contents($path, json_encode([
            'web' => [
                'client_id' => 'test-oauth-client-id',
                'client_secret' => 'test-oauth-client-secret',
                'auth_uri' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
