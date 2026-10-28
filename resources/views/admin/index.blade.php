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
        <p class="hint" style="margin: 0 0 14px">
            Showing {{ $registrations->total() }} registration(s).
            @if ($pendingSheetSyncCount > 0)
                {{ $pendingSheetSyncCount }} registration(s) are waiting to sync to Google Sheets.
            @else
                All registrations are synced to Google Sheets.
            @endif
            Payment proofs are only accessible to signed-in admins.
        </p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Attendee</th>
                        <th>District</th>
                        <th>Email</th>
                        <th>Birthdate</th>
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
                            <td>{{ $registration->birthdate?->format('Y-m-d') ?? '—' }}</td>
                            <td>{{ $registration->division }}</td>
                            <td>{{ $registration->ministry }}</td>
                            <td>{{ $registration->google_sheets_synced_at ? 'Synced' : 'Pending' }}</td>
                            <td><a href="{{ route('admin.proof', $registration) }}">Download</a></td>
                            <td>{{ $registration->created_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9">No registrations have been submitted yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $registrations->links() }}</div>
    </section>
    </div>
@endsection
