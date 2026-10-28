@extends('layouts.app')

@section('title', 'Register | Convention Registration')
@section('body-class', 'registration-page')

@section('content')
    <div class="registration-shell">
        <div class="form-panel">
            @if (session('emailWarning'))
                <div class="notice warning" role="alert">{{ session('emailWarning') }}</div>
            @endif
            @if ($errors->any() && ! $errors->has('email'))
                <div class="notice error" role="alert">Please check the highlighted fields and try again.</div>
            @endif

            <section class="intro" aria-labelledby="registration-intro-title">
                <h1 id="registration-intro-title">UFY Registration</h1>
                <p><strong><em>Hello mga ka-UFY! Our FOURSQUARE GLOBAL CONFERENCE FOR YOUNG ADULTS is set this July 23 to 25, 2027 at SMX Manila, Pasay City, Philippines.</em></strong></p>
                <p><strong>How to fill out the registration form?</strong></p>
                <ol class="registration-instructions">
                    <li>Enter the official email that will be used to confirm the payment.</li>
                    <li>Select your district.</li>
                    <li>Fill out the names of the delegates included in the receipt.</li>
                    <li>Attach your bank receipt as proof of payment and submit the form.</li>
                    <li>Upon submission, wait for our confirmation that will be sent to your email.</li>
                </ol>
                <div class="bank-details">
                    <p class="bank-details-title"><strong>BANK DETAILS</strong></p>
                    <p>Bank Name: <strong>Bank of the Philippine Islands (BPI-Savings Account)</strong></p>
                    <p>Account Name: <strong>Church of the Foursquare Gospel in the Philippines, Inc.</strong></p>
                    <p>Account Number: <strong>005613-3775-62</strong></p>
                    
                </div>
                <br>
                <div class="facebook">
                    <p><strong>For inquiries, kindly message the official UFY-PH Facebook page: Foursquare Youth - Philippines </strong></p>
            <a href="https://www.facebook.com/foursquareyouthphilippines" target="_blank">https://www.facebook.com/FoursquareYouthPhilippines</a>
               </div>
            </div>
            </section>

            <section class="card email-step" aria-labelledby="email-step-title">
                <h2 id="email-step-title" class="section-title">Email verification</h2>
                @if (! $allowedEmailsConfigured)
                    <p class="notice warning" role="alert">Registration is not available until approved email addresses are configured.</p>
                @endif

                <form id="email-verification-form" action="{{ route('registrations.verify-email') }}" method="POST">
                    @csrf
                    <label for="verification-email">{{ $verifiedEmail ? 'Change to another approved email' : 'Enter your approved email to continue' }} <span class="required">*</span></label>
                    <input id="verification-email" name="email" type="email" value="{{ old('email', $verifiedEmail) }}" autocomplete="email" maxlength="255" required>
                    @error('email') <p class="error-text" role="alert">{{ $message }}</p> @enderror
                    <p class="hint">Only email addresses approved for this registration can continue.</p>
                    <div class="actions">
                                        @if ($verifiedEmail)
                    <div id="verified-email-display">
                        <a class="button" style="margin-right: auto; background: #d9d9d9; color: #150d08;" href="{{ route('registrations.details') }}">Continue to Section 2</a>
                    </div>
                @endif
                        <button type="submit" @if (! $allowedEmailsConfigured) disabled @endif>{{ $verifiedEmail ? 'Verify another email' : 'Verify email and continue' }}</button>
                    </div>
                </form>
            </section>
        </div>
    </div>

    @if (session('success'))
        <dialog id="registration-success-dialog" aria-labelledby="registration-success-title">
            <img
                class="registration-success-logo"
                src="{{ asset('images/unstoppable-2027-banner.png') }}"
                alt="Unstoppable 2027, Foursquare Global Conference for Young Adults"
            >
            <h2 id="registration-success-title">Registration submitted</h2>
            <p>Please check your email to see your registration details. Thank you!</p>
            <button id="close-registration-success" type="button">Close</button>
        </dialog>
    @endif
@endsection

@if (session('success'))
    @push('scripts')
        <script>
            const registrationSuccessDialog = document.getElementById('registration-success-dialog');
            registrationSuccessDialog.showModal();
            document.getElementById('close-registration-success').addEventListener('click', () => {
                registrationSuccessDialog.close();
            });
        </script>
    @endpush
@endif
