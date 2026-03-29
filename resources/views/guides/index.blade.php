@extends('layouts.admin')

@section('title', 'APEWSD - Guide Center')

@section('content')
  @php
    $tracks = is_array($guideTracks ?? null) ? $guideTracks : [];
    $trackKeys = array_keys($tracks);
    $resolvedDefaultTrack = in_array($defaultTrackKey ?? null, $trackKeys, true)
      ? $defaultTrackKey
      : ($trackKeys[0] ?? null);
  @endphp

  <div class="row mb-4">
    <div class="col-12">
      <h4 class="mb-1">Guide Center</h4>
      <p class="mb-0 text-body-secondary">
        Follow the checklist for your role. Use "Open page" buttons to jump directly to each required screen.
      </p>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header">
      <h5 class="mb-1">Premises Layers (Easy View)</h5>
      <p class="mb-0 text-body-secondary">
        The system checks these layers together: General Geofence, User Premises, and Farm Fence.
      </p>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-12 col-lg-4">
          <div class="border rounded p-3 h-100">
            <h6 class="mb-2">1) General Geofence</h6>
            <p class="text-body-secondary mb-2">Main allowed perimeter for location-aware access control.</p>
            @if ($isAdminViewer)
              <a href="{{ route('admin.settings.edit') }}" class="btn btn-sm btn-outline-primary">Open Settings</a>
            @else
              <span class="badge bg-label-secondary">Admin-only action</span>
            @endif
          </div>
        </div>
        <div class="col-12 col-lg-4">
          <div class="border rounded p-3 h-100">
            <h6 class="mb-2">2) User Premises</h6>
            <p class="text-body-secondary mb-2">Per-user area limit applied to non-admin users.</p>
            @if ($isAdminViewer)
              <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-primary">Open User List</a>
            @else
              <span class="badge bg-label-secondary">Ask admin to configure</span>
            @endif
          </div>
        </div>
        <div class="col-12 col-lg-4">
          <div class="border rounded p-3 h-100">
            <h6 class="mb-2">3) Farm Fence</h6>
            <p class="text-body-secondary mb-2">Farm-specific perimeter used for farm map and assignment checks.</p>
            @if ($isAdminViewer)
              <a href="{{ route('admin.maps.farms') }}" class="btn btn-sm btn-outline-primary">Open Farm &amp; Map</a>
            @else
              <span class="badge bg-label-secondary">Ask admin to configure</span>
            @endif
          </div>
        </div>
      </div>
    </div>
  </div>

  @if ($tracks === [])
    <div class="alert alert-warning mb-4" role="alert">
      No guide tracks are currently available for this role.
    </div>
  @else
    @if ($isAdminViewer && count($tracks) > 1)
      <div class="card mb-4">
        <div class="card-body py-3">
          <div class="d-flex flex-wrap gap-2" role="tablist" aria-label="Guide tracks">
            @foreach ($tracks as $trackKey => $track)
              <button
                type="button"
                class="btn btn-sm btn-outline-primary guide-track-tab"
                data-track-target="{{ $trackKey }}"
                @if ($trackKey === $resolvedDefaultTrack) data-default-track="1" @endif>
                {{ $track['label'] ?? ucfirst($trackKey) }}
              </button>
            @endforeach
          </div>
        </div>
      </div>
    @endif

    @foreach ($tracks as $trackKey => $track)
      @php
        $steps = is_array($track['steps'] ?? null) ? $track['steps'] : [];
        $notes = is_array($track['notes'] ?? null) ? $track['notes'] : [];
      @endphp
      <div
        class="card mb-4 guide-track-panel"
        data-track-panel="{{ $trackKey }}"
        @if ($trackKey !== $resolvedDefaultTrack) style="display:none;" @endif>
        <div class="card-header d-flex flex-wrap justify-content-between gap-3">
          <div>
            <h5 class="mb-1">{{ $track['label'] ?? ucfirst($trackKey) }}</h5>
            <p class="mb-0 text-body-secondary">{{ $track['summary'] ?? '' }}</p>
          </div>
          <div class="text-lg-end">
            <div class="badge bg-label-primary mb-2">{{ $track['audience'] ?? 'User' }}</div>
            <div class="small text-body-secondary mb-2">
              Progress: <span data-progress-label>0 / 0 complete</span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-reset-track="{{ $trackKey }}">
              Reset checklist
            </button>
          </div>
        </div>
        <div class="card-body">
          <div class="progress mb-3" style="height: 8px;">
            <div class="progress-bar" role="progressbar" data-progress-bar style="width: 0%"></div>
          </div>

          <div class="row g-3">
            @foreach ($steps as $step)
              @php
                $stepId = (string) ($step['id'] ?? ('step-' . $loop->index));
                $stepCheckboxId = 'guide_' . $trackKey . '_' . $stepId;
              @endphp
              <div class="col-12">
                <div class="border rounded p-3 guide-step-card" data-step-card="{{ $stepId }}">
                  <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
                    <div class="form-check">
                      <input
                        class="form-check-input guide-step-checkbox"
                        type="checkbox"
                        id="{{ $stepCheckboxId }}"
                        data-track="{{ $trackKey }}"
                        data-step="{{ $stepId }}">
                      <label class="form-check-label fw-semibold" for="{{ $stepCheckboxId }}">
                        {{ $loop->iteration }}. {{ $step['title'] ?? 'Step' }}
                      </label>
                    </div>

                    @if (!empty($step['url']))
                      <a href="{{ $step['url'] }}" class="btn btn-sm btn-outline-primary">Open page</a>
                    @else
                      <span class="badge bg-label-secondary">Ask admin</span>
                    @endif
                  </div>

                  <p class="text-body-secondary mt-2 mb-2">{{ $step['description'] ?? '' }}</p>
                  <p class="mb-0"><strong>Do this:</strong> {{ $step['action'] ?? '' }}</p>
                </div>
              </div>
            @endforeach
          </div>

          @if ($notes !== [])
            <hr class="my-4" />
            <h6 class="mb-2">Notes</h6>
            <ul class="mb-0 text-body-secondary">
              @foreach ($notes as $note)
                <li>{{ $note }}</li>
              @endforeach
            </ul>
          @endif
        </div>
      </div>
    @endforeach
  @endif

  <div class="card">
    <div class="card-header">
      <h5 class="mb-0">Common Errors And Fixes</h5>
    </div>
    <div class="card-body">
      <div class="row g-3">
        @foreach ($commonIssues as $issue)
          <div class="col-12 col-lg-6">
            <div class="border rounded p-3 h-100">
              <h6 class="mb-1">{{ $issue['title'] ?? 'Issue' }}</h6>
              <p class="text-body-secondary mb-0">{{ $issue['detail'] ?? '' }}</p>
            </div>
          </div>
        @endforeach
      </div>
    </div>
  </div>

  <style>
    .guide-step-card.is-complete {
      border-color: rgba(40, 199, 111, 0.55);
      background-color: rgba(40, 199, 111, 0.08);
    }
  </style>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      function storageKey(trackKey) {
        return 'guide_center_progress_' + trackKey;
      }

      function readProgress(trackKey) {
        try {
          const raw = window.localStorage.getItem(storageKey(trackKey));
          const parsed = raw ? JSON.parse(raw) : {};
          return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
          return {};
        }
      }

      function saveProgress(trackKey, progressMap) {
        window.localStorage.setItem(storageKey(trackKey), JSON.stringify(progressMap));
      }

      function initTrackPanel(panel) {
        const trackKey = String(panel.getAttribute('data-track-panel') || '');
        if (trackKey === '') {
          return;
        }

        const checkboxes = Array.from(panel.querySelectorAll('.guide-step-checkbox[data-track="' + trackKey + '"]'));
        const progressLabel = panel.querySelector('[data-progress-label]');
        const progressBar = panel.querySelector('[data-progress-bar]');
        const resetButton = panel.querySelector('[data-reset-track="' + trackKey + '"]');
        const stored = readProgress(trackKey);

        checkboxes.forEach(function (checkbox) {
          const stepKey = String(checkbox.getAttribute('data-step') || '');
          checkbox.checked = Boolean(stored[stepKey]);
        });

        function syncTrackState() {
          const total = checkboxes.length;
          let complete = 0;
          const nextState = {};

          checkboxes.forEach(function (checkbox) {
            const stepKey = String(checkbox.getAttribute('data-step') || '');
            const card = checkbox.closest('[data-step-card]');
            if (checkbox.checked) {
              complete += 1;
            }

            if (stepKey !== '') {
              nextState[stepKey] = checkbox.checked;
            }

            if (card) {
              card.classList.toggle('is-complete', checkbox.checked);
            }
          });

          if (progressLabel) {
            progressLabel.textContent = complete + ' / ' + total + ' complete';
          }
          if (progressBar) {
            const percent = total > 0 ? Math.round((complete / total) * 100) : 0;
            progressBar.style.width = percent + '%';
            progressBar.setAttribute('aria-valuenow', String(percent));
          }

          saveProgress(trackKey, nextState);
        }

        checkboxes.forEach(function (checkbox) {
          checkbox.addEventListener('change', syncTrackState);
        });

        if (resetButton) {
          resetButton.addEventListener('click', function () {
            window.localStorage.removeItem(storageKey(trackKey));
            checkboxes.forEach(function (checkbox) {
              checkbox.checked = false;
            });
            syncTrackState();
          });
        }

        syncTrackState();
      }

      function initTrackTabs() {
        const tabButtons = Array.from(document.querySelectorAll('.guide-track-tab[data-track-target]'));
        const panels = Array.from(document.querySelectorAll('.guide-track-panel[data-track-panel]'));
        if (tabButtons.length === 0 || panels.length === 0) {
          return;
        }

        function setActiveTrack(trackKey) {
          tabButtons.forEach(function (button) {
            const isActive = String(button.getAttribute('data-track-target')) === trackKey;
            button.classList.toggle('btn-primary', isActive);
            button.classList.toggle('btn-outline-primary', !isActive);
          });

          panels.forEach(function (panel) {
            panel.style.display = String(panel.getAttribute('data-track-panel')) === trackKey ? '' : 'none';
          });
        }

        tabButtons.forEach(function (button) {
          button.addEventListener('click', function () {
            setActiveTrack(String(button.getAttribute('data-track-target')));
          });
        });

        const defaultButton = tabButtons.find(function (button) {
          return button.getAttribute('data-default-track') === '1';
        }) || tabButtons[0];
        setActiveTrack(String(defaultButton.getAttribute('data-track-target') || ''));
      }

      Array.from(document.querySelectorAll('.guide-track-panel[data-track-panel]')).forEach(initTrackPanel);
      initTrackTabs();
    });
  </script>
@endsection
