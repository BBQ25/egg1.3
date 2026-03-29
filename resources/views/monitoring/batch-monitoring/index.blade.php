@extends('layouts.admin')

@section('title', 'APEWSD - Batch Monitoring')

@section('content')
  @php
    $payload = $batchPayload ?? [];
    $stats = $payload['stats'] ?? (object) [];
    $batches = $payload['batches'] ?? null;
    $window = $payload['window'] ?? [];
    $context = $batchContext ?? [];
    $selectedFarmId = $context['selected']['farm_id'] ?? null;
    $selectedDeviceId = $context['selected']['device_id'] ?? null;
    $selectedRange = (string) ($selectedRange ?? '1d');
    $selectedSearch = (string) ($selectedSearch ?? '');
    $selectedStatus = (string) ($selectedStatus ?? 'all');
    $rangeOptions = $rangeOptions ?? [];
    $statusOptions = $statusOptions ?? [];
    $oldFarmId = (int) old('farm_id', $selectedFarmId ?? 0);
    $oldDeviceId = (int) old('device_id', $selectedDeviceId ?? 0);
    $oldBatchCode = (string) old('batch_code', '');

    $formatInt = static fn ($value): string => number_format((int) ($value ?? 0));
    $formatWeight = static fn ($value): string => number_format((float) ($value ?? 0), 2) . ' g';
    $formatDateTime = static function ($value): string {
        if (!$value) {
            return 'N/A';
        }

        return \Illuminate\Support\Carbon::parse($value)->format('M j, Y g:i A');
    };
    $durationLabel = static function ($start, $end): string {
        if (!$start) {
            return 'N/A';
        }

        if (!$end) {
            return 'In progress';
        }

        $minutes = \Illuminate\Support\Carbon::parse($start)->diffInMinutes(\Illuminate\Support\Carbon::parse($end));

        if ($minutes < 1) {
            return 'Under 1 minute';
        }

        return $minutes . ' min';
    };
    $statusTheme = static function ($status): string {
        return match ((string) $status) {
            'closed' => 'bg-label-success',
            'open' => 'bg-label-warning',
            default => 'bg-label-secondary',
        };
    };
  @endphp

  <style>
    .batch-monitor-shell {
      display: grid;
      gap: 1.5rem;
    }

    .batch-monitor-hero,
    .batch-monitor-card {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.35rem;
      background: #fff;
      box-shadow: 0 0.9rem 2rem rgba(67, 89, 113, 0.08);
    }

    .batch-monitor-hero {
      overflow: hidden;
      background:
        radial-gradient(circle at top right, rgba(105, 108, 255, 0.16), transparent 30%),
        linear-gradient(135deg, #f8fbff 0%, #ffffff 50%, #fff8ed 100%);
    }

    .batch-monitor-hero-body,
    .batch-monitor-card-body {
      padding: 1.35rem 1.45rem;
    }

    .batch-monitor-kicker {
      font-size: 0.75rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      font-weight: 700;
      color: #8592a3;
    }

    .batch-monitor-title {
      margin: 0.35rem 0 0;
      font-size: clamp(1.45rem, 1.2rem + 0.5vw, 2rem);
      line-height: 1.08;
      letter-spacing: -0.03em;
      color: #243448;
    }

    .batch-monitor-lead {
      max-width: 54rem;
      color: #66788a;
      margin: 0.7rem 0 0;
    }

    .batch-monitor-pill-row,
    .batch-monitor-metrics,
    .batch-monitor-filter-row {
      display: flex;
      flex-wrap: wrap;
      gap: 0.85rem;
    }

    .batch-monitor-dual-grid {
      display: grid;
      grid-template-columns: 1.35fr 0.95fr;
      gap: 1.5rem;
      align-items: start;
    }

    .batch-monitor-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.45rem 0.8rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.88);
      border: 1px solid rgba(67, 89, 113, 0.1);
      color: #44576b;
      font-weight: 600;
      font-size: 0.88rem;
    }

    .batch-monitor-metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .batch-monitor-metric {
      padding: 1rem 1.05rem;
      border-radius: 1rem;
      background: rgba(255, 255, 255, 0.92);
      border: 1px solid rgba(67, 89, 113, 0.1);
    }

    .batch-monitor-metric-label {
      font-size: 0.73rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #8592a3;
    }

    .batch-monitor-metric-value {
      margin-top: 0.35rem;
      font-size: 1.45rem;
      line-height: 1;
      font-weight: 800;
      color: #243448;
    }

    .batch-monitor-table-wrap {
      overflow-x: auto;
    }

    .batch-monitor-empty {
      padding: 1.8rem;
      text-align: center;
      color: #6e7f92;
      border: 1px dashed rgba(67, 89, 113, 0.18);
      border-radius: 1rem;
      background: rgba(245, 247, 250, 0.72);
    }

    @media (max-width: 991.98px) {
      .batch-monitor-dual-grid,
      .batch-monitor-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 575.98px) {
      .batch-monitor-dual-grid,
      .batch-monitor-metrics {
        grid-template-columns: minmax(0, 1fr);
      }
    }
  </style>

  <div class="batch-monitor-shell">
    <section class="batch-monitor-hero">
      <div class="batch-monitor-hero-body">
        <div class="batch-monitor-kicker">Production Visibility</div>
        <h1 class="batch-monitor-title">Batch Monitoring</h1>
        <p class="batch-monitor-lead">
          Track real-time and historical production batches by farm, device, and batch code using the existing ESP32 ingest stream.
        </p>

        <div class="batch-monitor-pill-row mt-3">
          <span class="batch-monitor-pill">Window: {{ $formatDateTime($window['start'] ?? null) }} to {{ $formatDateTime($window['end'] ?? null) }}</span>
          <span class="batch-monitor-pill">Latest record: {{ $formatDateTime($stats->latest_recorded_at ?? null) }}</span>
        </div>
      </div>
    </section>

    <section class="batch-monitor-metrics">
      <article class="batch-monitor-metric">
        <div class="batch-monitor-metric-label">Visible Batches</div>
        <div class="batch-monitor-metric-value">{{ $formatInt($stats->total_batches ?? 0) }}</div>
      </article>
      <article class="batch-monitor-metric">
        <div class="batch-monitor-metric-label">Egg Records</div>
        <div class="batch-monitor-metric-value">{{ $formatInt($stats->total_eggs ?? 0) }}</div>
      </article>
      <article class="batch-monitor-metric">
        <div class="batch-monitor-metric-label">Reject Eggs</div>
        <div class="batch-monitor-metric-value">{{ $formatInt($stats->reject_eggs ?? 0) }}</div>
      </article>
      <article class="batch-monitor-metric">
        <div class="batch-monitor-metric-label">Total Weight</div>
        <div class="batch-monitor-metric-value">{{ $formatWeight($stats->total_weight_grams ?? 0) }}</div>
      </article>
    </section>

    <section class="batch-monitor-card">
      <div class="batch-monitor-card-body">
        @if (session('status'))
          <div class="alert alert-success mb-3">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
          <div class="alert alert-danger mb-3">
            <div class="fw-semibold mb-1">Batch opening could not be completed.</div>
            <ul class="mb-0 ps-3">
              @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
              @endforeach
            </ul>
          </div>
        @endif

        <form method="GET" class="batch-monitor-filter-row align-items-end justify-content-between">
          <div class="batch-monitor-filter-row align-items-end">
            <div>
              <label for="batch_monitor_range" class="form-label mb-1">Range</label>
              <select id="batch_monitor_range" name="range" class="form-select">
                @foreach ($rangeOptions as $rangeValue => $rangeLabel)
                  <option value="{{ $rangeValue }}" @selected($selectedRange === (string) $rangeValue)>{{ $rangeLabel }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="batch_monitor_farm" class="form-label mb-1">Farm</label>
              <select id="batch_monitor_farm" name="context_farm_id" class="form-select">
                <option value="">All Farms</option>
                @foreach (($context['switcher']['farms'] ?? []) as $farmOption)
                  <option value="{{ $farmOption['id'] }}" @selected((int) ($selectedFarmId ?? 0) === (int) $farmOption['id'])>{{ $farmOption['name'] }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="batch_monitor_device" class="form-label mb-1">Device</label>
              <select id="batch_monitor_device" name="context_device_id" class="form-select">
                <option value="">All Devices</option>
                @foreach (($context['switcher']['devices'] ?? []) as $deviceOption)
                  <option value="{{ $deviceOption['id'] }}" @selected((int) ($selectedDeviceId ?? 0) === (int) $deviceOption['id'])>{{ $deviceOption['name'] }} ({{ $deviceOption['serial'] }})</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="batch_monitor_status" class="form-label mb-1">Status</label>
              <select id="batch_monitor_status" name="status" class="form-select">
                @foreach ($statusOptions as $statusValue => $statusLabel)
                  <option value="{{ $statusValue }}" @selected($selectedStatus === (string) $statusValue)>{{ $statusLabel }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="batch_monitor_search" class="form-label mb-1">Batch Code</label>
              <input type="text" id="batch_monitor_search" name="q" class="form-control" value="{{ $selectedSearch }}" placeholder="Search batch code" />
            </div>
          </div>

          <div class="batch-monitor-filter-row align-items-end">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="{{ route('monitoring.batches.index') }}" class="btn btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>
    </section>

    <section class="batch-monitor-dual-grid">
      <article class="batch-monitor-card">
        <div class="batch-monitor-card-body">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
              <h2 class="h5 mb-1">Observed Batches</h2>
              <div class="text-body-secondary">Open and closed production sessions grouped by farm, device, and batch code.</div>
            </div>
            <a href="{{ route('monitoring.batches.export', array_filter([
                'range' => $selectedRange,
                'context_farm_id' => $selectedFarmId,
                'context_device_id' => $selectedDeviceId,
                'q' => $selectedSearch !== '' ? $selectedSearch : null,
                'status' => $selectedStatus !== 'all' ? $selectedStatus : null,
            ], static fn ($value) => $value !== null && $value !== '')) }}" class="btn btn-outline-primary">
              Export CSV
            </a>
          </div>

          @if ($batches && $batches->count() > 0)
            <div class="batch-monitor-table-wrap">
              <table class="table table-hover align-middle mb-0">
                <thead>
                  <tr>
                    <th>Batch</th>
                    <th>Status</th>
                    <th>Farm</th>
                    <th>Device</th>
                    <th>Window</th>
                    <th>Eggs</th>
                    <th>Rejects</th>
                    <th>Average Weight</th>
                    <th class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach ($batches as $batch)
                    <tr>
                      <td>
                        <div class="fw-semibold">{{ $batch->batch_code }}</div>
                        <div class="text-body-secondary small">{{ $formatWeight($batch->total_weight_grams) }}</div>
                      </td>
                      <td>
                        <span class="badge {{ $statusTheme($batch->status) }}">{{ ucfirst((string) $batch->status) }}</span>
                      </td>
                      <td>
                        <div>{{ $batch->farm_name }}</div>
                        <div class="text-body-secondary small">{{ $batch->owner_name ?: 'Owner not available' }}</div>
                      </td>
                      <td>
                        <div>{{ $batch->device_name }}</div>
                        <div class="text-body-secondary small">Serial {{ $batch->device_serial }}</div>
                      </td>
                      <td>
                        <div>{{ $formatDateTime($batch->started_at) }}</div>
                        <div class="text-body-secondary small">{{ $durationLabel($batch->started_at, $batch->ended_at) }}</div>
                      </td>
                      <td>{{ $formatInt($batch->total_eggs) }}</td>
                      <td>{{ $formatInt($batch->reject_count) }}</td>
                      <td>{{ $formatWeight($batch->avg_weight_grams) }}</td>
                      <td class="text-end">
                        <a href="{{ route('monitoring.batches.show', [
                            'farm' => $batch->farm_id,
                            'device' => $batch->device_id,
                            'batchCode' => $batch->batch_code,
                            'range' => $selectedRange,
                            'context_farm_id' => $selectedFarmId,
                            'context_device_id' => $selectedDeviceId,
                            'q' => $selectedSearch,
                            'status' => $selectedStatus !== 'all' ? $selectedStatus : null,
                        ]) }}" class="btn btn-sm btn-outline-primary">
                          View Batch
                        </a>
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>

            <div class="mt-3">
              {{ $batches->links() }}
            </div>
          @else
            <div class="batch-monitor-empty">
              No batches matched the selected scope, time range, and status filter.
            </div>
          @endif
        </div>
      </article>

      <article class="batch-monitor-card">
        <div class="batch-monitor-card-body">
          <h2 class="h5 mb-1">Open Batch</h2>
          <div class="text-body-secondary mb-3">Create a live production session before the next ingest cycle starts.</div>

          <form method="POST" action="{{ route('monitoring.batches.store', array_filter([
              'range' => $selectedRange,
              'context_farm_id' => $selectedFarmId,
              'context_device_id' => $selectedDeviceId,
              'q' => $selectedSearch !== '' ? $selectedSearch : null,
              'status' => $selectedStatus !== 'all' ? $selectedStatus : null,
          ], static fn ($value) => $value !== null && $value !== '')) }}" class="d-grid gap-3">
            @csrf

            <div>
              <label for="open_batch_farm" class="form-label mb-1">Farm</label>
              <select id="open_batch_farm" name="farm_id" class="form-select @error('farm_id') is-invalid @enderror" required>
                <option value="">Select Farm</option>
                @foreach (($context['switcher']['farms'] ?? []) as $farmOption)
                  <option value="{{ $farmOption['id'] }}" @selected($oldFarmId === (int) $farmOption['id'])>{{ $farmOption['name'] }}</option>
                @endforeach
              </select>
              @error('farm_id')
                <div class="invalid-feedback">{{ $message }}</div>
              @enderror
            </div>

            <div>
              <label for="open_batch_device" class="form-label mb-1">Device</label>
              <select id="open_batch_device" name="device_id" class="form-select @error('device_id') is-invalid @enderror" required>
                <option value="">Select Device</option>
                @foreach (($context['switcher']['devices'] ?? []) as $deviceOption)
                  <option value="{{ $deviceOption['id'] }}" @selected($oldDeviceId === (int) $deviceOption['id'])>
                    {{ $deviceOption['name'] }} ({{ $deviceOption['serial'] }}){{ $deviceOption['farm_name'] ? ' - ' . $deviceOption['farm_name'] : '' }}
                  </option>
                @endforeach
              </select>
              @error('device_id')
                <div class="invalid-feedback">{{ $message }}</div>
              @enderror
            </div>

            <div>
              <label for="open_batch_code" class="form-label mb-1">Batch Code</label>
              <input type="text" id="open_batch_code" name="batch_code" class="form-control @error('batch_code') is-invalid @enderror" value="{{ $oldBatchCode }}" maxlength="80" placeholder="BATCH-2026-001" required />
              @error('batch_code')
                <div class="invalid-feedback">{{ $message }}</div>
              @enderror
            </div>

            <div class="small text-body-secondary">
              One open batch is allowed per device. Close the current batch first before opening another one on the same device.
            </div>

            <div>
              <button type="submit" class="btn btn-primary">Open Batch</button>
            </div>
          </form>
        </div>
      </article>
    </section>

  </div>
@endsection
