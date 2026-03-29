'use strict';

(function () {
    const form = document.getElementById('multi-upload-form');
    const statusBox = document.getElementById('upload-status');
    const recentUploadsContainer = document.getElementById('recent-uploads');
    const monthlyUploadsContainer = document.getElementById('monthly-uploads');
    const latitudeInput = document.getElementById('locationLatitude');
    const longitudeInput = document.getElementById('locationLongitude');
    const locationStatus = document.getElementById('location-status');
    const studentNoInput = document.getElementById('studentNo');
    const studentNameInput = document.getElementById('studentName');
    const notesInput = document.getElementById('notes');
    const notesCounter = document.getElementById('notesCounter');
    const NOTES_MIN = 50;
    const NOTES_MAX = 200;

    if (!form) {
        return;
    }

    // Wizard/tab handling (Bootstrap 5 tabs)
    const wizardTabs = {
        step1: document.getElementById('step1-tab'),
        step2: document.getElementById('step2-tab'),
        step3: document.getElementById('step3-tab'),
    };

    const panes = {
        step1: document.getElementById('step1'),
        step2: document.getElementById('step2'),
        step3: document.getElementById('step3'),
    };

    const setPaneDisabled = (pane, disabled) => {
        if (!pane) return;
        pane.querySelectorAll('input, select, textarea, button').forEach((el) => {
            // Don't disable the wizard navigation buttons inside panes
            if (el.matches('[data-wizard-next], [data-wizard-prev]')) return;
            // Never disable submit button (step3 submit) once reached
            if (el.type === 'submit') return;
            if (disabled) {
                el.setAttribute('disabled', 'disabled');
            } else {
                el.removeAttribute('disabled');
            }
        });
    };

    const showStep = (stepKey) => {
        const tabEl = wizardTabs[stepKey];
        if (!tabEl || typeof bootstrap === 'undefined' || !bootstrap.Tab) return;
        bootstrap.Tab.getOrCreateInstance(tabEl).show();
    };

    // Disable future steps initially so built-in validation doesn't fail on hidden fields.
    setPaneDisabled(panes.step2, true);
    setPaneDisabled(panes.step3, true);

    // Next buttons
    document.querySelectorAll('[data-wizard-next]').forEach((btn) => {
        btn.addEventListener('click', () => {
            // Validate currently enabled fields only (future steps are disabled).
            if (!form.reportValidity()) return;

            const next = btn.getAttribute('data-wizard-next');
            if (next === 'step2') {
                setPaneDisabled(panes.step2, false);
                showStep('step2');
            } else if (next === 'step3') {
                setPaneDisabled(panes.step3, false);
                showStep('step3');
            }
        });
    });

    // Prev buttons
    document.querySelectorAll('[data-wizard-prev]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const prev = btn.getAttribute('data-wizard-prev');
            if (prev === 'step1') showStep('step1');
            if (prev === 'step2') showStep('step2');
        });
    });

    // Lock student info inputs (they are pre-filled server-side)
    if (studentNoInput) {
        studentNoInput.setAttribute('readonly', 'readonly');
    }
    if (studentNameInput) {
        studentNameInput.setAttribute('readonly', 'readonly');
    }

    // Live note character counter and enforcement
    const updateNotesCounter = () => {
        if (!notesInput || !notesCounter) return;
        if (notesInput.value.length > NOTES_MAX) {
            notesInput.value = notesInput.value.substring(0, NOTES_MAX);
        }
        notesCounter.textContent = `${notesInput.value.length} / ${NOTES_MAX}`;
    };

    if (notesInput) {
        notesInput.addEventListener('input', updateNotesCounter);
        updateNotesCounter();
    }

    if (latitudeInput && longitudeInput && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                latitudeInput.value = position.coords.latitude.toFixed(6);
                longitudeInput.value = position.coords.longitude.toFixed(6);
                if (locationStatus) {
                    locationStatus.textContent = 'Location detected automatically.';
                    locationStatus.classList.remove('text-danger');
                    locationStatus.classList.add('text-success');
                }
            },
            () => {
                if (locationStatus) {
                    locationStatus.textContent = 'Unable to detect location automatically. You may enter it manually if needed.';
                    locationStatus.classList.remove('text-success');
                    locationStatus.classList.add('text-danger');
                }
                latitudeInput.removeAttribute('readonly');
                longitudeInput.removeAttribute('readonly');
            }
        );
    } else if (locationStatus) {
        locationStatus.textContent = 'Geolocation not supported. Please enter coordinates manually if required.';
        locationStatus.classList.add('text-danger');
        latitudeInput?.removeAttribute('readonly');
        longitudeInput?.removeAttribute('readonly');
    }

    const showStatus = (message, type) => {
        statusBox.textContent = message;
        statusBox.className = `alert alert-${type} mt-3`;
        statusBox.classList.remove('d-none');
    };

    const renderUploads = (files) => {
        if (!recentUploadsContainer) return;
        if (!files || files.length === 0) {
            recentUploadsContainer.innerHTML = '<p class="text-muted mb-0">No files were uploaded.</p>';
            return;
        }

        const listItems = files.map((file) => {
            const sizeInKb = (file.file_size / 1024).toFixed(1);
            return `<li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>${file.original_name}</span>
                        <span class="badge bg-primary rounded-pill">${sizeInKb} KB</span>
                    </li>`;
        }).join('');

        recentUploadsContainer.innerHTML = `<ul class="list-group list-group-flush">${listItems}</ul>`;
    };

    const renderMonthlyUploads = (files) => {
        const tableBody = monthlyUploadsContainer.querySelector('tbody');
        if (!tableBody) return;

        if (!files || files.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">No files uploaded this month.</td></tr>';
            return;
        }

        const rows = files.map((file) => {
            const sizeInKb = (file.file_size / 1024).toFixed(1);
            const date = new Date(file.created_at).toLocaleDateString();
            return `<tr>
                        <td>${file.original_name}</td>
                        <td>${date}</td>
                        <td>${sizeInKb} KB</td>
                    </tr>`;
        }).join('');

        tableBody.innerHTML = rows;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        statusBox.classList.add('d-none');
        if (recentUploadsContainer) {
            recentUploadsContainer.innerHTML = '<p class="text-muted mb-0">Uploading...</p>';
        }

            if (notesInput) {
                const len = notesInput.value.trim().length;
                if (len < NOTES_MIN || len > NOTES_MAX) {
                    showStatus(`Note must be between ${NOTES_MIN} and ${NOTES_MAX} characters.`, 'danger');
                    if (recentUploadsContainer) {
                        recentUploadsContainer.innerHTML = '<p class="text-muted mb-0">Fix the issues above and try again.</p>';
                    }
                    return;
                }
            }

        const formData = new FormData(form);

        try {
            const response = await fetch('includes/upload_multiple.php', {
                method: 'POST',
                body: formData,
            });

            let result;
            try {
                result = await response.json();
            } catch (e) {
                const text = await response.text();
                showStatus('Error: ' + text.substring(0, 200), 'danger'); // Limit to 200 chars
                if (recentUploadsContainer) {
                    recentUploadsContainer.innerHTML = '<p class="text-muted mb-0">Fix the issues above and try again.</p>';
                }
                return;
            }

            if (!response.ok || result.status !== 'success') {
                const errorMessages = result.errors && result.errors.length
                    ? ` Details: ${result.errors.join(' ')}`
                    : '';
                showStatus(result.message + errorMessages, 'danger');
                if (recentUploadsContainer) {
                    recentUploadsContainer.innerHTML = '<p class="text-muted mb-0">Fix the issues above and try again.</p>';
                }
                return;
            }

            showStatus(result.message, 'success');
            renderUploads(result.uploaded);
            fetchMonthlyUploads();
            form.reset();
            updateNotesCounter();

            // Reset wizard back to step 1 and disable future steps again.
            setPaneDisabled(panes.step2, true);
            setPaneDisabled(panes.step3, true);
            showStep('step1');
        } catch (error) {
            showStatus('Network error occurred while uploading files.', 'danger');
            if (recentUploadsContainer) {
                recentUploadsContainer.innerHTML = '<p class="text-muted mb-0">Please try again later.</p>';
            }
        }
    });

    const fetchMonthlyUploads = async () => {
        try {
            const response = await fetch('get_monthly_uploads.php');
            const files = await response.json();
            renderMonthlyUploads(files);
        } catch (error) {
            if (monthlyUploadsContainer) {
                monthlyUploadsContainer.innerHTML = '<p class="text-danger mb-0">Could not load monthly uploads.</p>';
            }
        }
    };

    fetchMonthlyUploads();
})();
