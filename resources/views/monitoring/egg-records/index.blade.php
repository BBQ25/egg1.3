@extends('layouts.admin')

@section('title', 'APEWSD - Egg Record Explorer')

@push('scripts')
  @vite('resources/js/egg-record-explorer-live.js')
@endpush

@section('content')
  @php
    $payload = $recordPayload ?? [];
    $liveFeed = $liveFeed ?? [];
    $liveStats = $liveFeed['stats'] ?? [];
    $liveSizeTally = $liveFeed['size_tally'] ?? [];
    $liveRecentRecords = $liveFeed['recent_records'] ?? [];
    $livePagination = $liveFeed['pagination'] ?? [];
    $liveRefreshIntervalMs = (int) (($liveFeed['refresh_interval_seconds'] ?? 2) * 1000);
    $stats = $payload['stats'] ?? (object) [];
    $records = $payload['records'] ?? null;
    $window = $payload['window'] ?? [];
    $context = $recordContext ?? [];
    $filters = $filters ?? [];
    $selectedFarmId = $context['selected']['farm_id'] ?? null;
    $selectedDeviceId = $context['selected']['device_id'] ?? null;
    $selectedRange = (string) ($selectedRange ?? '1d');
    $rangeOptions = $rangeOptions ?? [];
    $sizeClassOptions = $sizeClassOptions ?? [];
    $selectedEggUid = (string) ($filters['egg_uid'] ?? '');
    $selectedBatchCode = (string) ($filters['batch_code'] ?? '');
    $selectedSizeClass = (string) ($filters['size_class'] ?? '');
    $selectedWeightMin = $filters['weight_min'] ?? null;
    $selectedWeightMax = $filters['weight_max'] ?? null;

    $formatInt = static fn ($value): string => number_format((int) ($value ?? 0));
    $formatWeight = static fn ($value): string => number_format((float) ($value ?? 0), 2) . ' g';
    $formatSeconds = static fn ($value): string => $value === null ? 'Awaiting device timestamps' : number_format((float) $value, 1) . ' s';
    $formatDateTime = static function ($value): string {
        return \App\Support\AppTimezone::formatDateTime($value);
    };
    $statusTheme = static function ($status): string {
        return match ((string) $status) {
            'closed' => 'bg-label-success',
            'open' => 'bg-label-warning',
            default => 'bg-label-secondary',
        };
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
    .egg-record-shell {
      display: grid;
      gap: 1.5rem;
    }

    .egg-record-hero,
    .egg-record-card {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.35rem;
      background: #fff;
      box-shadow: 0 0.9rem 2rem rgba(67, 89, 113, 0.08);
    }

    .egg-record-hero {
      overflow: hidden;
      background:
        radial-gradient(circle at top left, rgba(255, 171, 0, 0.16), transparent 28%),
        linear-gradient(135deg, #fffdf7 0%, #ffffff 48%, #f3f8ff 100%);
    }

    .egg-record-hero-body,
    .egg-record-card-body {
      padding: 1.35rem 1.45rem;
    }

    .egg-record-kicker {
      font-size: 0.75rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      font-weight: 700;
      color: #8592a3;
    }

    .egg-record-title {
      margin: 0.35rem 0 0;
      font-size: clamp(1.45rem, 1.2rem + 0.5vw, 2rem);
      line-height: 1.08;
      letter-spacing: -0.03em;
      color: #243448;
    }

    .egg-record-lead {
      max-width: 58rem;
      color: #66788a;
      margin: 0.7rem 0 0;
    }

    .egg-record-pill-row,
    .egg-record-filter-row {
      display: flex;
      flex-wrap: wrap;
      gap: 0.85rem;
    }

    .egg-record-pill {
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

    .egg-record-metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 0.85rem;
    }

    .egg-record-metric {
      padding: 1rem 1.05rem;
      border-radius: 1rem;
      background: rgba(255, 255, 255, 0.92);
      border: 1px solid rgba(67, 89, 113, 0.1);
    }

    .egg-record-metric-label {
      font-size: 0.73rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #8592a3;
    }

    .egg-record-metric-value {
      margin-top: 0.35rem;
      font-size: 1.45rem;
      line-height: 1;
      font-weight: 800;
      color: #243448;
    }

    .egg-record-table-wrap {
      overflow-x: auto;
    }

    .egg-record-empty {
      padding: 1.8rem;
      text-align: center;
      color: #6e7f92;
      border: 1px dashed rgba(67, 89, 113, 0.18);
      border-radius: 1rem;
      background: rgba(245, 247, 250, 0.72);
    }

    .egg-record-source-list {
      display: grid;
      gap: 0.2rem;
    }

    .egg-record-live-card {
      display: grid;
      gap: 1rem;
    }

    .egg-record-live-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
    }

    .egg-record-live-badge,
    .egg-record-live-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.55rem 0.8rem;
      border-radius: 999px;
      border: 1px solid rgba(67, 89, 113, 0.12);
      background: rgba(255, 255, 255, 0.92);
      color: #44576b;
      font-size: 0.88rem;
    }

    .egg-record-live-badge strong,
    .egg-record-live-chip strong {
      color: #243448;
    }

    .egg-record-live-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.85rem;
    }

    .egg-record-live-stat {
      padding: 1rem 1.05rem;
      border-radius: 1rem;
      background: linear-gradient(135deg, rgba(255, 252, 241, 0.95), rgba(255, 255, 255, 0.98));
      border: 1px solid rgba(67, 89, 113, 0.1);
    }

    .egg-record-live-stat small {
      display: block;
      color: #8592a3;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-size: 0.72rem;
      margin-bottom: 0.35rem;
    }

    .egg-record-live-stat strong {
      font-size: 1.3rem;
      color: #243448;
    }

    .egg-record-live-tally {
      display: flex;
      flex-wrap: wrap;
      gap: 0.65rem;
    }

    .egg-record-live-pagination {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      padding-top: 0.9rem;
      border-top: 1px solid rgba(67, 89, 113, 0.1);
    }

    .egg-record-live-pagination__summary {
      color: #6e7f92;
      font-size: 0.88rem;
    }

    .egg-record-live-pagination__actions {
      display: inline-flex;
      align-items: center;
      gap: 0.6rem;
    }

    .egg-record-live-chip__label {
      color: #8592a3;
      font-weight: 600;
    }

    @media (max-width: 991.98px) {
      .egg-record-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 575.98px) {
      .egg-record-metrics {
        grid-template-columns: minmax(0, 1fr);
      }

      .egg-record-live-grid {
        grid-template-columns: minmax(0, 1fr);
      }
    }
  </style>

  <div class="egg-record-shell">
    <section class="egg-record-hero">
      <div class="egg-record-hero-body">
        <div class="egg-record-kicker">Record Traceability</div>
        <h1 class="egg-record-title">Egg Record Explorer</h1>
        <p class="egg-record-lead">
          Review individual ingest records by farm, device, batch, egg UID, size class, and weight so production history stays auditable down to each captured egg event.
        </p>

        <div class="egg-record-pill-row mt-3">
          <span class="egg-record-pill">Window: {{ $formatDateTime($window['start'] ?? null) }} to {{ $formatDateTime($window['end'] ?? null) }}</span>
          <span class="egg-record-pill">Latest record: {{ $formatDateTime($stats->latest_recorded_at ?? null) }}</span>
        </div>
      </div>
    </section>

    <section class="egg-record-card">
      <div id="eggRecordLivePanel" class="egg-record-card-body egg-record-live-card"
        data-live-url="{{ route('monitoring.records.live') }}"
        data-live-page="{{ (int) ($livePagination['current_page'] ?? 1) }}"
        data-timezone="{{ $appTimezoneCode ?? \App\Support\AppTimezone::current() }}"
        data-refresh-interval-ms="{{ $liveRefreshIntervalMs }}">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
          <div>
            <div class="egg-record-kicker">Near Real-Time Feed</div>
            <h2 class="h4 mb-1">ESP32 live egg stream</h2>
            <p class="egg-record-lead mb-0">
              Watch newly sorted eggs appear from the ESP32 and HX711 ingest stream with a 2-second refresh cycle, so owner and staff users can verify that each processed egg becomes a database record with minimal delay.
            </p>
          </div>
          <div class="egg-record-live-meta">
            <span class="egg-record-live-badge">
              Status:
              <strong id="eggRecordLiveStatus">Live feed ready</strong>
            </span>
            <span class="egg-record-live-badge">
              Refreshed:
              <strong id="eggRecordLiveRefreshedAt">{{ $formatDateTime($liveFeed['as_of'] ?? null) }}</strong>
            </span>
            <span class="egg-record-live-badge">
              Observed ingest gap:
              <strong id="eggRecordLiveGap">{{ $formatSeconds($liveStats['observed_gap_seconds'] ?? null) }}</strong>
            </span>
            <span class="egg-record-live-badge">
              Poll cadence:
              <strong>{{ (int) ($liveFeed['refresh_interval_seconds'] ?? 2) }} s</strong>
            </span>
          </div>
        </div>

        <div class="egg-record-live-grid">
          <article class="egg-record-live-stat">
            <small>Visible Records</small>
            <strong id="eggRecordLiveTotalRecords">{{ $formatInt($liveStats['total_records'] ?? 0) }}</strong>
          </article>
          <article class="egg-record-live-stat">
            <small>Latest Egg Record</small>
            <strong id="eggRecordLiveLatestRecord">{{ $formatDateTime($liveStats['latest_recorded_at'] ?? null) }}</strong>
          </article>
        </div>

        <div>
          <div class="text-body-secondary small mb-2">Current tally by size class within the selected farm, device, and date window.</div>
          <div id="eggRecordLiveTally" class="egg-record-live-tally">
            @foreach ($liveSizeTally as $tally)
              <div class="egg-record-live-chip">
                <span class="egg-record-live-chip__label">{{ $tally['size_class'] }}</span>
                <strong>{{ $formatInt($tally['total'] ?? 0) }}</strong>
              </div>
            @endforeach
          </div>
        </div>

        <div class="egg-record-table-wrap">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Recorded At</th>
                <th>Egg UID</th>
                <th>Size Class</th>
                <th>Weight</th>
                <th>Batch</th>
                <th>Scope</th>
                <th>Device</th>
              </tr>
            </thead>
            <tbody id="eggRecordLiveRows">
              @forelse ($liveRecentRecords as $record)
                <tr>
                  <td>{{ $formatDateTime($record['recorded_at'] ?? null) }}</td>
                  <td>{{ $record['egg_uid'] ?: 'Not set' }}</td>
                  <td><span class="badge {{ $sizeTheme($record['size_class'] ?? '') }}">{{ $record['size_class'] ?? 'Unknown' }}</span></td>
                  <td>{{ $formatWeight($record['weight_grams'] ?? 0) }}</td>
                  <td>{{ $record['batch_code'] ?: 'Not batched' }}</td>
                  <td>
                    <div>{{ $record['farm_name'] ?? 'Unknown farm' }}</div>
                    <div class="small text-body-secondary">{{ $record['owner_name'] ?? 'Owner not available' }}</div>
                  </td>
                  <td>
                    <div>{{ $record['device_name'] ?? 'Unknown device' }}</div>
                    <div class="small text-body-secondary">{{ $record['device_serial'] ?? '' }}</div>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="7" class="text-center text-body-secondary py-4">No live ingest records matched the current scope.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>

        <div class="egg-record-live-pagination">
          <div id="eggRecordLivePageSummary" class="egg-record-live-pagination__summary">
            @if (($livePagination['total'] ?? 0) > 0)
              Showing {{ number_format((int) ($livePagination['from'] ?? 0)) }} to {{ number_format((int) ($livePagination['to'] ?? 0)) }}
              of {{ number_format((int) ($livePagination['total'] ?? 0)) }} live records
            @else
              No live records available
            @endif
          </div>
          <div class="egg-record-live-pagination__actions">
            <button type="button" id="eggRecordLivePrev" class="btn btn-outline-secondary btn-sm"
              @disabled(((int) ($livePagination['current_page'] ?? 1)) <= 1)>
              Previous
            </button>
            <span id="eggRecordLivePageLabel" class="egg-record-live-badge">
              Page <strong>{{ number_format((int) ($livePagination['current_page'] ?? 1)) }}</strong>
              of <strong>{{ number_format(max(1, (int) ($livePagination['last_page'] ?? 1))) }}</strong>
            </span>
            <button type="button" id="eggRecordLiveNext" class="btn btn-outline-secondary btn-sm"
              @disabled(((int) ($livePagination['current_page'] ?? 1)) >= max(1, (int) ($livePagination['last_page'] ?? 1)))>
              Next
            </button>
          </div>
        </div>
      </div>
    </section>

    <section class="egg-record-metrics">
      <article class="egg-record-metric">
        <div class="egg-record-metric-label">Visible Records</div>
        <div class="egg-record-metric-value">{{ $formatInt($stats->total_records ?? 0) }}</div>
      </article>
      <article class="egg-record-metric">
        <div class="egg-record-metric-label">Unique Batches</div>
        <div class="egg-record-metric-value">{{ $formatInt($stats->unique_batches ?? 0) }}</div>
      </article>
      <article class="egg-record-metric">
        <div class="egg-record-metric-label">Reject Records</div>
        <div class="egg-record-metric-value">{{ $formatInt($stats->reject_count ?? 0) }}</div>
      </article>
      <article class="egg-record-metric">
        <div class="egg-record-metric-label">Average Weight</div>
        <div class="egg-record-metric-value">{{ $formatWeight($stats->avg_weight_grams ?? 0) }}</div>
      </article>
    </section>

    <section class="egg-record-card">
      <div class="egg-record-card-body">
        <form method="GET" class="egg-record-filter-row align-items-end justify-content-between">
          <div class="egg-record-filter-row align-items-end">
            <div>
              <label for="egg_record_range" class="form-label mb-1">Range</label>
              <select id="egg_record_range" name="range" class="form-select">
                @foreach ($rangeOptions as $rangeValue => $rangeLabel)
                  <option value="{{ $rangeValue }}" @selected($selectedRange === (string) $rangeValue)>{{ $rangeLabel }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="egg_record_farm" class="form-label mb-1">Farm</label>
              <select id="egg_record_farm" name="context_farm_id" class="form-select">
                <option value="">All Farms</option>
                @foreach (($context['switcher']['farms'] ?? []) as $farmOption)
                  <option value="{{ $farmOption['id'] }}" @selected((int) ($selectedFarmId ?? 0) === (int) $farmOption['id'])>{{ $farmOption['name'] }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="egg_record_device" class="form-label mb-1">Device</label>
              <select id="egg_record_device" name="context_device_id" class="form-select">
                <option value="">All Devices</option>
                @foreach (($context['switcher']['devices'] ?? []) as $deviceOption)
                  <option value="{{ $deviceOption['id'] }}" @selected((int) ($selectedDeviceId ?? 0) === (int) $deviceOption['id'])>{{ $deviceOption['name'] }} ({{ $deviceOption['serial'] }})</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="egg_record_batch_code" class="form-label mb-1">Batch Code</label>
              <input type="text" id="egg_record_batch_code" name="batch_code" class="form-control" value="{{ $selectedBatchCode }}" placeholder="Filter batch code" />
            </div>
            <div>
              <label for="egg_record_uid" class="form-label mb-1">Egg UID</label>
              <input type="text" id="egg_record_uid" name="egg_uid" class="form-control" value="{{ $selectedEggUid }}" placeholder="Filter egg UID, e.g. egg-001" />
            </div>
            <div>
              <label for="egg_record_size_class" class="form-label mb-1">Size Class</label>
              <select id="egg_record_size_class" name="size_class" class="form-select">
                <option value="">All Classes</option>
                @foreach ($sizeClassOptions as $sizeClass)
                  <option value="{{ $sizeClass }}" @selected($selectedSizeClass === $sizeClass)>{{ $sizeClass }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="egg_record_weight_min" class="form-label mb-1">Min Weight</label>
              <input type="number" step="0.01" min="0" id="egg_record_weight_min" name="weight_min" class="form-control" value="{{ $selectedWeightMin !== null ? number_format((float) $selectedWeightMin, 2, '.', '') : '' }}" placeholder="0.00" />
            </div>
            <div>
              <label for="egg_record_weight_max" class="form-label mb-1">Max Weight</label>
              <input type="number" step="0.01" min="0" id="egg_record_weight_max" name="weight_max" class="form-control" value="{{ $selectedWeightMax !== null ? number_format((float) $selectedWeightMax, 2, '.', '') : '' }}" placeholder="100.00" />
            </div>
          </div>

          <div class="egg-record-filter-row align-items-end">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="{{ route('monitoring.records.index') }}" class="btn btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>
    </section>

    <section class="egg-record-card">
      <div class="egg-record-card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <div>
            <h2 class="h5 mb-1">Record Feed</h2>
            <div class="text-body-secondary">Individual egg events captured from the weighing and sorting device ingest stream.</div>
          </div>
          <a href="{{ route('monitoring.records.export', array_filter([
              'range' => $selectedRange,
              'context_farm_id' => $selectedFarmId,
              'context_device_id' => $selectedDeviceId,
              'batch_code' => $selectedBatchCode !== '' ? $selectedBatchCode : null,
              'egg_uid' => $selectedEggUid !== '' ? $selectedEggUid : null,
              'size_class' => $selectedSizeClass !== '' ? $selectedSizeClass : null,
              'weight_min' => $selectedWeightMin,
              'weight_max' => $selectedWeightMax,
          ], static fn ($value) => $value !== null && $value !== '')) }}" class="btn btn-outline-primary">
            Export CSV
          </a>
        </div>

        @if ($records && $records->count() > 0)
          <div class="egg-record-table-wrap">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Recorded At</th>
                  <th>Egg UID</th>
                  <th>Batch</th>
                  <th>Size Class</th>
                  <th>Weight</th>
                  <th>Farm</th>
                  <th>Device</th>
                  <th>Source</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($records as $record)
                  <tr>
                    <td>{{ $formatDateTime($record->recorded_at) }}</td>
                    <td>{{ $record->egg_uid ?: 'Not set' }}</td>
                    <td>
                      @if ($record->batch_code)
                        <div class="fw-semibold">{{ $record->batch_code }}</div>
                        <div class="small mt-1">
                          <span class="badge {{ $statusTheme($record->batch_status) }}">{{ ucfirst((string) ($record->batch_status ?: 'legacy')) }}</span>
                        </div>
                        <div class="small mt-1">
                          <a href="{{ route('monitoring.batches.show', array_filter([
                              'farm' => $record->farm_id,
                              'device' => $record->device_id,
                              'batchCode' => $record->batch_code,
                              'range' => $selectedRange,
                              'context_farm_id' => $selectedFarmId,
                              'context_device_id' => $selectedDeviceId,
                              'batch_code' => $selectedBatchCode !== '' ? $selectedBatchCode : null,
                              'egg_uid' => $selectedEggUid !== '' ? $selectedEggUid : null,
                              'size_class' => $selectedSizeClass !== '' ? $selectedSizeClass : null,
                              'weight_min' => $selectedWeightMin,
                              'weight_max' => $selectedWeightMax,
                          ], static fn ($value) => $value !== null && $value !== '')) }}" class="link-primary">
                            View Batch
                          </a>
                        </div>
                      @else
                        <span class="text-body-secondary">Not batched</span>
                      @endif
                    </td>
                    <td><span class="badge {{ $sizeTheme($record->size_class) }}">{{ $record->size_class }}</span></td>
                    <td>{{ $formatWeight($record->weight_grams) }}</td>
                    <td>
                      <div>{{ $record->farm_name }}</div>
                      <div class="text-body-secondary small">{{ $record->owner_name ?: 'Owner not available' }}</div>
                    </td>
                    <td>
                      <div>{{ $record->device_name }}</div>
                      <div class="text-body-secondary small">Serial {{ $record->device_serial }}</div>
                    </td>
                    <td>
                      <div class="egg-record-source-list small">
                        <div><span class="text-body-secondary">IP:</span> {{ $record->source_ip ?: 'N/A' }}</div>
                        <div><span class="text-body-secondary">ESP32 MAC:</span> {{ $record->esp32_mac_address ?: 'N/A' }}</div>
                        <div><span class="text-body-secondary">Router MAC:</span> {{ $record->router_mac_address ?: 'N/A' }}</div>
                        <div><span class="text-body-secondary">WiFi:</span> {{ $record->wifi_ssid ?: 'N/A' }}</div>
                      </div>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

          <div class="mt-3">
            {{ $records->links() }}
          </div>
        @else
          <div class="egg-record-empty">
            No ingest records matched the selected farm, device, date range, and record filters.
          </div>
        @endif
      </div>
    </section>
  </div>
@endsection
