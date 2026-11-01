<h1>{{ $includeProof ? 'A new convention registration was submitted' : 'Your convention registration is saved' }}</h1>
<p>
    @if ($includeProof)
        A new registration and proof of payment are attached for your review.
        @if ($spreadsheetUrl)
            <a href="{{ $spreadsheetUrl }}">Open the convention registration spreadsheet</a>
        @endif
    @else
        Thank you. Your registration details are listed below.
    @endif
</p>
@foreach ($attendees as $attendee)
<ul>
    <li><strong>Attendee:</strong> {{ $attendee->given_name }} {{ $attendee->middle_initial }} {{ $attendee->surname }}</li>
    <li><strong>District:</strong> {{ $attendee->district }}</li>
    <li><strong>Birthdate:</strong> {{ $attendee->birthdate?->format('Y-m-d') }}</li>
    <li><strong>Age:</strong> {{ $attendee->age }}</li>
    <li><strong>Gender:</strong> {{ $attendee->gender }}</li>
    <li><strong>Shirt size:</strong> {{ $attendee->shirt_size }}</li>
    <li><strong>Division:</strong> {{ $attendee->division }}</li>
    <li><strong>Ministry:</strong> {{ $attendee->ministry }}</li>
</ul>
@endforeach
