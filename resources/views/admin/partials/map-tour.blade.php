@php
  $tourId = $tourId ?? 'admin-map-tour';
  $tourTitle = $tourTitle ?? 'Map Guide';
  $tourIntro = $tourIntro ?? 'Follow the guided steps to understand this map page.';
  $tourSteps = is_array($tourSteps ?? null) ? $tourSteps : [];
  $tourButtonLabel = $tourButtonLabel ?? 'Map Guide';
  $tourButtonClass = $tourButtonClass ?? 'btn btn-outline-primary';
  $tourData = [
      'intro' => $tourIntro,
      'steps' => array_values(array_map(static function ($step) {
          return [
              'title' => (string) ($step['title'] ?? 'Step'),
              'body' => (string) ($step['body'] ?? ''),
              'selector' => isset($step['selector']) ? (string) $step['selector'] : '',
          ];
      }, $tourSteps)),
  ];
  $tourDataJson = json_encode($tourData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
@endphp

<button
  type="button"
  class="{{ $tourButtonClass }}"
  data-map-tour-trigger="{{ $tourId }}"
  aria-controls="{{ $tourId }}Modal">
  @include('partials.curated-shell-icon', [
    'src' => 'resources/icons/dusk/location/animated/icons8-compass--v2.gif',
    'alt' => $tourButtonLabel,
    'classes' => 'map-tour-trigger-icon me-1',
  ]){{ $tourButtonLabel }}
</button>

<div class="modal fade" id="{{ $tourId }}Modal" tabindex="-1" aria-labelledby="{{ $tourId }}Label" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <div class="small text-body-secondary">Admin guide</div>
          <h5 class="modal-title mb-0" id="{{ $tourId }}Label">{{ $tourTitle }}</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="small text-body-secondary mb-3" data-map-tour-intro="{{ $tourId }}"></div>
        <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
          <div class="badge bg-label-primary rounded-pill" data-map-tour-progress="{{ $tourId }}">Step 1 of 1</div>
          <button type="button" class="btn btn-sm btn-label-secondary" data-map-tour-focus="{{ $tourId }}">Focus Area</button>
        </div>
        <h6 class="mb-2" data-map-tour-step-title="{{ $tourId }}"></h6>
        <p class="mb-0 text-body-secondary" data-map-tour-step-body="{{ $tourId }}"></p>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <button type="button" class="btn btn-outline-secondary" data-map-tour-prev="{{ $tourId }}">Previous</button>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-primary" data-map-tour-next="{{ $tourId }}">Next</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script id="{{ $tourId }}_payload" type="application/json">{!! $tourDataJson !!}</script>

@once
  <style>
    .map-tour-trigger-icon {
      width: 1rem;
      height: 1rem;
      display: inline-block;
      flex-shrink: 0;
    }

    .map-tour-highlight {
      position: relative;
      z-index: 2;
      outline: 3px solid rgba(105, 108, 255, 0.45);
      outline-offset: 4px;
      border-radius: 1rem;
      transition: outline-color 0.2s ease, box-shadow 0.2s ease;
      box-shadow: 0 0 0 0.35rem rgba(105, 108, 255, 0.12);
    }
  </style>
  <script>
    (function() {
      if (window.__adminMapTourInitialized) {
        return;
      }
      window.__adminMapTourInitialized = true;

      function parseJson(text) {
        try {
          return JSON.parse(text || '{}');
        } catch (error) {
          return {};
        }
      }

      function createTourController(tourId) {
        var trigger = document.querySelector('[data-map-tour-trigger="' + tourId + '"]');
        var modalElement = document.getElementById(tourId + 'Modal');
        var payloadElement = document.getElementById(tourId + '_payload');
        if (!trigger || !modalElement || !payloadElement || !window.bootstrap) {
          return;
        }

        var payload = parseJson(payloadElement.textContent);
        var steps = Array.isArray(payload.steps) ? payload.steps : [];
        if (steps.length === 0) {
          return;
        }

        var modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
        var introNode = modalElement.querySelector('[data-map-tour-intro="' + tourId + '"]');
        var progressNode = modalElement.querySelector('[data-map-tour-progress="' + tourId + '"]');
        var titleNode = modalElement.querySelector('[data-map-tour-step-title="' + tourId + '"]');
        var bodyNode = modalElement.querySelector('[data-map-tour-step-body="' + tourId + '"]');
        var prevButton = modalElement.querySelector('[data-map-tour-prev="' + tourId + '"]');
        var nextButton = modalElement.querySelector('[data-map-tour-next="' + tourId + '"]');
        var focusButton = modalElement.querySelector('[data-map-tour-focus="' + tourId + '"]');
        var activeTarget = null;
        var stepIndex = 0;

        function clearHighlight() {
          if (activeTarget) {
            activeTarget.classList.remove('map-tour-highlight');
            activeTarget = null;
          }
        }

        function focusSelector(selector) {
          clearHighlight();
          if (!selector) {
            return;
          }

          var target = document.querySelector(selector);
          if (!target) {
            return;
          }

          activeTarget = target;
          activeTarget.classList.add('map-tour-highlight');
          activeTarget.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        }

        function renderStep() {
          var step = steps[stepIndex] || steps[0];
          if (introNode) {
            introNode.textContent = String(payload.intro || '');
          }
          if (progressNode) {
            progressNode.textContent = 'Step ' + (stepIndex + 1) + ' of ' + steps.length;
          }
          if (titleNode) {
            titleNode.textContent = String(step.title || 'Step');
          }
          if (bodyNode) {
            bodyNode.textContent = String(step.body || '');
          }
          if (prevButton) {
            prevButton.disabled = stepIndex === 0;
          }
          if (nextButton) {
            nextButton.textContent = stepIndex === steps.length - 1 ? 'Finish' : 'Next';
          }
          focusSelector(step.selector || '');
        }

        trigger.addEventListener('click', function() {
          stepIndex = 0;
          modal.show();
          window.setTimeout(renderStep, 120);
        });

        if (prevButton) {
          prevButton.addEventListener('click', function() {
            if (stepIndex === 0) {
              return;
            }
            stepIndex -= 1;
            renderStep();
          });
        }

        if (nextButton) {
          nextButton.addEventListener('click', function() {
            if (stepIndex >= steps.length - 1) {
              modal.hide();
              return;
            }
            stepIndex += 1;
            renderStep();
          });
        }

        if (focusButton) {
          focusButton.addEventListener('click', function() {
            var step = steps[stepIndex] || steps[0];
            focusSelector(step.selector || '');
          });
        }

        modalElement.addEventListener('hidden.bs.modal', function() {
          clearHighlight();
        });
      }

      document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-map-tour-trigger]').forEach(function(trigger) {
          var tourId = trigger.getAttribute('data-map-tour-trigger');
          if (tourId) {
            createTourController(tourId);
          }
        });
      });
    })();
  </script>
@endonce
