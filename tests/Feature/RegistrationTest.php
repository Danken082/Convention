<?php

namespace Tests\Feature;

use App\Mail\RegistrationReceived;
use App\Models\Registration;
use App\Services\GoogleSheetsOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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
        config(['services.google_sheets.spreadsheet_id' => 'test-spreadsheet-id']);
    }

    public function test_registration_is_saved_and_email_is_sent_to_submitter_and_admin(): void
    {
        Mail::fake();

        $response = $this->post(route('registrations.store'), $this->registrationData());

        $response->assertRedirect(route('registrations.create'));
        $response->assertSessionHas('success', '1 attendee registration(s) saved.');
        $this->assertDatabaseHas('registrations', [
            'district' => 'CD',
            'surname' => 'Santos',
            'given_name' => 'Alex',
            'email' => 'alex@example.com',
        ]);

        $registration = Registration::firstOrFail();
        Storage::disk('local')->assertExists($registration->proof_of_payment_path);
        Mail::assertSent(
            RegistrationReceived::class,
            fn (RegistrationReceived $mail) => $mail->hasTo('alex@example.com') && ! $mail->includeProof,
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

    public function test_each_submission_creates_a_separate_registration(): void
    {
        Mail::fake();

        $first = $this->registrationData();
        $second = $this->registrationData();
        $second['attendees'][0]['surname'] = 'Reyes';
        $second['email'] = 'sam@example.com';

        $this->post(route('registrations.store'), $first)->assertRedirect(route('registrations.create'));
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
        Mail::assertSent(RegistrationReceived::class, 3);

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

        $attachment = $submitterMail->attachments()[0];
        $this->assertSame('convention-registrations.xlsx', $attachment->as);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $attachment->mime,
        );

        $workbookContents = null;
        $attachment->attachWith(
            static fn () => null,
            static function ($data) use (&$workbookContents) {
                $workbookContents = $data();
            },
        );
        $this->assertIsString($workbookContents);

        $file = tempnam(sys_get_temp_dir(), 'convention-email-export-');
        $this->assertNotFalse($file);
        try {
            file_put_contents($file, $workbookContents);
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertSame('Surname', $sheet->getCell('B1')->getValue());
            $this->assertSame('Santos', $sheet->getCell('B2')->getValue());
            $this->assertSame('Reyes', $sheet->getCell('B3')->getValue());
        } finally {
            unlink($file);
        }
    }

    public function test_batch_is_appended_to_the_configured_google_sheet(): void
    {
        Mail::fake();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/*/values/*:append*' => Http::response([], 200),
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
            $rows = $appendRequests->first()[0]->data()['values'];
            $this->assertCount(2, $rows);
            $this->assertSame('Santos', $rows[0][1]);
            $this->assertSame('Reyes', $rows[1][1]);
            $this->assertSame('alex@example.com', $rows[0][10]);
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

    public function test_oauth_client_appends_registration_to_google_sheet_after_authorization(): void
    {
        Mail::fake();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'oauth-access-token',
                'expires_in' => 3600,
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/*/values/*:append*' => Http::response([], 200),
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
                    && str_contains($pair[0]->url(), '/values/'),
            );
            $this->assertCount(1, $appendRequests);
        } finally {
            unlink($credentialsPath);
        }
    }

    public function test_registration_form_is_available(): void
    {
        $response = $this->get(route('registrations.create'))
            ->assertOk()
            ->assertSee('Attendee 1')
            ->assertSee('Add another form')
            ->assertSee('Confirm and save')
            ->assertSee('NORSD');

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'name="district"'));
        $this->assertSame(1, substr_count($html, 'name="proof_of_payment"'));
        $this->assertStringNotContainsString('attendees[0][district]', $html);
        $this->assertStringNotContainsString('attendees[0][proof_of_payment]', $html);
        $this->assertStringNotContainsString('attendees[__INDEX__][district]', $html);
        $this->assertStringNotContainsString('attendees[__INDEX__][proof_of_payment]', $html);
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
        } finally {
            unlink($file);
        }
    }

    public function test_admin_can_sign_in_with_the_configured_access_key(): void
    {
        config(['registrations.admin_export_key' => 'test-admin-key']);

        $this->post(route('admin.authenticate'), ['access_key' => 'test-admin-key'])
            ->assertRedirect(route('admin.index'));

        $this->get(route('admin.index'))->assertOk();
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
