<div style="margin:0;background:#f3f6f8;padding:24px 12px;color:#182b3b;font-family:Arial,Helvetica,sans-serif;line-height:1.5">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e0e8ed">
                    <tr>
                        <td>
                            <img src="{{ asset('images/unstoppable-2027-banner.png') }}" alt="Unstoppable 2027, Foursquare Global Conference for Young Adults" width="600" style="display:block;width:100%;height:auto">
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 30px 12px">
                            <p style="margin:0 0 6px;color:#16765c;font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase">{{ $includeProof ? 'Organizer notification' : 'Registration confirmation' }}</p>
                            <h1 style="margin:0;font-size:24px;line-height:1.25;color:#182b3b">{{ $includeProof ? 'A new registration was submitted' : 'Your registration is saved' }}</h1>
                            <p style="margin:14px 0 0;color:#637687">
                                @if ($includeProof)
                                    A new registration and proof of payment are attached for your review.
                                    @if ($spreadsheetUrl)
                                        <a href="{{ $spreadsheetUrl }}" style="color:#176b54;font-weight:bold">Open the registration spreadsheet</a>
                                    @endif
                                @else
                                    Thank you for registering for the <strong>FOURSQUARE GLOBAL CONFERENCE FOR YOUNG ADULTS 2027!</strong> 🎉<br><br>
                                    Your <strong>Registration Details</strong> are included below. Your <strong>Proof of Payment</strong> is attached for your review and reference.
                                @endif
                            </p>
                            @if (! $includeProof)
                                <p style="margin:14px 0 0;color:#637687;font-size:13px">
                                    For any inquiries or corrections, kindly send a message to the official UFY-PH Facebook page:
                                    <strong>Foursquare Youth – Philippines</strong><br>
                                    <a href="https://www.facebook.com/FoursquareYouthPhilippines" style="color:#176b54">https://www.facebook.com/FoursquareYouthPhilippines</a>
                                </p>
                            @endif
                            <p style="margin:10px 0 0;color:#8a9aa5;font-size:12px">Submitted: {{ $submittedAt }} Philippine Time</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 30px 16px">
                            <table role="table" width="100%" cellspacing="0" cellpadding="0" border="1" bordercolor="#e0e8ed" style="border-collapse:collapse;border:1px solid #e0e8ed">
                                <thead>
                                    <tr>
                                        <th colspan="8" align="left" style="padding:11px 12px;background:#f3f6f8;color:#a0602b;font-size:16px">Registered Participants</th>
                                    </tr>
                                    <tr>
                                        <th align="left" style="padding:8px 10px;background:#fafcfd;color:#637687;font-size:12px">Attendee</th>
                                        <th align="left" style="padding:8px 10px;background:#fafcfd;color:#637687;font-size:12px">District</th>
                                        <th align="left" style="padding:8px 10px;background:#fafcfd;color:#637687;font-size:12px">Birthdate</th>
                                        <th align="left" style="padding:8px 10px;background:#fafcfd;color:#637687;font-size:12px">Age</th>
                                        <th align="left" style="padding:8px 10px;background:#fafcfd;color:#637687;font-size:12px">Gender</th>
                                        <th align="left" style="padding:8px 10px;background:#fafcfd;color:#637687;font-size:12px">Shirt size</th>
                                        <th align="left" style="padding:8px 10px;background:#fafcfd;color:#637687;font-size:12px">Division</th>
                                        <th align="left" style="padding:8px 10px;background:#fafcfd;color:#637687;font-size:12px">Ministry</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($attendees as $attendee)
                                        <tr>
                                            <td style="padding:8px 10px;color:#182b3b;font-size:13px">{{ $attendee->given_name }} {{ $attendee->middle_initial }} {{ $attendee->surname }}</td>
                                            <td style="padding:8px 10px;color:#182b3b;font-size:13px">{{ $attendee->district }}</td>
                                            <td style="padding:8px 10px;color:#182b3b;font-size:13px">{{ $attendee->birthdate?->format('F j, Y') }}</td>
                                            <td style="padding:8px 10px;color:#182b3b;font-size:13px">{{ $attendee->age }}</td>
                                            <td style="padding:8px 10px;color:#182b3b;font-size:13px">{{ $attendee->gender }}</td>
                                            <td style="padding:8px 10px;color:#182b3b;font-size:13px">{{ $attendee->shirt_size }}</td>
                                            <td style="padding:8px 10px;color:#182b3b;font-size:13px">{{ $attendee->division }}</td>
                                            <td style="padding:8px 10px;color:#182b3b;font-size:13px">{{ $attendee->ministry }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:14px 30px 24px;color:#8a9aa5;font-size:12px">Convention Registration</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
