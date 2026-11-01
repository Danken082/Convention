@extends('layouts.app')

@section('title', 'Registration Details | UFY Registration')
@section('body-class', 'registration-page')

@section('content')
    @php($attendees = old('attendees', $draft['attendees'] ?? [[]]))

    <div class="registration-shell" style="border-left: 5px solid #4285f4;  background: #fff; border-radius: 8px; box-shadow: none; padding: 20px 22px 24px;">
        <div class="form-panel">
            @if ($errors->any())
                <div class="notice error" role="alert">Please check the highlighted fields and try again.</div>
            @endif

            <!-- <div class="section-marker" aria-current="step">Section 2 of 2</div>
            <section class="card email-step" aria-labelledby="registration-details-title">
                <h1 id="registration-details-title" class="section-title">UFY Registration</h1>

                <p>Email: <strong>{{ $verifiedEmail }}</strong></p>
                <small><p><strong><em>Select one district and attach one payment proof for the submission. Add one individual form for each attendee. A single confirmation email address.</em></strong></p>
                <a class="button secondary" href="{{ route('registrations.create') }}">Back to Section 1</a>
            </section> -->

            <form id="registration-form" class="card form-card" action="{{ route('registrations.store') }}" method="POST" enctype="multipart/form-data" novalidate>
                @csrf
                <input type="hidden" name="email" value="{{ $verifiedEmail }}">

                <h2 class="section-title">Registration Details</h2>
                <div class="fields">
                    <div class="field">
                        <label for="district">District <span class="required">*</span></label>
                        <select id="district" name="district" required>
                            <option value="">Choose a district</option>
                            @foreach ($districts as $district)
                                <option value="{{ $district }}" @selected(old('district', $draft['district'] ?? '') === $district)>{{ $district }}</option>
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

                <h2 class="section-title" style="margin-top: 30px">Delegate Information</h2>
                <div id="attendee-forms">
                    @foreach ($attendees as $index => $attendee)
                        @include('registrations._attendee', ['index' => $index, 'attendee' => $attendee])
                    @endforeach
                </div>
                @error('attendees') <p class="error-text">{{ $message }}</p> @enderror

                <p id="draft-save-status" class="hint" role="status" aria-live="polite">Your delegate details are saved automatically.</p>
                <nav id="attendee-pagination" class="attendee-pagination" aria-label="Delegate pages"></nav>
                <div class="actions registration-actions">
                    <div class="registration-action-group">
                        <a class="button secondary" href="{{ route('registrations.create') }}">Back</a>
                        <button id="add-attendee" class="secondary" type="button">Add another Delegate</button>
                    </div>
                    <button type="submit">Submit registration</button>
                </div>

                <template id="attendee-template">
                    @include('registrations._attendee', ['index' => '__INDEX__', 'attendee' => []])
                </template>
            </form>

            <dialog id="registration-confirmation" aria-labelledby="registration-confirmation-title">
                <h2 id="registration-confirmation-title">Review your registration</h2>
                <p>Please check your email and delegate details before submitting.</p>
                <div id="registration-confirmation-summary"></div>
                <div class="dialog-actions">
                    <button id="edit-registration" class="secondary" type="button">Go back and edit</button>
                    <button id="confirm-registration" type="button">Confirm and submit</button>
                </div>
            </dialog>

            <dialog id="proof-preview-dialog" aria-labelledby="proof-preview-title">
                <h2 id="proof-preview-title">Proof of payment preview</h2>
                <img id="proof-preview-image" alt="">
                <div class="dialog-actions">
                    <button id="close-proof-preview" class="secondary" type="button">Close preview</button>
                </div>
            </dialog>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const attendeeForms = document.getElementById('attendee-forms');
        const attendeeTemplate = document.getElementById('attendee-template');
        const attendeePagination = document.getElementById('attendee-pagination');
        const draftSaveStatus = document.getElementById('draft-save-status');
        const registrationForm = document.getElementById('registration-form');
        const confirmationDialog = document.getElementById('registration-confirmation');
        const confirmationSummary = document.getElementById('registration-confirmation-summary');
        const proofPreviewDialog = document.getElementById('proof-preview-dialog');
        const proofPreviewImage = document.getElementById('proof-preview-image');
        let proofPreviewUrl = null;
        let draftSaveTimeout;
        let draftSaveInProgress = false;
        let draftSaveQueued = false;
        let confirmedSubmission = false;
        let currentAttendeePage = 0;
        let nextAttendeeIndex = attendeeForms.querySelectorAll('.attendee-form').length;

        async function saveDraft(keepalive = false) {
            if (draftSaveInProgress) {
                draftSaveQueued = true;
                return;
            }

            draftSaveInProgress = true;
            draftSaveStatus.textContent = 'Saving your draft...';
            const draft = new FormData(document.getElementById('registration-form'));
            draft.delete('_token');
            draft.delete('email');
            draft.delete('proof_of_payment');

            try {
                const response = await fetch(@json(route('registrations.save-draft')), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: draft,
                    keepalive,
                });

                if (!response.ok) {
                    throw new Error(`Draft save failed with status ${response.status}.`);
                }

                draftSaveStatus.textContent = 'Draft saved.';
            } catch (error) {
                console.error(error);
                draftSaveStatus.textContent = 'Draft could not be saved. Check your connection and try again.';
            } finally {
                draftSaveInProgress = false;
                if (draftSaveQueued) {
                    draftSaveQueued = false;
                    saveDraft();
                }
            }
        }

        function scheduleDraftSave() {
            window.clearTimeout(draftSaveTimeout);
            draftSaveTimeout = window.setTimeout(() => saveDraft(), 350);
        }

        function updateOtherShirtSize(attendeeForm) {
            const shirtSize = attendeeForm.querySelector('select[name$="[shirt_size]"]');
            const otherField = attendeeForm.querySelector('.shirt-size-other-field');
            const otherInput = otherField?.querySelector('input');
            const isOther = shirtSize?.value === 'Other';

            if (!otherField || !otherInput) {
                return;
            }

            otherField.hidden = !isOther;
            otherInput.disabled = !isOther;
            otherInput.required = isOther;
        }

        attendeeForms.querySelectorAll('.attendee-form').forEach(updateOtherShirtSize);
        attendeeForms.addEventListener('change', (event) => {
            if (event.target.matches('select[name$="[shirt_size]"]')) {
                updateOtherShirtSize(event.target.closest('.attendee-form'));
            }
        });

        registrationForm.addEventListener('input', scheduleDraftSave);
        registrationForm.addEventListener('change', scheduleDraftSave);
        window.addEventListener('pagehide', () => {
            window.clearTimeout(draftSaveTimeout);
            saveDraft(true);
        });

        function buildConfirmationSummary() {
            confirmationSummary.replaceChildren();

            const addDetail = (container, label, value) => {
                const row = document.createElement('p');
                const strong = document.createElement('strong');
                strong.textContent = `${label}: `;
                row.append(strong, document.createTextNode(value || 'Not provided'));
                container.append(row);
            };

            const submissionSection = document.createElement('section');
            const submissionHeading = document.createElement('h3');
            submissionHeading.textContent = 'Submission details';
            submissionSection.append(submissionHeading);
            addDetail(submissionSection, 'Email', registrationForm.elements.email.value);
            addDetail(submissionSection, 'District', registrationForm.elements.district.selectedOptions[0]?.textContent.trim());
            const proofFile = registrationForm.elements.proof_of_payment.files[0];
            const proofRow = document.createElement('p');
            const proofLabel = document.createElement('strong');
            proofLabel.textContent = 'Proof of payment: ';
            proofRow.append(proofLabel);

            if (proofFile && /\.(jpe?g|png)$/i.test(proofFile.name)) {
                const previewButton = document.createElement('button');
                previewButton.className = 'proof-preview-trigger';
                previewButton.type = 'button';
                previewButton.textContent = proofFile.name;
                previewButton.setAttribute('aria-label', `Preview proof image: ${proofFile.name}`);
                previewButton.addEventListener('click', () => {
                    if (proofPreviewUrl) {
                        URL.revokeObjectURL(proofPreviewUrl);
                    }

                    proofPreviewUrl = URL.createObjectURL(proofFile);
                    proofPreviewImage.src = proofPreviewUrl;
                    proofPreviewImage.alt = `Preview of ${proofFile.name}`;
                    proofPreviewDialog.showModal();
                });
                proofRow.append(previewButton);
            } else {
                proofRow.append(document.createTextNode(proofFile?.name || 'Not provided'));
            }

            submissionSection.append(proofRow);
            confirmationSummary.append(submissionSection);

            attendeeForms.querySelectorAll('.attendee-form').forEach((attendee, index) => {
                const attendeeSection = document.createElement('section');
                const attendeeHeading = document.createElement('h3');
                attendeeHeading.textContent = `Delegate ${index + 1}`;
                attendeeSection.append(attendeeHeading);

                attendee.querySelectorAll('input:not([type="file"]), select').forEach((field) => {
                    const label = attendee.querySelector(`label[for="${field.id}"]`)
                        ?.textContent.replace('*', '').trim() ?? field.name;
                    const value = field instanceof HTMLSelectElement
                        ? field.selectedOptions[0]?.textContent.trim()
                        : field.value.trim();
                    addDetail(attendeeSection, label, value);
                });

                confirmationSummary.append(attendeeSection);
            });
        }

        registrationForm.addEventListener('submit', async (event) => {
            if (confirmedSubmission) {
                confirmedSubmission = false;
                return;
            }

            event.preventDefault();
            const invalidFields = [...registrationForm.querySelectorAll(':invalid')];
            if (invalidFields.length > 0) {
                const firstInvalid = invalidFields[0];
                const attendeeCard = firstInvalid.closest('.attendee-form');
                if (attendeeCard) {
                    currentAttendeePage = [...attendeeForms.querySelectorAll('.attendee-form')].indexOf(attendeeCard);
                    updateAttendeeForms();
                }
                requestAnimationFrame(() => firstInvalid.reportValidity());
                return;
            }

            window.clearTimeout(draftSaveTimeout);
            await saveDraft();
            buildConfirmationSummary();
            confirmationDialog.showModal();
        });

        document.getElementById('edit-registration').addEventListener('click', () => {
            confirmationDialog.close();
        });

        document.getElementById('close-proof-preview').addEventListener('click', () => {
            proofPreviewDialog.close();
        });

        proofPreviewDialog.addEventListener('close', () => {
            if (proofPreviewUrl) {
                URL.revokeObjectURL(proofPreviewUrl);
                proofPreviewUrl = null;
            }

            proofPreviewImage.removeAttribute('src');
        });

        document.getElementById('confirm-registration').addEventListener('click', () => {
            confirmationDialog.close();
            confirmedSubmission = true;
            registrationForm.requestSubmit();
        });

        function updateAttendeeForms() {
            const cards = [...attendeeForms.querySelectorAll('.attendee-form')];
            cards.forEach((card, index) => {
                card.querySelector('.remove-attendee').hidden = cards.length === 1;
                card.hidden = index !== currentAttendeePage;
            });

            document.getElementById('add-attendee').disabled = cards.length >= 50;
            attendeePagination.replaceChildren();

            const addPageButton = (label, page, { active = false, disabled = false, accessibleLabel = label } = {}) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'page-button';
                button.textContent = label;
                button.setAttribute('aria-label', accessibleLabel);
                button.disabled = disabled;

                if (active) {
                    button.classList.add('active');
                    button.setAttribute('aria-current', 'page');
                }

                if (!disabled && !active) {
                    button.dataset.page = String(page);
                }

                attendeePagination.append(button);
            };

            const addEllipsis = () => {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'page-ellipsis';
                ellipsis.textContent = '...';
                attendeePagination.append(ellipsis);
            };

            addPageButton('‹', currentAttendeePage - 1, {
                disabled: currentAttendeePage === 0,
                accessibleLabel: 'Previous delegate',
            });

            const totalPages = cards.length;
            let visiblePages;

            if (totalPages <= 5) {
                visiblePages = Array.from({ length: totalPages }, (_, index) => index);
            } else if (currentAttendeePage <= 1) {
                visiblePages = [0, 1, 'ellipsis', totalPages - 1];
            } else if (currentAttendeePage >= totalPages - 2) {
                visiblePages = [0, 'ellipsis', totalPages - 2, totalPages - 1];
            } else {
                visiblePages = [0, 'ellipsis', currentAttendeePage - 1, currentAttendeePage, currentAttendeePage + 1, 'ellipsis', totalPages - 1];
            }

            visiblePages.forEach((page) => {
                if (page === 'ellipsis') {
                    addEllipsis();
                    return;
                }

                addPageButton(String(page + 1), page, {
                    active: page === currentAttendeePage,
                    accessibleLabel: `Delegate ${page + 1}`,
                });
            });

            addPageButton('›', currentAttendeePage + 1, {
                disabled: currentAttendeePage === totalPages - 1,
                accessibleLabel: 'Next delegate',
            });
        }

        attendeePagination.addEventListener('click', (event) => {
            const pageButton = event.target.closest('button[data-page]');
            if (!pageButton) return;

            currentAttendeePage = Number(pageButton.dataset.page);
            updateAttendeeForms();
        });

        document.getElementById('add-attendee').addEventListener('click', () => {
            const index = nextAttendeeIndex++;
            attendeeForms.insertAdjacentHTML(
                'beforeend',
                attendeeTemplate.innerHTML.replaceAll('__INDEX__', String(index)),
            );
            currentAttendeePage = attendeeForms.querySelectorAll('.attendee-form').length - 1;
            updateAttendeeForms();
            scheduleDraftSave();
            attendeeForms.lastElementChild.querySelector('select').focus();
        });

        attendeeForms.addEventListener('click', (event) => {
            const removeButton = event.target.closest('.remove-attendee');
            if (!removeButton) return;
            const removedIndex = [...attendeeForms.querySelectorAll('.attendee-form')].indexOf(
                removeButton.closest('.attendee-form'),
            );
            removeButton.closest('.attendee-form').remove();

            if (removedIndex < currentAttendeePage) {
                currentAttendeePage--;
            } else if (removedIndex === currentAttendeePage) {
                currentAttendeePage = Math.min(currentAttendeePage, attendeeForms.querySelectorAll('.attendee-form').length - 1);
            }

            updateAttendeeForms();
            scheduleDraftSave();
        });

        updateAttendeeForms();
    </script>
@endpush
