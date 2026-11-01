<section class="attendee-form">
    <div class="attendee-form-header">
        <button class="secondary remove-attendee" type="button" aria-label="Remove attendee form" @if ($index === 0) hidden @endif>Remove</button>
    </div>
    <div class="fields">
        <div class="field">
            <label for="attendees_{{ $index }}_surname">Surname <span class="required">*</span></label>
            <input id="attendees_{{ $index }}_surname" name="attendees[{{ $index }}][surname]" value="{{ $attendee['surname'] ?? '' }}" autocomplete="family-name" maxlength="100" required>
            @error('attendees.'.$index.'.surname') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="attendees_{{ $index }}_given_name">Given name <span class="required">*</span></label>
            <input id="attendees_{{ $index }}_given_name" name="attendees[{{ $index }}][given_name]" value="{{ $attendee['given_name'] ?? '' }}" autocomplete="given-name" maxlength="100" required>
            @error('attendees.'.$index.'.given_name') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="attendees_{{ $index }}_middle_initial">Middle initial</label>
            <input id="attendees_{{ $index }}_middle_initial" name="attendees[{{ $index }}][middle_initial]" value="{{ $attendee['middle_initial'] ?? '' }}" maxlength="10">
            @error('attendees.'.$index.'.middle_initial') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="attendees_{{ $index }}_nickname">Nickname</label>
            <input id="attendees_{{ $index }}_nickname" name="attendees[{{ $index }}][nickname]" value="{{ $attendee['nickname'] ?? '' }}" maxlength="100">
            @error('attendees.'.$index.'.nickname') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="attendees_{{ $index }}_birthdate">Birthdate <span class="required">*</span></label>
            <input id="attendees_{{ $index }}_birthdate" name="attendees[{{ $index }}][birthdate]" type="date" value="{{ $attendee['birthdate'] ?? '' }}" autocomplete="bday" max="2011-07-22" required>
            <p class="hint">Attendees must be born before July 23, 2011 and be at least 16 years old.</p>
            @error('attendees.'.$index.'.birthdate') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="attendees_{{ $index }}_age">Age <span class="required">*</span></label>
            <input id="attendees_{{ $index }}_age" name="attendees[{{ $index }}][age]" type="number" value="{{ $attendee['age'] ?? '' }}" min="16" max="120" required>
            @error('attendees.'.$index.'.age') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="attendees_{{ $index }}_gender">Gender <span class="required">*</span></label>
            <select id="attendees_{{ $index }}_gender" name="attendees[{{ $index }}][gender]" required>
                <option value="">Choose an option</option>
                @foreach ($genders as $gender)
                    <option value="{{ $gender }}" @selected(($attendee['gender'] ?? '') === $gender)>{{ $gender }}</option>
                @endforeach
            </select>
            @error('attendees.'.$index.'.gender') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        @php
            $shirtSize = $attendee['shirt_size'] ?? '';
            $shirtSizeOther = $attendee['shirt_size_other'] ?? '';
            $shirtSizeIsOther = $shirtSize === 'Other';
        @endphp
        <div class="field">
            <label for="attendees_{{ $index }}_shirt_size">Shirt size <span class="required">*</span></label>
            <select id="attendees_{{ $index }}_shirt_size" name="attendees[{{ $index }}][shirt_size]" required>
                <option value="">Choose a size</option>
                @foreach ($shirtSizes as $size)
                    <option value="{{ $size }}" @selected($shirtSize === $size)>{{ $size }}</option>
                @endforeach
            </select>
            @error('attendees.'.$index.'.shirt_size') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field shirt-size-other-field" @if (! $shirtSizeIsOther) hidden @endif>
            <label for="attendees_{{ $index }}_shirt_size_other">Custom shirt size <span class="required">*</span></label>
            <input id="attendees_{{ $index }}_shirt_size_other" name="attendees[{{ $index }}][shirt_size_other]" value="{{ $shirtSizeOther }}" maxlength="100" @disabled(! $shirtSizeIsOther) @required($shirtSizeIsOther)>
            @error('attendees.'.$index.'.shirt_size_other') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="attendees_{{ $index }}_division">Division <span class="required">*</span></label>
            <input id="attendees_{{ $index }}_division" name="attendees[{{ $index }}][division]" value="{{ $attendee['division'] ?? '' }}" maxlength="150" required>
            @error('attendees.'.$index.'.division') <p class="error-text">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="attendees_{{ $index }}_ministry">Ministry <span class="required">*</span></label>
            <input id="attendees_{{ $index }}_ministry" name="attendees[{{ $index }}][ministry]" value="{{ $attendee['ministry'] ?? '' }}" maxlength="150" required>
            @error('attendees.'.$index.'.ministry') <p class="error-text">{{ $message }}</p> @enderror
        </div>
    </div>
</section>
