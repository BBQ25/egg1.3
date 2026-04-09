@extends('layouts.admin')

@section('title', 'APEWSD - Production Reports')

@section('content')
  @php
    $payload = $reportPayload ?? [];
    $summary = $payload['summary'] ?? (object) [];
    $daily = $payload['daily'] ?? collect();
    $farms = $payload['farms'] ?? collect();
    $devices = $payload['devices'] ?? collect();
    $sizes = $payload['sizes'] ?? collect();
    $window = $payload['window'] ?? [];
    $context = $reportContext ?? [];
    $filters = $filters ?? [];
    $selectedFarmId = $context['selected']['farm_id'] ?? null;
    $selectedDeviceId = $context['selected']['device_id'] ?? null;
    $selectedRange = (string) ($selectedRange ?? '1d');
    $rangeOptions = $rangeOptions ?? [];
    $sizeClassOptions = $sizeClassOptions ?? [];
    $selectedBatchCode = (string) ($filters['batch_code'] ?? '');
    $selectedEggUid = (string) ($filters['egg_uid'] ?? '');
    $selectedSizeClass = (string) ($filters['size_class'] ?? '');
    $selectedWeightMin = $filters['weight_min'] ?? null;
    $selectedWeightMax = $filters['weight_max'] ?? null;

    $formatInt = static fn ($value): string => number_format((int) ($value ?? 0));
    $formatWeight = static fn ($value): string => number_format((float) ($value ?? 0), 2) . ' g';
    $formatDate = static function ($value): string {
        return \App\Support\AppTimezone::formatDate($value);
    };
    $formatDateTime = static function ($value): string {
        return \App\Support\AppTimezone::formatDateTime($value);
    };
    $sizeTheme = static function ($sizeClass): string {
        return match ((string) $sizeClass) {
            'Reject' => 'bg-label-danger',
            'Peewee' => 'bg-label-secondary',
            'Pullet' => 'bg-label-info',
            'Small' => 'bg-label-primary',
            'Medium' => 'bg-label-success',
            'Large' => 'bg-label-warning',
            'Extra-Large' => 'bg-label-dark',
            'Jumbo' => 'bg-label-danger',
            default => 'bg-label-secondary',
        };
    };
  @endphp

  <style>
    .production-report-shell {
      display: grid;
      gap: 1.5rem;
    }

    .production-report-hero,
    .production-report-card {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.35rem;
      background: #fff;
      box-shadow: 0 0.9rem 2rem rgba(67, 89, 113, 0.08);
    }

    .production-report-hero {
      overflow: hidden;
      background:
        radial-gradient(circle at top right, rgba(32, 201, 151, 0.14), transparent 30%),
        linear-gradient(135deg, #f7fff9 0%, #ffffff 45%, #f4f8ff 100%);
    }

    .production-report-hero-body,
    .production-report-card-body {
      padding: 1.35rem 1.45rem;
    }

    .production-report-kicker {
      font-size: 0.75rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      font-weight: 700;
      color: #8592a3;
    }

    .production-report-title {
      margin: 0.35rem 0 0;
      font-size: clamp(1.45rem, 1.2rem + 0.5vw, 2rem);
      line-height: 1.08;
      letter-spacing: -0.03em;
      color: #243448;
    }

    .production-report-lead {
      max-width: 58rem;
      color: #66788a;
      margin: 0.7rem 0 0;
    }

    .production-report-pill-row,
    .production-report-filter-row {
      display: flex;
      flex-wrap: wrap;
      gap: 0.85rem;
    }

    .production-report-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.45rem 0.8rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid rgba(67, 89, 113, 0.1);
      color: #44576b;
      font-weight: 600;
      font-size: 0.88rem;
    }

    .production-report-metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 0.85rem;
    }

    .production-report-metric {
      padding: 1rem 1.05rem;
      border-radius: 1rem;
      background: rgba(255, 255, 255, 0.92);
      border: 1px solid rgba(67, 89, 113, 0.1);
    }

    .production-report-metric-label {
      font-size: 0.73rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #8592a3;
    }

    .production-report-metric-value {
      margin-top: 0.35rem;
      font-size: 1.45rem;
      line-height: 1;
      font-weight: 800;
      color: #243448;
    }

    .production-report-grid {
      display: grid;
      grid-template-columns: 1.2fr 1fr;
      gap: 1.5rem;
      align-items: start;
    }

    .production-report-table-wrap {
      overflow-x: auto;
    }

    .production-report-empty {
      padding: 1.8rem;
      text-align: center;
      color: #6e7f92;
      border: 1px dashed rgba(67, 89, 113, 0.18);
      border-radius: 1rem;
      background: rgba(245, 247, 250, 0.72);
    }

    @media (max-width: 991.98px) {
      .production-report-grid,
      .production-report-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 575.98px) {
      .production-report-grid,
      .production-report-metrics {
        grid-template-columns: minmax(0, 1fr);
      }
    }
  </style>

  <div class="production-report-shell">
    <section class="production-report-hero">
      <div class="production-report-hero-body">
        <div class="production-report-kicker">Historical Summary</div>
        <h1 class="production-report-title">Production Reports</h1>
        <p class="production-report-lead">
          Convert filtered ingest records into production summaries by date, farm, device, and egg size class for faster operational review and manuscript-ready reporting.
        </p>

        <div class="production-report-pill-row mt-3">
          <span class="production-report-pill">Window: {{ $formatDateTime($window['start'] ?? null) }} to {{ $formatDateTime($window['end'] ?? null) }}</span>
          <span class="production-report-pill">Visible batches: {{ $formatInt($summary->unique_batches ?? 0) }}</span>
        </div>
      </div>
    </section>

    <section class="production-report-metrics">
      <article class="production-report-metric">
        <div class="production-report-metric-label">Egg Records</div>
        <div class="production-report-metric-value">{{ $formatInt($summary->total_records ?? 0) }}</div>
      </article>
      <article class="production-report-metric">
        <div class="production-report-metric-label">Active Farms</div>
        <div class="production-report-metric-value">{{ $formatInt($summary->active_farms ?? 0) }}</div>
      </article>
      <article class="production-report-metric">
        <div class="production-report-metric-label">Active Devices</div>
        <div class="production-report-metric-value">{{ $formatInt($summary->active_devices ?? 0) }}</div>
      </article>
      <article class="production-report-metric">
        <div class="production-report-metric-label">Total Weight</div>
        <div class="production-report-metric-value">{{ $formatWeight($summary->total_weight_grams ?? 0) }}</div>
      </article>
    </section>

    <section class="production-report-card">
      <div class="production-report-card-body">
        <form method="GET" class="production-report-filter-row align-items-end justify-content-between">
          <div class="production-report-filter-row align-items-end">
            <div>
              <label for="production_report_range" class="form-label mb-1">Range</label>
              <select id="production_report_range" name="range" class="form-select">
                @foreach ($rangeOptions as $rangeValue => $rangeLabel)
                  <option value="{{ $rangeValue }}" @selected($selectedRange === (string) $rangeValue)>{{ $rangeLabel }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="production_report_farm" class="form-label mb-1">Farm</label>
              <select id="production_report_farm" name="context_farm_id" class="form-select">
                <option value="">All Farms</option>
                @foreach (($context['switcher']['farms'] ?? []) as $farmOption)
                  <option value="{{ $farmOption['id'] }}" @selected((int) ($selectedFarmId ?? 0) === (int) $farmOption['id'])>{{ $farmOption['name'] }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="production_report_device" class="form-label mb-1">Device</label>
              <select id="production_report_device" name="context_device_id" class="form-select">
                <option value="">All Devices</option>
                @foreach (($context['switcher']['devices'] ?? []) as $deviceOption)
                  <option value="{{ $deviceOption['id'] }}" @selected((int) ($selectedDeviceId ?? 0) === (int) $deviceOption['id'])>{{ $deviceOption['name'] }} ({{ $deviceOption['serial'] }})</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="production_report_batch_code" class="form-label mb-1">Batch Code</label>
              <input type="text" id="production_report_batch_code" name="batch_code" class="form-control" value="{{ $selectedBatchCode }}" placeholder="Optional batch filter" />
            </div>
            <div>
              <label for="production_report_uid" class="form-label mb-1">Egg UID</label>
              <input type="text" id="production_report_uid" name="egg_uid" class="form-control" value="{{ $selectedEggUid }}" placeholder="Optional egg UID, e.g. egg-001" />
            </div>
            <div>
              <label for="production_report_size_class" class="form-label mb-1">Size Class</label>
              <select id="production_report_size_class" name="size_class" class="form-select">
                <option value="">All Classes</option>
                @foreach ($sizeClassOptions as $sizeClass)
                  <option value="{{ $sizeClass }}" @selected($selectedSizeClass === $sizeClass)>{{ $sizeClass }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="production_report_weight_min" class="form-label mb-1">Min Weight</label>
              <input type="number" step="0.01" min="0" id="production_report_weight_min" name="weight_min" class="form-control" value="{{ $selectedWeightMin !== null ? number_format((float) $selectedWeightMin, 2, '.', '') : '' }}" placeholder="0.00" />
            </div>
            <div>
              <label for="production_report_weight_max" class="form-label mb-1">Max Weight</label>
              <input type="number" step="0.01" min="0" id="production_report_weight_max" name="weight_max" class="form-control" value="{{ $selectedWeightMax !== null ? number_format((float) $selectedWeightMax, 2, '.', '') : '' }}" placeholder="100.00" />
            </div>
          </div>

          <div class="production-report-filter-row align-items-end">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="{{ route('monitoring.reports.production.index') }}" class="btn btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>
    </section>

    <section class="production-report-card">
      <div class="production-report-card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <div>
            <h2 class="h5 mb-1">Daily Output</h2>
            <div class="text-body-secondary">Per-day production totals inside the selected monitoring window.</div>
          </div>
          <a href="{{ route('monitoring.reports.production.export', array_filter([
              'range' => $selectedRange,
              'context_farm_id' => $selectedFarmId,
              'context_device_id' => $selectedDeviceId,
              'batch_code' => $selectedBatchCode !== '' ? $selectedBatchCode : null,
              'egg_uid' => $selectedEggUid !== '' ? $selectedEggUid : null,
              'size_class' => $selectedSizeClass !== '' ? $selectedSizeClass : null,
              'weight_min' => $selectedWeightMin,
              'weight_max' => $selectedWeightMax,
          ], static fn ($value) => $value !== null && $value !== '')) }}" class="btn btn-outline-primary">
            Export Daily CSV
          </a>
        </div>

        @if ($daily->count() > 0)
          <div class="production-report-table-wrap">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Records</th>
                  <th>Batches</th>
                  <th>Rejects</th>
                  <th>Average Weight</th>
                  <th>Total Weight</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($daily as $row)
                  <tr>
                    <td>{{ $formatDate($row->report_date) }}</td>
                    <td>{{ $formatInt($row->total_records) }}</td>
                    <td>{{ $formatInt($row->unique_batches) }}</td>
                    <td>{{ $formatInt($row->reject_count) }}</td>
                    <td>{{ $formatWeight($row->avg_weight_grams) }}</td>
                    <td>{{ $formatWeight($row->total_weight_grams) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @else
          <div class="production-report-empty">No production records matched the current filters.</div>
        @endif
      </div>
    </section>

    <section class="production-report-grid">
      <article class="production-report-card">
        <div class="production-report-card-body">
          <h2 class="h5 mb-3">By Farm</h2>
          <div class="production-report-table-wrap">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Farm</th>
                  <th>Records</th>
                  <th>Batches</th>
                  <th>Rejects</th>
                  <th>Total Weight</th>
                </tr>
              </thead>
              <tbody>
                @forelse ($farms as $row)
                  <tr>
                    <td>{{ $row->farm_name }}</td>
                    <td>{{ $formatInt($row->total_records) }}</td>
                    <td>{{ $formatInt($row->unique_batches) }}</td>
                    <td>{{ $formatInt($row->reject_count) }}</td>
                    <td>{{ $formatWeight($row->total_weight_grams) }}</td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="5" class="text-center text-body-secondary">No farm summaries available.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </article>

      <article class="production-report-card">
        <div class="production-report-card-body">
          <h2 class="h5 mb-3">By Size Class</h2>
          <div class="production-report-table-wrap">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Size Class</th>
                  <th>Records</th>
                  <th>Average Weight</th>
                </tr>
              </thead>
              <tbody>
                @forelse ($sizes as $row)
                  <tr>
                    <td><span class="badge {{ $sizeTheme($row->size_class) }}">{{ $row->size_class }}</span></td>
                    <td>{{ $formatInt($row->total_records) }}</td>
                    <td>{{ $formatWeight($row->avg_weight_grams) }}</td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="3" class="text-center text-body-secondary">No size summaries available.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </article>
    </section>

    <section class="production-report-card">
      <div class="production-report-card-body">
        <h2 class="h5 mb-3">By Device</h2>
        <div class="production-report-table-wrap">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Device</th>
                <th>Records</th>
                <th>Batches</th>
                <th>Rejects</th>
                <th>Average Weight</th>
                <th>Total Weight</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($devices as $row)
                <tr>
                  <td>
                    <div>{{ $row->device_name }}</div>
                    <div class="text-body-secondary small">Serial {{ $row->device_serial }}</div>
                  </td>
                  <td>{{ $formatInt($row->total_records) }}</td>
                  <td>{{ $formatInt($row->unique_batches) }}</td>
                  <td>{{ $formatInt($row->reject_count) }}</td>
                  <td>{{ $formatWeight($row->avg_weight_grams) }}</td>
                  <td>{{ $formatWeight($row->total_weight_grams) }}</td>
                </tr>
              @empty
                <tr>
                  <td colspan="6" class="text-center text-body-secondary">No device summaries available.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
@endsection
