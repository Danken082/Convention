@extends('layouts.app')

@section('title', 'Registrations | Convention Registration')
@section('body-class', 'admin-page')

@section('content')
    <div class="admin-shell">
        <div class="admin-header">
            <div class="admin-heading">
                <p class="eyebrow">Organizer dashboard</p>
                <h1>Registrations</h1>
            </div>
        <div class="admin-actions">
                <details class="access-email-menu">
                    <summary class="button secondary">Add access email</summary>
                    <form action="{{ route('admin.access-emails.store') }}" method="POST">
                        @csrf
                        <label for="access-emails">Approved email address(es)</label>
                        <textarea id="access-emails" name="emails" rows="4" placeholder="one@example.com&#10;two@example.com" required></textarea>
                        <p class="hint">Enter one email per line or separate emails with commas.</p>
                        @error('emails') <p class="error-text">{{ $message }}</p> @enderror
                        @error('emails.*') <p class="error-text">{{ $message }}</p> @enderror
                        <button type="submit">Add email(s)</button>
                    </form>
                </details>
              
                @if ($googleSheetsOAuthConfigured)
                <a class="button secondary" href="{{ route('admin.google-sheets.connect') }}">
                    {{ $googleSheetsConnected ? 'Reconnect Google Sheets' : 'Connect Google Sheets' }}
                </a>
                @if (! $googleSheetsConnected)
                    <!-- <p class="hint" style="width: 100%; margin: 0">
                        Add this exact URL to your Google OAuth client's authorized redirect URIs:
                        <code>{{ $googleSheetsRedirectUri }}</code>
                    </p> -->
                @endif
            @endif
            @if ($pendingSheetSyncCount > 0)
                <form action="{{ route('admin.sync') }}" method="POST">
                    @csrf
                    <button type="submit">Sync {{ $pendingSheetSyncCount }} pending</button>
                </form>
            @endif
            <a class="button" href="{{ route('admin.export') }}">Download Excel</a>
              @if ($accessEmails !== [])
                    <button class="secondary" type="button" onclick="document.getElementById('access-emails-dialog').showModal()">
                        Approved emails ({{ count($accessEmails) }})
                    </button>
                @endif
            <form action="{{ route('admin.logout') }}" method="POST">
                @csrf
                <button class="secondary" type="submit">Sign out</button>
            </form>
        </div>
        </div>

    <section class="card admin-card">
        @if (session('syncSuccess'))
            <div class="notice success" role="status">{{ session('syncSuccess') }}</div>
        @endif
        @if (session('syncError'))
            <div class="notice warning" role="alert">{{ session('syncError') }}</div>
        @endif
        @if (session('accessEmailSuccess'))
            <div class="notice success" role="status">{{ session('accessEmailSuccess') }}</div>
        @endif
        @if (session('accessEmailError'))
            <div class="notice warning" role="alert">{{ session('accessEmailError') }}</div>
        @endif
        <p class="hint" style="margin: 0 0 14px">
            Showing {{ $registrations->total() }} registration(s).
            @if ($pendingSheetSyncCount > 0)
                {{ $pendingSheetSyncCount }} registration(s) are waiting to sync to Google Sheets.
            @else
                All registrations are synced to Google Sheets.
            @endif
            A submission's proof appears once in Google Sheets as “See proof of payment (district name)”. JPG/PNG links are non-expiring;
            anyone with a link can view them. PDF proofs remain private and are listed by filename.
        </p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Attendee</th>
                        <th>District</th>
                        <th>Email</th>
                        <th>Birthdate</th>
                        <th>Age</th>
                        <th>Shirt size</th>
                        <th>Division</th>
                        <th>Ministry</th>
                        <th>Google Sheets</th>
                        <th>Proof</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($registrations as $registration)
                        <tr>
                            <td>{{ $registration->surname }}, {{ $registration->given_name }} {{ $registration->middle_initial }}</td>
                            <td>{{ $registration->district }}</td>
                            <td>{{ $registration->email }}</td>
                            <td>{{ $registration->birthdate?->format('F j, Y') ?? '—' }}</td>
                            <td>{{ $registration->age }}</td>
                            <td>{{ $registration->shirt_size }}</td>
                            <td>{{ $registration->division }}</td>
                            <td>{{ $registration->ministry }}</td>
                            <td>{{ $registration->google_sheets_synced_at ? 'Synced' : 'Pending' }}</td>
                            <td><a href="{{ route('admin.proof', $registration) }}">Download</a></td>
                            <td>{{ $registration->created_at->copy()->timezone(config('registrations.display_timezone'))->format('F j, Y g:i A') }} Philippine Time</td>
                        </tr>
                    @empty
                        <tr><td colspan="11">No registrations have been submitted yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $registrations->links() }}</div>
    </section>
    </div>

    @if ($accessEmails !== [])
        <dialog id="access-emails-dialog" aria-labelledby="access-emails-title">
            <h2 id="access-emails-title">Approved emails</h2>
            <p>Select one or more Access emails to remove</p>
            <form action="{{ route('admin.access-emails.destroy-many') }}" method="POST">
                @csrf
                <div class="access-email-options">
                    @foreach ($accessEmails as $accessEmail)
                        <label>
                            @if (in_array($accessEmail, $databaseAccessEmails, true))
                                <input name="emails[]" type="checkbox" value="{{ $accessEmail }}">
                            @endif
                            <span>{{ $accessEmail }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="dialog-actions">
                    <button class="secondary" type="submit" formmethod="dialog">Close</button>
                    <button type="submit">Remove selected</button>
                </div>
            </form>
        </dialog>
    @endif
@endsection
