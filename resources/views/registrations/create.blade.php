@extends('layouts.app')

@section('title', 'Register | Convention Registration')

@section('content')
    <div class="intro">
        <div class="eyebrow">Attendee registration</div>
        <h1>General Question</h1>
        <p>Select one district and attach one payment proof for the submission. Add one individual form for each attendee. A single confirmation email address is used for everyone.</p>
    </div>

    @if (session('success'))
        <div class="notice success" role="status">{{ session('success') }}</div>
    @endif
    @if (session('emailWarning'))
        <div class="notice warning" role="alert">{{ session('emailWarning') }}</div>
    @endif
    @if ($errors->any())
        <div class="notice error" role="alert">Please check the highlighted fields and try again.</div>
    @endif

    @php($attendees = old('attendees', [[]]))
    <form id="registration-form" class="card" action="{{ route('registrations.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <h2 class="section-title">Submission details</h2>
        <div class="fields">
            <div class="field">
                <label for="district">District <span class="required">*</span></label>
                <select id="district" name="district" required>
                    <option value="">Choose a district</option>
                    @foreach ($districts as $district)
                        <option value="{{ $district }}" @selected(old('district') === $district)>{{ $district }}</option>
                    @endforeach
                </select>
                @error('district') <p class="error-text">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="proof_of_payment">Proof of payment <span class="required">*</span></label>
                <input id="proof_of_payment" name="proof_of_payment" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                <p class="hint">Attach one PDF, JPG, or PNG, up to 10 MB. This proof is shared by all attendees in this submission.</p>
                @error('proof_of_payment') <p class="error-text">{{ $message }}</p> @enderror
            </div>
        </div>

        <h2 class="section-title" style="margin-top: 30px">Individual fields</h2>
        <div id="attendee-forms">
            @foreach ($attendees as $index => $attendee)
                @include('registrations._attendee', ['index' => $index, 'attendee' => $attendee])
            @endforeach
        </div>

        <div class="actions" style="justify-content: space-between">
            <button id="add-attendee" class="secondary" type="button">+ Add another form</button>
            <button id="open-email-dialog" type="button">Save registrations</button>
        </div>

        <dialog id="email-dialog" aria-labelledby="email-dialog-title">
            <h2 id="email-dialog-title">Where should we send the confirmation?</h2>
            <p>We’ll send confirmation for all attendees to this address and notify the convention organizers.</p>
            <label for="email">Your email address <span class="required">*</span></label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" maxlength="255">
            @error('email') <p class="error-text">{{ $message }}</p> @enderror
            <div class="dialog-actions">
                <button id="cancel-email" class="secondary" type="button">Go back</button>
                <button id="confirm-save" type="button">Confirm and save</button>
            </div>
        </dialog>

        <template id="attendee-template">
            @include('registrations._attendee', ['index' => '__INDEX__', 'attendee' => []])
        </template>
    </form>
@endsection

@push('scripts')
    <script>
        const form = document.getElementById('registration-form');
        const attendeeForms = document.getElementById('attendee-forms');
        const attendeeTemplate = document.getElementById('attendee-template');
        const emailDialog = document.getElementById('email-dialog');
        const emailInput = document.getElementById('email');
        let nextAttendeeIndex = {{ count($attendees) }};

        function updateAttendeeForms() {
            const cards = [...attendeeForms.querySelectorAll('.attendee-form')];
            cards.forEach((card, index) => {
                card.querySelector('.attendee-heading').textContent = `Attendee ${index + 1}`;
                card.querySelector('.remove-attendee').hidden = cards.length === 1;
            });
            document.getElementById('add-attendee').disabled = cards.length >= 50;
        }

        document.getElementById('add-attendee').addEventListener('click', () => {
            const index = nextAttendeeIndex++;
            attendeeForms.insertAdjacentHTML(
                'beforeend',
                attendeeTemplate.innerHTML.replaceAll('__INDEX__', String(index)),
            );
            updateAttendeeForms();
            attendeeForms.lastElementChild.querySelector('select').focus();
        });

        attendeeForms.addEventListener('click', (event) => {
            const removeButton = event.target.closest('.remove-attendee');
            if (!removeButton) return;
            removeButton.closest('.attendee-form').remove();
            updateAttendeeForms();
        });

        document.getElementById('open-email-dialog').addEventListener('click', () => {
            emailInput.required = false;
            const attendeesAreValid = form.reportValidity();
            emailInput.required = true;
            if (!attendeesAreValid) return;
            emailDialog.showModal();
            emailInput.focus();
        });

        document.getElementById('cancel-email').addEventListener('click', () => emailDialog.close());
        document.getElementById('confirm-save').addEventListener('click', () => {
            if (!form.reportValidity()) return;
            emailDialog.close();
            form.requestSubmit();
        });

        @if ($errors->has('email'))
            emailInput.required = true;
            emailDialog.showModal();
            emailInput.focus();
        @endif

        updateAttendeeForms();
    </script>
@endpush
