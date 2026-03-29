@extends('layouts.admin')

@section('title', 'Machine Blueprint')

@php
  $deviceBlueprintPath = resource_path('images/device.png');
  $deviceBlueprintSrc = file_exists($deviceBlueprintPath)
      ? 'data:image/png;base64,' . base64_encode(file_get_contents($deviceBlueprintPath))
      : null;
@endphp

@push('styles')
  <style>
    .machine-blueprint-hero {
      position: relative;
      overflow: hidden;
      border: 1px solid rgba(67, 89, 113, 0.12);
      background:
        linear-gradient(180deg, rgba(211, 234, 242, 0.55), rgba(241, 246, 250, 0.96)),
        linear-gradient(90deg, rgba(91, 141, 239, 0.04) 1px, transparent 1px),
        linear-gradient(rgba(91, 141, 239, 0.04) 1px, transparent 1px);
      background-size: auto, 24px 24px, 24px 24px;
      border-radius: 1.25rem;
      box-shadow: 0 22px 44px rgba(18, 38, 63, 0.08);
    }

    .machine-blueprint-copy {
      max-width: 50rem;
    }

    .machine-blueprint-frame {
      position: relative;
      width: 100%;
      border-radius: 1rem;
      overflow: hidden;
      background: rgba(255, 255, 255, 0.4);
      border: 1px solid rgba(67, 89, 113, 0.12);
    }

    .machine-blueprint-image {
      display: block;
      width: 100%;
      height: auto;
    }

    .machine-blueprint-image-fallback {
      padding: 4rem 1.5rem;
      text-align: center;
      color: #6c7a8b;
      background: rgba(255, 255, 255, 0.7);
    }

    .machine-blueprint-overlay {
      position: absolute;
      inset: 0;
      pointer-events: none;
    }

    .machine-blueprint-svg {
      display: block;
      width: 100%;
      height: 100%;
      overflow: visible;
    }

    .machine-blueprint-annotation {
      --callout-color: #ff1f1f;
      --point-delay: 0s;
      --line-delay: .34s;
      --label-delay: 1s;
      color: var(--callout-color);
    }

    .machine-blueprint-annotation-point {
      fill: var(--callout-color);
      stroke: rgba(255, 255, 255, 0.95);
      stroke-width: 4;
      opacity: 0;
      transform-box: fill-box;
      transform-origin: center;
      transform: scale(0.35);
      animation: machine-blueprint-point-in .34s ease-out both;
      animation-delay: var(--point-delay);
    }

    .machine-blueprint-annotation-pulse {
      fill: none;
      stroke: color-mix(in srgb, var(--callout-color) 34%, transparent);
      stroke-width: 10;
      opacity: 0;
      animation: machine-blueprint-pulse-in .5s ease-out both;
      animation-delay: calc(var(--point-delay) + .06s);
    }

    .machine-blueprint-annotation-line {
      fill: none;
      stroke: var(--callout-color);
      stroke-width: 4.5;
      stroke-linecap: round;
      stroke-linejoin: round;
      pathLength: 1;
      stroke-dasharray: 1;
      stroke-dashoffset: 1;
      opacity: 0;
      animation: machine-blueprint-svg-draw .72s ease-out both;
      animation-delay: var(--line-delay);
    }

    .machine-blueprint-annotation-label {
      opacity: 0;
      transform-box: fill-box;
      transform-origin: center;
      transform: translateY(10px);
      animation: machine-blueprint-label-in .36s ease-out both;
      animation-delay: var(--label-delay);
    }

    .machine-blueprint-annotation-label-box {
      fill: rgba(255, 255, 255, 0.96);
      stroke: var(--callout-color);
      stroke-width: 2.5;
    }

    .machine-blueprint-annotation-label-text {
      fill: #1f2d3d;
      font-size: 15px;
      font-weight: 600;
      font-family: "Public Sans", "Figtree", sans-serif;
    }

    .machine-blueprint-annotation-label-text tspan.accent {
      fill: var(--callout-color);
    }

    @keyframes machine-blueprint-point-in {
      from {
        opacity: 0;
        transform: scale(0.35);
      }

      to {
        opacity: 1;
        transform: scale(1);
      }
    }

    @keyframes machine-blueprint-pulse-in {
      from {
        opacity: 0;
        transform: scale(0.4);
      }

      to {
        opacity: 1;
        transform: scale(1);
      }
    }

    @keyframes machine-blueprint-svg-draw {
      from {
        stroke-dashoffset: 1;
        opacity: 0;
      }

      to {
        stroke-dashoffset: 0;
        opacity: 1;
      }
    }

    @keyframes machine-blueprint-label-in {
      from {
        opacity: 0;
        transform: translateY(10px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @media (max-width: 991.98px) {
      .machine-blueprint-annotation-label-text {
        font-size: 14px;
      }
    }

    @media (max-width: 575.98px) {
      .machine-blueprint-annotation-label-text {
        font-size: 13px;
      }
    }

    .machine-blueprint-legend {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
      gap: 1rem;
    }

    .machine-blueprint-legend-item {
      padding: 1rem;
      border-radius: 1rem;
      border: 1px solid rgba(67, 89, 113, 0.12);
      background: #fff;
      box-shadow: 0 10px 24px rgba(18, 38, 63, 0.06);
    }

    .machine-blueprint-swatch {
      width: .9rem;
      height: .9rem;
      border-radius: 999px;
      display: inline-block;
      margin-right: .5rem;
      vertical-align: middle;
      border: 1px solid rgba(0, 0, 0, 0.08);
    }

    .machine-blueprint-notes {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
      gap: 1rem;
    }

    .machine-blueprint-note {
      padding: 1rem 1.1rem;
      border-radius: 1rem;
      background: linear-gradient(180deg, #fff, #f7f9fc);
      border: 1px solid rgba(67, 89, 113, 0.12);
    }
  </style>
@endpush

@section('content')
  <div class="row g-4">
    <div class="col-12">
      <section class="card machine-blueprint-hero">
        <div class="card-body p-4 p-lg-5">
          <div class="machine-blueprint-copy mb-4">
            <span class="badge bg-label-primary mb-2">Machine Layout</span>
            <h3 class="mb-2">Automated Egg Weighing and Sorting Device Blueprint</h3>
            <p class="text-body-secondary mb-0">
              This page presents the actual device image stored in the project so the machine layout can be reviewed
              directly from the system without relying on a recreated schematic.
            </p>
          </div>

          <div class="machine-blueprint-frame">
            @if ($deviceBlueprintSrc)
              <img src="{{ $deviceBlueprintSrc }}" alt="Actual egg sorting device image" class="machine-blueprint-image" />
              <div class="machine-blueprint-overlay" aria-hidden="true">
                <svg viewBox="0 0 1296 674" class="machine-blueprint-svg">
                  <defs>
                    <filter id="machineBlueprintLabelShadow" x="-20%" y="-40%" width="140%" height="180%">
                      <feDropShadow dx="0" dy="8" stdDeviation="10" flood-color="#12263f" flood-opacity="0.16" />
                    </filter>
                  </defs>

                  <g class="machine-blueprint-annotation" style="--callout-color:#ff1f1f; --point-delay:.25s; --line-delay:.66s; --label-delay:1.5s;">
                    <circle class="machine-blueprint-annotation-pulse" cx="970" cy="96" r="12"></circle>
                    <circle class="machine-blueprint-annotation-point" cx="970" cy="96" r="9"></circle>
                    <path class="machine-blueprint-annotation-line" d="M970 96 L970 154 L290 154"></path>
                    <g class="machine-blueprint-annotation-label" filter="url(#machineBlueprintLabelShadow)">
                      <rect class="machine-blueprint-annotation-label-box" x="92" y="130" rx="24" ry="24" width="182" height="48"></rect>
                      <text class="machine-blueprint-annotation-label-text" x="114" y="161">Que Ramp</text>
                    </g>
                  </g>

                  <g class="machine-blueprint-annotation" style="--callout-color:#24df00; --point-delay:1.95s; --line-delay:2.36s; --label-delay:3.2s;">
                    <circle class="machine-blueprint-annotation-pulse" cx="1120" cy="165" r="12"></circle>
                    <circle class="machine-blueprint-annotation-point" cx="1120" cy="165" r="9"></circle>
                    <path class="machine-blueprint-annotation-line" d="M1120 165 L1120 204"></path>
                    <g class="machine-blueprint-annotation-label" filter="url(#machineBlueprintLabelShadow)">
                      <rect class="machine-blueprint-annotation-label-box" x="968" y="216" rx="24" ry="24" width="264" height="48"></rect>
                      <text class="machine-blueprint-annotation-label-text" x="988" y="247">Weighing Section</text>
                    </g>
                  </g>

                  <g class="machine-blueprint-annotation" style="--callout-color:#6b1fe0; --point-delay:3.65s; --line-delay:4.06s; --label-delay:4.9s;">
                    <circle class="machine-blueprint-annotation-pulse" cx="450" cy="302" r="12"></circle>
                    <circle class="machine-blueprint-annotation-point" cx="450" cy="302" r="9"></circle>
                    <path class="machine-blueprint-annotation-line" d="M450 302 L610 302 L610 262"></path>
                    <g class="machine-blueprint-annotation-label" filter="url(#machineBlueprintLabelShadow)">
                      <rect class="machine-blueprint-annotation-label-box" x="528" y="214" rx="24" ry="24" width="156" height="48"></rect>
                      <text class="machine-blueprint-annotation-label-text" x="562" y="245">Chute</text>
                    </g>
                  </g>

                  <g class="machine-blueprint-annotation" style="--callout-color:#ffd400; --point-delay:5.35s; --line-delay:5.76s; --label-delay:6.6s;">
                    <circle class="machine-blueprint-annotation-pulse" cx="205" cy="478" r="12"></circle>
                    <circle class="machine-blueprint-annotation-point" cx="205" cy="478" r="9"></circle>
                    <path class="machine-blueprint-annotation-line" d="M205 478 L205 548 L92 548"></path>
                    <g class="machine-blueprint-annotation-label" filter="url(#machineBlueprintLabelShadow)">
                      <rect class="machine-blueprint-annotation-label-box" x="52" y="556" rx="28" ry="28" width="632" height="74"></rect>
                      <text class="machine-blueprint-annotation-label-text" x="74" y="590">Section Bins</text>
                      <text class="machine-blueprint-annotation-label-text" x="74" y="620">
                        <tspan>(Reject, Jumbo, Extra-Large, Large, Medium, Small, Pullet, Pewee)</tspan>
                      </text>
                    </g>
                  </g>
                </svg>
              </div>
            @else
              <div class="machine-blueprint-image-fallback">
                The device image could not be loaded from <code>{{ $deviceBlueprintPath }}</code>.
              </div>
            @endif
          </div>
        </div>
      </section>
    </div>

    <div class="col-12">
      <section class="machine-blueprint-legend">
        <article class="machine-blueprint-legend-item">
          <h6 class="mb-2">Color Legend</h6>
          <div class="text-body-secondary small"><span class="machine-blueprint-swatch" style="background:#ff1f1f;"></span>Que Ramp</div>
          <div class="text-body-secondary small mt-2"><span class="machine-blueprint-swatch" style="background:#24df00;"></span>Weighing Section</div>
          <div class="text-body-secondary small mt-2"><span class="machine-blueprint-swatch" style="background:#6b1fe0;"></span>Chute</div>
          <div class="text-body-secondary small mt-2"><span class="machine-blueprint-swatch" style="background:#ffd400;"></span>Section Bins</div>
        </article>

        <article class="machine-blueprint-legend-item">
          <h6 class="mb-2">Sorting Bins</h6>
          <p class="text-body-secondary small mb-0">
            Reject, Jumbo, Extra-Large, Large, Medium, Small, Pullet, and Pewee are grouped under the highlighted
            section bins call-out for presentation and device orientation.
          </p>
        </article>

        <article class="machine-blueprint-legend-item">
          <h6 class="mb-2">Use Case</h6>
          <p class="text-body-secondary small mb-0">
            This page can be used as a presentation, documentation, or operator reference view when explaining the
            physical device layout to poultry owners, staff, and evaluators.
          </p>
        </article>
      </section>
    </div>

    <div class="col-12">
      <section class="machine-blueprint-notes">
        <article class="machine-blueprint-note">
          <h6 class="mb-1">Upper Conveyor Feed</h6>
          <p class="text-body-secondary small mb-0">
            The inclined double-rail lane guides eggs from the entry point toward the weighing section while limiting
            lateral movement.
          </p>
        </article>
        <article class="machine-blueprint-note">
          <h6 class="mb-1">Weighing Chamber</h6>
          <p class="text-body-secondary small mb-0">
            The rectangular middle chamber represents the measurement zone where the load-cell based reading is captured
            before classification.
          </p>
        </article>
        <article class="machine-blueprint-note">
          <h6 class="mb-1">Bin Array</h6>
          <p class="text-body-secondary small mb-0">
            The tapered lower body collects eggs into the designated classification bins after the servo-driven routing
            action.
          </p>
        </article>
      </section>
    </div>
  </div>
@endsection
