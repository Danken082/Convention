<?php

namespace App\Mail;

use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class RegistrationReceived extends Mailable
{
    use Queueable;

    public function __construct(
        public Registration $registration,
        public bool $includeProof = false,
        public array $registrations = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->includeProof
                ? 'New convention registration'
                : 'Your convention registration',
        );
    }

    public function content(): Content
    {
        $spreadsheetId = config('services.google_sheets.spreadsheet_id');
        $spreadsheetUrl = is_string($spreadsheetId) && $spreadsheetId !== ''
            ? 'https://docs.google.com/spreadsheets/d/'.rawurlencode($spreadsheetId).'/edit'
            : null;

        return new Content(
            view: 'emails.registration-received',
            with: [
                'spreadsheetUrl' => $spreadsheetUrl,
                'attendees' => $this->registrations ?: [$this->registration],
            ],
        );
    }

    public function attachments(): array
    {
        if ($this->includeProof) {
            return [
                Attachment::fromStorageDisk('local', $this->registration->proof_of_payment_path)
                    ->as(basename($this->registration->proof_of_payment_path)),
            ];
        }

        return [];
    }
}
