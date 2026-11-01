<?php

namespace App\Http\Controllers;

use App\Mail\RegistrationReceived;
use App\Models\Registration;
use App\Services\AccessEmailService;
use App\Services\GoogleSheetsAppender;
use App\Services\GoogleSheetsException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class RegistrationController extends Controller
{
    public function __construct(private AccessEmailService $accessEmailService) {}

    public function create(): View
    {
        $verifiedEmail = session('registration_verified_email');
        $allowedEmails = $this->accessEmailService->all();
        if (! in_array($verifiedEmail, $allowedEmails, true)) {
            session()->forget('registration_verified_email');
            $verifiedEmail = null;
        }

        return view('registrations.create', [
            'verifiedEmail' => $verifiedEmail,
            'allowedEmailsConfigured' => $allowedEmails !== [],
        ]);
    }

    public function details(): View|RedirectResponse
    {
        $verifiedEmail = session('registration_verified_email');
        if (! is_string($verifiedEmail)
            || ! $this->accessEmailService->isAllowed($verifiedEmail)) {
            session()->forget('registration_verified_email');

            return redirect()->route('registrations.create');
        }

        return view('registrations.details', [
            'districts' => config('registrations.districts'),
            'genders' => config('registrations.genders'),
            'shirtSizes' => config('registrations.shirt_sizes'),
            'verifiedEmail' => $verifiedEmail,
            'draft' => session('registration_draft', []),
        ]);
    }

    public function proofImage(Registration $registration): BinaryFileResponse
    {
        $proofPath = $registration->proof_of_payment_path;
        if (! Storage::disk('local')->exists($proofPath)) {
            abort(404);
        }

        $imagePath = Storage::disk('local')->path($proofPath);
        $imageInfo = @getimagesize($imagePath);
        if ($imageInfo === false || ! in_array($imageInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            abort(404);
        }

        return response()->file($imagePath, [
            'Content-Type' => $imageInfo[2] === IMAGETYPE_JPEG ? 'image/jpeg' : 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function saveDraft(Request $request): JsonResponse
    {
        $verifiedEmail = $request->session()->get('registration_verified_email');
        if (! is_string($verifiedEmail)
            || ! $this->accessEmailService->isAllowed($verifiedEmail)) {
            return response()->json([
                'message' => 'Verify an approved email address before saving a registration draft.',
            ], 403);
        }

        $validated = $request->validate([
            'district' => ['nullable', 'string', Rule::in(config('registrations.districts'))],
            'attendees' => ['required', 'array', 'min:1', 'max:50'],
            'attendees.*' => ['required', 'array:surname,given_name,middle_initial,nickname,birthdate,age,gender,shirt_size,shirt_size_other,division,ministry'],
            'attendees.*.surname' => ['nullable', 'string', 'max:100'],
            'attendees.*.given_name' => ['nullable', 'string', 'max:100'],
            'attendees.*.middle_initial' => ['nullable', 'string', 'max:10'],
            'attendees.*.nickname' => ['nullable', 'string', 'max:100'],
            'attendees.*.birthdate' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'attendees.*.age' => ['nullable', 'string', 'max:3'],
            'attendees.*.gender' => ['nullable', 'string', Rule::in(config('registrations.genders'))],
            'attendees.*.shirt_size' => ['nullable', 'string', Rule::in(config('registrations.shirt_sizes'))],
            'attendees.*.shirt_size_other' => ['nullable', 'string', 'max:100'],
            'attendees.*.division' => ['nullable', 'string', 'max:150'],
            'attendees.*.ministry' => ['nullable', 'string', 'max:150'],
        ]);

        $request->session()->put('registration_draft', $validated);

        return response()->json(['saved' => true]);
    }

    public function verifyEmail(Request $request): RedirectResponse
    {
        $previousEmail = $request->session()->get('registration_verified_email');
        $request->session()->forget('registration_verified_email');

        $email = $request->input('email');
        if (is_string($email)) {
            $request->merge(['email' => strtolower(trim($email))]);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::in($this->accessEmailService->all())],
        ], [
            'email.in' => 'This email address is not approved for registration.',
        ]);

        $request->session()->put('registration_verified_email', $validated['email']);
        if ($previousEmail !== $validated['email']) {
            $request->session()->forget('registration_draft');
        }

        return redirect()->route('registrations.details');
    }

    public function store(Request $request): RedirectResponse
    {
        $email = $request->input('email');
        if (is_string($email)) {
            $request->merge(['email' => strtolower(trim($email))]);
        }

        $email = $request->input('email');
        $verifiedEmail = $request->session()->get('registration_verified_email');
        if (! is_string($email)
            || ! is_string($verifiedEmail)
            || $email !== $verifiedEmail
            || ! $this->accessEmailService->isAllowed($verifiedEmail)) {
            $request->session()->forget('registration_verified_email');
            $request->session()->forget('registration_draft');

            return back()
                ->withErrors(['email' => 'Enter and verify an approved email address before submitting registrations.'])
                ->withInput();
        }

        $validated = $request->validate([
            'district' => ['required', Rule::in(config('registrations.districts'))],
            'proof_of_payment' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'attendees' => ['required', 'array', 'min:1', 'max:50'],
            'attendees.*' => ['required', 'array:surname,given_name,middle_initial,nickname,birthdate,age,gender,shirt_size,shirt_size_other,division,ministry'],
            'attendees.*.surname' => ['required', 'string', 'max:100'],
            'attendees.*.given_name' => ['required', 'string', 'max:100'],
            'attendees.*.middle_initial' => ['nullable', 'string', 'max:10'],
            'attendees.*.nickname' => ['nullable', 'string', 'max:100'],
            'attendees.*.birthdate' => [
                'required',
                'date_format:Y-m-d',
                'before:2011-07-23',
            ],
            'attendees.*.age' => ['required', 'integer', 'between:16,120'],
            'attendees.*.gender' => ['required', Rule::in(config('registrations.genders'))],
            'attendees.*.shirt_size' => ['required', Rule::in(config('registrations.shirt_sizes'))],
            'attendees.*.shirt_size_other' => ['nullable', 'required_if:attendees.*.shirt_size,Other', 'string', 'max:100'],
            'attendees.*.division' => ['required', 'string', 'max:150'],
            'attendees.*.ministry' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::in($this->accessEmailService->all())],
        ], [
            'attendees.*.birthdate.before' => 'Attendees born on or after July 23, 2011 cannot register.',
            'attendees.*.age.between' => 'Each attendee must be at least 16 years old to register.',
        ]);

        $storedProofs = [];

        try {
            $proofPath = $validated['proof_of_payment']->store('proof-of-payment', 'local');
            if ($proofPath === false) {
                throw new \RuntimeException('The proof-of-payment file could not be stored.');
            }
            $storedProofs[] = $proofPath;

            $registrations = DB::transaction(function () use ($validated, &$storedProofs): Collection {
                $registrations = new Collection;

                foreach ($validated['attendees'] as $attendee) {
                    $shirtSize = $attendee['shirt_size'] === 'Other'
                        ? trim($attendee['shirt_size_other'])
                        : $attendee['shirt_size'];

                    $registrations->push(Registration::create([
                        ...$attendee,
                        'district' => $validated['district'],
                        'email' => $validated['email'],
                        'shirt_size' => $shirtSize,
                        'proof_of_payment_path' => $storedProofs[0],
                    ]));
                }

                return $registrations;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedProofs);
            throw $exception;
        }

        $request->session()->forget('registration_verified_email');

        $emailFailures = [];
        try {
            app(GoogleSheetsAppender::class)->appendRegistrations($registrations);
            Registration::query()
                ->whereIn('id', $registrations->modelKeys())
                ->update(['google_sheets_synced_at' => now()]);
        } catch (GoogleSheetsException $exception) {
            Log::error('Convention registrations could not be appended to Google Sheets.', [
                'registration_ids' => $registrations->modelKeys(),
                'exception' => $exception->getMessage(),
            ]);
            $emailFailures[] = 'Google Sheets sync: '.$exception->getMessage();
        }

        $adminEmail = config('registrations.admin_email');

        $this->sendNotification(
            $registrations->firstOrFail(),
            $registrations->firstOrFail()->email,
            false,
            'submitter confirmation',
            $emailFailures,
            $registrations->all(),
        );

        if (is_string($adminEmail) && $adminEmail !== '') {
            $this->sendNotification(
                $registrations->firstOrFail(),
                $adminEmail,
                true,
                'admin notification',
                $emailFailures,
                $registrations->all(),
            );
        }

        if (! is_string($adminEmail) || $adminEmail === '') {
            $emailFailures[] = 'admin notification (ADMIN_EMAIL is not configured)';
        }

        $notificationWarning = implode(', ', array_unique($emailFailures));

        return redirect()
            ->route('registrations.create')
            ->with('success', count($registrations).' attendee registration(s) saved.')
            ->with('emailWarning', $emailFailures === []
                ? null
                : 'The registrations were saved, but some notifications could not be completed: '.rtrim($notificationWarning, '.').'.');
    }

    private function sendNotification(
        Registration $registration,
        string $recipient,
        bool $includeProof,
        string $notificationName,
        array &$emailFailures,
        array $includedRegistrations = [],
    ): void {
        try {
            Mail::to($recipient)->send(new RegistrationReceived(
                $registration,
                $includeProof,
                $includedRegistrations,
            ));
        } catch (TransportExceptionInterface $exception) {
            Log::error('A registration email could not be delivered.', [
                'registration_id' => $registration->id,
                'recipient' => $recipient,
                'exception' => $exception->getMessage(),
            ]);
            $emailFailures[] = $notificationName;
        }
    }
}
