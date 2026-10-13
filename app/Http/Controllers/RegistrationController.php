<?php

namespace App\Http\Controllers;

use App\Mail\RegistrationReceived;
use App\Models\Registration;
use App\Services\GoogleSheetsAppender;
use App\Services\GoogleSheetsException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class RegistrationController extends Controller
{
    public function create(): View
    {
        return view('registrations.create', [
            'districts' => config('registrations.districts'),
            'genders' => config('registrations.genders'),
            'shirtSizes' => config('registrations.shirt_sizes'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'district' => ['required', Rule::in(config('registrations.districts'))],
            'proof_of_payment' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'attendees' => ['required', 'array', 'min:1', 'max:50'],
            'attendees.*' => ['required', 'array:surname,given_name,middle_initial,nickname,age,gender,shirt_size,division,ministry'],
            'attendees.*.surname' => ['required', 'string', 'max:100'],
            'attendees.*.given_name' => ['required', 'string', 'max:100'],
            'attendees.*.middle_initial' => ['nullable', 'string', 'max:10'],
            'attendees.*.nickname' => ['nullable', 'string', 'max:100'],
            'attendees.*.age' => ['required', 'integer', 'between:1,120'],
            'attendees.*.gender' => ['required', Rule::in(config('registrations.genders'))],
            'attendees.*.shirt_size' => ['required', Rule::in(config('registrations.shirt_sizes'))],
            'attendees.*.division' => ['required', 'string', 'max:150'],
            'attendees.*.ministry' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
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
                    $registrations->push(Registration::create([
                        ...$attendee,
                        'district' => $validated['district'],
                        'email' => $validated['email'],
                        'proof_of_payment_path' => $storedProofs[0],
                    ]));
                }

                return $registrations;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedProofs);
            throw $exception;
        }

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

        foreach ($registrations as $registration) {
            if (is_string($adminEmail) && $adminEmail !== '') {
                $this->sendNotification(
                    $registration,
                    $adminEmail,
                    true,
                    'admin notification',
                    $emailFailures,
                );
            }
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
