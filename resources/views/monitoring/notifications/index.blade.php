@extends('layouts.admin')

@section('title', 'APEWSD - Notifications')

@section('content')
  @php
    $payload = $notificationPayload ?? [];
    $stats = $payload['stats'] ?? (object) [];
    $alerts = $payload['alerts'] ?? collect();
    $window = $payload['window'] ?? [];
    $context = $notificationContext ?? [];
    $selectedFarmId = $context['selected']['farm_id'] ?? null;
    $selectedDeviceId = $context['selected']['device_id'] ?? null;
    $selectedRange = (string) ($selectedRange ?? '1d');
    $selectedSeverity = (string) ($selectedSeverity ?? 'all');
    $rangeOptions = $rangeOptions ?? [];
    $severityOptions = $severityOptions ?? [];

    $formatInt = static fn ($value): string => number_format((int) ($value ?? 0));
    $formatDateTime = static function ($value): string {
        if (!$value) {
            return 'N/A';
        }

        return \Illuminate\Support\Carbon::parse($value)->format('M j, Y g:i A');
    };
    $severityTheme = static function ($severity): string {
        return match ((string) $severity) {
            'critical' => 'bg-label-danger',
            'warn' => 'bg-label-warning',
            default => 'bg-label-info',
        };
    };
  @endphp

  <style>
    .notification-shell {
      display: grid;
      gap: 1.5rem;
    }

    .notification-hero,
    .notification-card {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.35rem;
      background: #fff;
      box-shadow: 0 0.9rem 2rem rgba(67, 89, 113, 0.08);
    }

    .notification-hero {
      overflow: hidden;
      background:
        radial-gradient(circle at top left, rgba(255, 86, 48, 0.12), transparent 28%),
        linear-gradient(135deg, #fff8f6 0%, #ffffff 50%, #f6fbff 100%);
    }

    .notification-hero-body,
    .notification-card-body {
      padding: 1.35rem 1.45rem;
    }

    .notification-kicker {
      font-size: 0.75rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      font-weight: 700;
      color: #8592a3;
    }

    .notification-title {
      margin: 0.35rem 0 0;
      font-size: clamp(1.45rem, 1.2rem + 0.5vw, 2rem);
      line-height: 1.08;
      letter-spacing: -0.03em;
      color: #243448;
    }

    .notification-lead {
      max-width: 58rem;
      color: #66788a;
      margin: 0.7rem 0 0;
    }

    .notification-pill-row,
    .notification-filter-row {
      display: flex;
      flex-wrap: wrap;
      gap: 0.85rem;
    }

    .notification-pill {
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

    .notification-metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 0.85rem;
    }

    .notification-metric {
      padding: 1rem 1.05rem;
      border-radius: 1rem;
      background: rgba(255, 255, 255, 0.92);
      border: 1px solid rgba(67, 89, 113, 0.1);
    }

    .notification-metric-label {
      font-size: 0.73rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #8592a3;
    }

    .notification-metric-value {
      margin-top: 0.35rem;
      font-size: 1.45rem;
      line-height: 1;
      font-weight: 800;
      color: #243448;
    }

    .notification-table-wrap {
      overflow-x: auto;
    }

    .notification-empty {
      padding: 1.8rem;
      text-align: center;
      color: #6e7f92;
      border: 1px dashed rgba(67, 89, 113, 0.18);
      border-radius: 1rem;
      background: rgba(245, 247, 250, 0.72);
    }

    @media (max-width: 991.98px) {
      .notification-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 575.98px) {
      .notification-metrics {
        grid-template-columns: minmax(0, 1fr);
      }
    }
  </style>

  <div class="notification-shell">
    <section class="notification-hero">
      <div class="notification-hero-body">
        <div class="notification-kicker">Operational Alerts</div>
        <h1 class="notification-title">Notifications</h1>
        <p class="notification-lead">
          Monitor heartbeat failures, ingest gaps, reject spikes, and stalled open batches across the devices currently visible to your account.
        </p>

        <div class="notification-pill-row mt-3">
          <span class="notification-pill">Window: {{ $formatDateTime($window['start'] ?? null) }} to {{ $formatDateTime($window['end'] ?? null) }}</span>
          <span class="notification-pill">Latest alert: {{ $formatDateTime($stats->latest_triggered_at ?? null) }}</span>
        </div>
      </div>
    </section>

    <section class="notification-metrics">
      <article class="notification-metric">
        <div class="notification-metric-label">Visible Alerts</div>
        <div class="notification-metric-value">{{ $formatInt($stats->total_alerts ?? 0) }}</div>
      </article>
      <article class="notification-metric">
        <div class="notification-metric-label">Critical</div>
        <div class="notification-metric-value">{{ $formatInt($stats->critical_count ?? 0) }}</div>
      </article>
      <article class="notification-metric">
        <div class="notification-metric-label">Warning</div>
        <div class="notification-metric-value">{{ $formatInt($stats->warn_count ?? 0) }}</div>
      </article>
      <article class="notification-metric">
        <div class="notification-metric-label">Flagged Devices</div>
        <div class="notification-metric-value">{{ $formatInt($stats->flagged_devices ?? 0) }}</div>
      </article>
    </section>

    <section class="notification-card">
      <div class="notification-card-body">
        <form method="GET" class="notification-filter-row align-items-end justify-content-between">
          <div class="notification-filter-row align-items-end">
            <div>
              <label for="notification_range" class="form-label mb-1">Range</label>
              <select id="notification_range" name="range" class="form-select">
                @foreach ($rangeOptions as $rangeValue => $rangeLabel)
                  <option value="{{ $rangeValue }}" @selected($selectedRange === (string) $rangeValue)>{{ $rangeLabel }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="notification_farm" class="form-label mb-1">Farm</label>
              <select id="notification_farm" name="context_farm_id" class="form-select">
                <option value="">All Farms</option>
                @foreach (($context['switcher']['farms'] ?? []) as $farmOption)
                  <option value="{{ $farmOption['id'] }}" @selected((int) ($selectedFarmId ?? 0) === (int) $farmOption['id'])>{{ $farmOption['name'] }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="notification_device" class="form-label mb-1">Device</label>
              <select id="notification_device" name="context_device_id" class="form-select">
                <option value="">All Devices</option>
                @foreach (($context['switcher']['devices'] ?? []) as $deviceOption)
                  <option value="{{ $deviceOption['id'] }}" @selected((int) ($selectedDeviceId ?? 0) === (int) $deviceOption['id'])>{{ $deviceOption['name'] }} ({{ $deviceOption['serial'] }})</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="notification_severity" class="form-label mb-1">Severity</label>
              <select id="notification_severity" name="severity" class="form-select">
                @foreach ($severityOptions as $severityValue => $severityLabel)
                  <option value="{{ $severityValue }}" @selected($selectedSeverity === (string) $severityValue)>{{ $severityLabel }}</option>
                @endforeach
              </select>
            </div>
          </div>

          <div class="notification-filter-row align-items-end">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="{{ route('monitoring.notifications.index') }}" class="btn btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>
    </section>

    <section class="notification-card">
      <div class="notification-card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
          <div>
            <h2 class="h5 mb-1">Live Alert Feed</h2>
            <div class="text-body-secondary">Computed from active devices, recent ingest, and open-batch activity.</div>
          </div>
        </div>

        @if ($alerts->count() > 0)
          <div class="notification-table-wrap">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Severity</th>
                  <th>Alert</th>
                  <th>Target</th>
                  <th>Triggered</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($alerts as $alert)
                  <tr>
                    <td>
                      <span class="badge {{ $severityTheme($alert['severity'] ?? 'info') }}">{{ strtoupper((string) ($alert['severity'] ?? 'info')) }}</span>
                    </td>
                    <td>
                      <div class="fw-semibold">{{ $alert['title'] }}</div>
                      <div class="text-body-secondary small">{{ $alert['message'] }}</div>
                    </td>
                    <td>
                      <div>{{ $alert['device_name'] }} · {{ $alert['device_serial'] }}</div>
                      <div class="text-body-secondary small">{{ $alert['farm_name'] }}</div>
                      @if (!empty($alert['batch_code']))
                        <div class="text-body-secondary small">Batch {{ $alert['batch_code'] }}</div>
                      @endif
                    </td>
                    <td>{{ $formatDateTime($alert['triggered_at'] ?? null) }}</td>
                    <td class="text-end">
                      @if (!empty($alert['batch_code']))
                        <a href="{{ route('monitoring.batches.show', [
                            'farm' => $alert['farm_id'],
                            'device' => $alert['device_id'],
                            'batchCode' => $alert['batch_code'],
                            'range' => $selectedRange,
                            'context_farm_id' => $selectedFarmId,
                            'context_device_id' => $selectedDeviceId,
                            'severity' => $selectedSeverity !== 'all' ? $selectedSeverity : null,
                        ]) }}" class="btn btn-sm btn-outline-primary">
                          View Batch
                        </a>
                      @else
                        <a href="{{ route('monitoring.records.index', [
                            'range' => $selectedRange,
                            'context_farm_id' => $alert['farm_id'],
                            'context_device_id' => $alert['device_id'],
                        ]) }}" class="btn btn-sm btn-outline-primary">
                          View Records
                        </a>
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @else
          <div class="notification-empty">
            No alerts matched the selected monitoring scope and severity filter.
          </div>
        @endif
      </div>
    </section>
  </div>
@endsection
