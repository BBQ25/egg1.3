@extends('layouts.admin')

@section('title', 'Dashboard')

@php
  $payload = is_array($dashboardPayload ?? null) ? $dashboardPayload : [];
  $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
  $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
  $profile = is_array($summary['profile'] ?? null) ? $summary['profile'] : [];
  $sizeBreakdown = is_array($payload['size_breakdown'] ?? null) ? $payload['size_breakdown'] : [];
  $activityBreakdown = is_array($payload['activity_breakdown'] ?? null) ? $payload['activity_breakdown'] : [];
  $topActive = is_array($payload['top_active'] ?? null) ? $payload['top_active'] : [];
  $timeline = is_array($payload['timeline'] ?? null) ? $payload['timeline'] : [];

  $selectedRange = (string) ($payload['range'] ?? '1d');
  $selectedFarmId = $context['selected']['farm_id'] ?? null;
  $selectedDeviceId = $context['selected']['device_id'] ?? null;

  $rangeUrl = static function (string $range) use ($selectedFarmId, $selectedDeviceId): string {
      $query = [];

      if ($range !== '') {
          $query['range'] = $range;
      }

      if ($selectedFarmId !== null) {
          $query['context_farm_id'] = (int) $selectedFarmId;
      }

      if ($selectedDeviceId !== null) {
          $query['context_device_id'] = (int) $selectedDeviceId;
      }

      return route('dashboard', $query);
  };

  $formatInt = static fn ($value): string => number_format((int) ($value ?? 0));
  $formatFloat = static fn ($value, int $decimals = 2): string => number_format((float) ($value ?? 0), $decimals);
  $formatTray = static fn ($value): string => \App\Support\EggTrayFormatter::trayLabel((int) ($value ?? 0));
  $formatActivityTotal = static function (array $row) use ($formatTray): string {
      $label = trim((string) ($row['total_label'] ?? ''));

      return $label !== '' ? $label : $formatTray($row['total'] ?? 0);
  };

  $timelineNow = 0;
  $timelineTotal = 0;
  foreach ($timeline as $point) {
      $eggs = (int) ($point['eggs'] ?? 0);
      $timelineTotal += $eggs;
      $timelineNow = $eggs;
  }

  $sizeClassColorMap = [];
  foreach ($sizeBreakdown as $row) {
      $label = (string) ($row['size_class'] ?? '');
      $color = (string) ($row['color'] ?? '');

      if ($label !== '' && $color !== '') {
          $sizeClassColorMap[$label] = $color;
      }
  }

  $appBasePath = trim((string) config('app.base_path', ''), '/');
  $appBaseUrlPath = $appBasePath === '' ? '' : '/' . $appBasePath;
  $sneatAssetsBase = $appBaseUrlPath . '/sneat/assets';
  $curatedIcon = static fn (string $path): string => asset('assets/icons/curated/' . ltrim($path, '/'));
  $topActiveIconMap = [
      'bx-home-smile' => $curatedIcon('ui-flat/farm.png'),
      'bx-chip' => $curatedIcon('ui-flat/devices.png'),
      'default' => $curatedIcon('ui-flat/eggs.png'),
  ];
@endphp

@push('styles')
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/css/dashboard-admin.css" />
@endpush

@section('content')
  <div id="dashboardAutoFitRoot">
    <div id="dashboardApp" class="dashboard-compact" data-dashboard-data-url="{{ route('dashboard.data') }}"
      data-dashboard-view-url="{{ route('dashboard') }}"
      data-timezone="{{ $appTimezoneCode ?? \App\Support\AppTimezone::current() }}">
    <span class="visually-hidden">Real-Time Poultry Monitoring</span>

    <div class="card mb-1 dashboard-toolbar-card">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-2">
          <div class="dashboard-toolbar-meta">
            <div class="btn-group dashboard-range-group" role="group" aria-label="Dashboard range">
              @foreach (($dashboardRangeOptions ?? []) as $rangeValue => $rangeLabel)
                <a href="{{ $rangeUrl((string) $rangeValue) }}"
                  class="btn btn-sm @if ($selectedRange === (string) $rangeValue) btn-primary @else btn-outline-primary @endif">
                  {{ $rangeLabel }}
                </a>
              @endforeach
            </div>
            <span class="dashboard-toolbar-asof">
              @include('partials.curated-shell-icon', [
                'src' => 'assets/icons/curated/ui-flat/signal.png',
                'alt' => 'Refresh status',
                'classes' => 'app-shell-icon--kicker',
              ])
              <span id="dashboardAsOfLabel">As of {{ \App\Support\AppTimezone::formatDateTime(now(), 'M j, g:i A') }}</span>
            </span>
          </div>

          <form method="GET" id="dashboardContextForm" class="row g-0 align-items-end dashboard-context-form">
            <input type="hidden" name="range" value="{{ $selectedRange }}" />

            <div class="col-sm-auto">
              <label for="dashboardFarmSwitcher" class="form-label mb-1">Farm</label>
              <select name="context_farm_id" id="dashboardFarmSwitcher" class="form-select form-select-sm">
                <option value="">All Farms</option>
                @foreach (($context['switcher']['farms'] ?? []) as $farmOption)
                  <option value="{{ $farmOption['id'] }}" @selected((int) ($selectedFarmId ?? 0) === (int) $farmOption['id'])>
                    {{ $farmOption['name'] }}
                  </option>
                @endforeach
              </select>
            </div>

            <div class="col-sm-auto">
              <label for="dashboardDeviceSwitcher" class="form-label mb-1">Device</label>
              <select name="context_device_id" id="dashboardDeviceSwitcher" class="form-select form-select-sm">
                <option value="">All Devices</option>
                @foreach (($context['switcher']['devices'] ?? []) as $deviceOption)
                  <option value="{{ $deviceOption['id'] }}"
                    data-farm-id="{{ $deviceOption['farm_id'] ?? '' }}"
                    @selected((int) ($selectedDeviceId ?? 0) === (int) $deviceOption['id'])>
                    {{ $deviceOption['name'] }} ({{ $deviceOption['serial'] }})
                  </option>
                @endforeach
              </select>
            </div>

            <div class="col-sm-auto">
              <button type="submit" class="btn btn-sm btn-primary">Apply</button>
              <a href="{{ route('dashboard', ['range' => $selectedRange]) }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="card mb-1 dashboard-panel-card dashboard-poultry-hero-card">
      <div class="card-body">
        <div class="dashboard-poultry-hero-grid">
          <div class="dashboard-poultry-hero-lead">
            <span class="dashboard-poultry-hero-icon">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/animals/icons/icons8-chicken.png',
                'alt' => 'Poultry lead',
                'classes' => 'app-shell-icon--hero',
              ])
            </span>
            <div>
              <div class="dashboard-section-kicker mb-1">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/internet/animated/icons8-wifi--v2.gif',
                  'alt' => 'Live ingest',
                  'classes' => 'app-shell-icon--kicker',
                ])Flock Live Feed
              </div>
              <h5 class="mb-1">Real-time poultry sorting and network watch</h5>
              <p class="mb-0 text-body-secondary">Track egg output, device health, and farm coverage with poultry-focused cues instead of generic office visuals.</p>
            </div>
          </div>

          <div class="dashboard-poultry-signal">
            @include('partials.curated-shell-icon', [
              'src' => 'resources/icons/dusk/clock/animated/icons8-clock--v2.gif',
              'alt' => 'Cycle pulse',
              'classes' => 'app-shell-icon--signal',
            ])
            <div>
              <small class="text-body-secondary">Cycle Pulse</small>
              <strong id="dashboardCyclePulseCount">{{ $formatInt($timelineNow) }} eggs now</strong>
              <small class="text-body-secondary" id="dashboardCyclePulseTrays">{{ $formatTray($timelineNow) }}</small>
            </div>
          </div>

          <div class="dashboard-poultry-signal">
            @include('partials.curated-shell-icon', [
              'src' => 'resources/icons/dusk/product/icons/icons8-sensor.png',
              'alt' => 'Sensor watch',
              'classes' => 'app-shell-icon--signal',
            ])
            <div>
              <small class="text-body-secondary">Sensor Watch</small>
              <strong>{{ $profile['ingest_health'] ?? 'No active device selected' }}</strong>
            </div>
          </div>

          <div class="dashboard-poultry-signal">
            @include('partials.curated-shell-icon', [
              'src' => 'resources/icons/dusk/location/animated/icons8-compass--v2.gif',
              'alt' => 'Farm coverage',
              'classes' => 'app-shell-icon--signal',
            ])
            <div>
              <small class="text-body-secondary">Farm Coverage</small>
              <strong>{{ $formatInt($summary['active_farms'] ?? 0) }} farms / {{ $formatInt($summary['active_devices'] ?? 0) }} devices</strong>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-1 mb-0">
      <div class="col-sm-6 col-xl-3">
        <div class="card h-100 dashboard-panel-card dashboard-metric-card dashboard-metric-card-eggs">
          <div class="card-body">
            <span class="dashboard-metric-icon">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/food/icons/icons8-kawaii-egg.png',
                'alt' => 'Total eggs',
                'classes' => 'app-shell-icon--metric',
              ])
            </span>
            <div>
              <small class="text-body-secondary">Total Eggs</small>
              <h3 class="dashboard-summary-title" id="dashboardSummaryTotalEggs">{{ $formatInt($summary['total_eggs'] ?? 0) }}</h3>
              <small class="text-body-secondary" id="dashboardSummaryTotalEggsTrays">{{ $formatTray($summary['total_eggs'] ?? 0) }}</small>
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="card h-100 dashboard-panel-card dashboard-metric-card dashboard-metric-card-weight">
          <div class="card-body">
            <span class="dashboard-metric-icon">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/business/animated/icons8-combo-chart--v2.gif',
                'alt' => 'Total weight',
                'classes' => 'app-shell-icon--metric',
              ])
            </span>
            <div>
              <small class="text-body-secondary">Total Weight</small>
              <h3 class="dashboard-summary-title" id="dashboardSummaryWeight">{{ $formatFloat($summary['total_weight_grams'] ?? 0) }} g</h3>
              <small class="text-body-secondary">Combined ingest and manual intake</small>
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="card h-100 dashboard-panel-card dashboard-metric-card dashboard-metric-card-quality" id="dashboardQualityCard">
          <div class="card-body">
            <span class="dashboard-metric-icon">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/business/animated/icons8-pie-chart--v2.gif',
                'alt' => 'Quality score',
                'classes' => 'app-shell-icon--metric',
              ])
            </span>
            <div>
              <small class="text-body-secondary">Quality Score</small>
              <h3 class="dashboard-summary-title" id="dashboardSummaryQuality">{{ $formatFloat($summary['quality_score'] ?? 0, 1) }}%</h3>
              <small class="text-body-secondary">Non-reject egg ratio</small>
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="card h-100 dashboard-panel-card dashboard-metric-card dashboard-metric-card-coverage">
          <div class="card-body">
            <span class="dashboard-metric-icon">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/man/icons/icons8-farmer-male.png',
                'alt' => 'Coverage',
                'classes' => 'app-shell-icon--metric',
              ])
            </span>
            <div>
              <small class="text-body-secondary">Coverage</small>
              <h3 class="dashboard-summary-title">
                <span id="dashboardSummaryActiveFarms">{{ $formatInt($summary['active_farms'] ?? 0) }}</span> farms /
                <span id="dashboardSummaryActiveDevices">{{ $formatInt($summary['active_devices'] ?? 0) }}</span> devices
              </h3>
              <small class="text-body-secondary">Active in this range</small>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-1 mt-0">
      <div class="col-lg-3">
        <div class="card h-100 dashboard-panel-card dashboard-insight-card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <div>
              <div class="dashboard-section-kicker">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/product/icons/icons8-sensor.png',
                  'alt' => 'Device overview',
                  'classes' => 'app-shell-icon--kicker',
                ])Device Overview
              </div>
              <h6 class="mb-0">Device Profile</h6>
            </div>
            <span class="badge bg-label-secondary" id="dashboardProfileHealthBadge">{{ $profile['ingest_health'] ?? 'No active device selected' }}</span>
          </div>
          <div class="card-body">
            <h6 class="mb-1" id="dashboardProfileDeviceName">{{ $profile['device_name'] ?? 'No active device selected' }}</h6>
            <div class="text-body-secondary small mb-2" id="dashboardProfileDeviceSerial">
              @if (!empty($profile['serial']))
                Serial {{ $profile['serial'] }}
              @else
                Serial not available
              @endif
            </div>

            <ul class="list-unstyled mb-0 dashboard-compact-list">
              <li class="mb-2"><strong>Farm:</strong> <span id="dashboardProfileFarmName">{{ $profile['farm_name'] ?? '-' }}</span></li>
              <li class="mb-2"><strong>Owner:</strong> <span id="dashboardProfileOwnerName">{{ $profile['owner_name'] ?? '-' }}</span></li>
              <li class="mb-2"><strong>Last Seen:</strong> <span id="dashboardProfileLastSeen">{{ $profile['last_seen_label'] ?? 'No signal' }}</span></li>
              <li class="mb-2"><strong>Events/Minute:</strong> <span id="dashboardEventsPerMinute">{{ $formatFloat($profile['events_per_minute'] ?? 0) }}</span></li>
              <li class="dashboard-profile-status"><strong>Status:</strong> <span id="dashboardProfileHealth" class="dashboard-status-pill">{{ $profile['ingest_health'] ?? 'No active device selected' }}</span></li>
            </ul>
          </div>
        </div>
      </div>

      <div class="col-lg-9">
        <div class="card h-100 dashboard-panel-card dashboard-insight-card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <div>
              <div class="dashboard-section-kicker">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/business/animated/icons8-combo-chart--v2.gif',
                  'alt' => 'Trend monitor',
                  'classes' => 'app-shell-icon--kicker',
                ])Trend Monitor
              </div>
              <h6 class="mb-0">Production Timeline</h6>
              <small class="text-body-secondary"><span id="dashboardTimelineNow">{{ $formatInt($timelineNow) }}</span> now / <span
                  id="dashboardTimelineTotal">{{ $formatInt($timelineTotal) }}</span> total</small>
              <small class="text-body-secondary d-block" id="dashboardTimelineTotalTrays">{{ $formatTray($timelineTotal) }}</small>
            </div>
            <span class="badge bg-label-primary">Range {{ strtoupper($selectedRange) }}</span>
          </div>
          <div class="card-body">
            <div id="dashboardTimelineChart" class="dashboard-chart"></div>
            <small class="text-body-secondary d-block mt-1" id="dashboardRefreshMessage"></small>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-1 mt-0">
      <div class="col-lg-4">
        <div class="card h-100 dashboard-panel-card dashboard-insight-card">
          <div class="card-header">
            <div class="dashboard-section-kicker">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/food/icons/icons8-kawaii-egg.png',
                'alt' => 'Class performance',
                'classes' => 'app-shell-icon--kicker',
              ])Class Performance
            </div>
            <h6 class="mb-0">Size Breakdown</h6>
          </div>
          <div class="table-responsive text-nowrap">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>Class</th>
                  <th class="text-end">Eggs</th>
                  <th class="text-end">Share</th>
                  <th class="text-end">Avg Weight</th>
                </tr>
              </thead>
              <tbody id="dashboardSizeTableBody">
                @forelse ($sizeBreakdown as $row)
                  <tr>
                    <td>
                      <span class="dashboard-class-chip" style="--dashboard-chip-color: {{ $row['color'] ?? '#6b7280' }};">
                        {{ $row['size_class'] ?? '-' }}
                      </span>
                    </td>
                    <td class="text-end">
                      <span class="d-block">{{ $formatInt($row['count'] ?? 0) }}</span>
                      <small class="text-body-secondary">{{ $formatTray($row['count'] ?? 0) }}</small>
                    </td>
                    <td class="text-end">{{ $formatFloat($row['percent'] ?? 0, 1) }}%</td>
                    <td class="text-end">{{ $formatFloat($row['avg_weight'] ?? 0) }} g</td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="4" class="text-center text-body-secondary py-2">No records in selected range.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card h-100 dashboard-panel-card dashboard-insight-card">
          <div class="card-header">
            <div class="dashboard-section-kicker">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/internet/animated/icons8-wifi--v2.gif',
                'alt' => 'Data sources',
                'classes' => 'app-shell-icon--kicker',
              ])Data Sources
            </div>
            <h6 class="mb-0">Activity Breakdown</h6>
          </div>
          <div class="table-responsive text-nowrap">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>Source</th>
                  <th class="text-end">Total</th>
                  <th class="text-end">Activity</th>
                  <th class="text-end">Score</th>
                </tr>
              </thead>
              <tbody id="dashboardActivityTableBody">
                @forelse ($activityBreakdown as $row)
                  <tr>
                    <td>
                      <span class="dashboard-source-chip" style="--dashboard-chip-color: {{ $row['color'] ?? '#6b7280' }};">
                        {{ $row['label'] ?? '-' }}
                      </span>
                    </td>
                    <td class="text-end">
                      <span class="d-block">{{ $formatInt($row['total'] ?? 0) }}</span>
                      <small class="text-body-secondary">{{ $formatActivityTotal($row) }}</small>
                    </td>
                    <td class="text-end">{{ $formatFloat($row['activity_percent'] ?? 0, 1) }}%</td>
                    <td class="text-end">{{ $formatFloat($row['score_percent'] ?? 0, 1) }}%</td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="4" class="text-center text-body-secondary py-2">No records in selected range.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card h-100 dashboard-panel-card dashboard-insight-card">
          <div class="card-header">
            <div class="dashboard-section-kicker">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/animals/icons/icons8-chicken.png',
                'alt' => 'Leaderboard',
                'classes' => 'app-shell-icon--kicker',
              ])Leaderboard
            </div>
            <h6 class="mb-0">Top Active Farms / Devices</h6>
          </div>
          <div class="card-body">
            <ul class="list-group list-group-flush dashboard-top-active-list" id="dashboardTopActiveList">
              @forelse ($topActive as $item)
                @php
                  $topLabel = (string) ($item['label'] ?? '-');
                  $topColor = $sizeClassColorMap[$topLabel] ?? '#696cff';
                @endphp
                <li class="list-group-item px-0 d-flex justify-content-between align-items-center dashboard-top-active-item">
                  <div class="dashboard-top-active-label">
                    <span class="dashboard-top-active-icon" style="background: {{ $topColor }}20; color: {{ $topColor }};">
                      @include('partials.curated-shell-icon', [
                        'src' => $topActiveIconMap[$item['icon'] ?? ''] ?? $topActiveIconMap['default'],
                        'alt' => $topLabel,
                        'classes' => 'app-shell-icon--top-active',
                      ])
                    </span>
                    <div>
                      <strong>{{ $topLabel }}</strong>
                      <div class="text-body-secondary small">{{ $item['sub_label'] ?? '' }}</div>
                    </div>
                  </div>
                  <span class="badge dashboard-top-active-count" style="background: {{ $topColor }}20; color: {{ $topColor }};">{{ $formatInt($item['value'] ?? 0) }}</span>
                </li>
              @empty
                <li class="list-group-item px-0 text-body-secondary">No activity records yet.</li>
              @endforelse
            </ul>
          </div>
        </div>
      </div>
    </div>
    </div>
  </div>

  <script type="application/json"
    id="dashboardInitialPayload">{!! json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

  <script src="{{ $sneatAssetsBase }}/vendor/libs/apex-charts/apexcharts.js"></script>
  <script>
    (function() {
      const appRoot = document.getElementById('dashboardApp');
      if (!appRoot) {
        return;
      }

      const dataUrl = appRoot.dataset.dashboardDataUrl || '';
      const farmSwitcher = document.getElementById('dashboardFarmSwitcher');
      const deviceSwitcher = document.getElementById('dashboardDeviceSwitcher');
      const contextForm = document.getElementById('dashboardContextForm');
      const refreshMessage = document.getElementById('dashboardRefreshMessage');
      const chartRoot = document.getElementById('dashboardTimelineChart');
      const autoFitRoot = document.getElementById('dashboardAutoFitRoot');
      const autoFitBaseScale = 1;
      const chartFontFamily = resolveChartFontFamily();
      const appTimezone = appRoot.dataset.timezone || 'Asia/Manila';
      const asOfFormatter = new Intl.DateTimeFormat('en-US', {
        timeZone: appTimezone,
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      });
      const timeOnlyFormatter = new Intl.DateTimeFormat('en-US', {
        timeZone: appTimezone,
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
      });
      const topActiveIconMap = @json($topActiveIconMap);
      let timelineChart = null;
      let fitRaf = 0;

      const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, function(match) {
        return ({
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#039;'
        })[match] || match;
      });

      const formatInt = (value) => new Intl.NumberFormat().format(Number(value || 0));
      const formatDecimal = (value, digits = 2) => Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits
      });
      const formatTrayCount = (value) => {
        const normalized = Math.max(0, Number(value || 0));
        const fullTrays = Math.floor(normalized / 30);
        let remainder = normalized % 30;
        const parts = [];

        if (fullTrays > 0) {
          parts.push(`${fullTrays} tray${fullTrays === 1 ? '' : 's'}`);
        }

        if (remainder >= 15) {
          parts.push('1/2 tray');
          remainder -= 15;
        }

        if (remainder > 0 || parts.length === 0) {
          parts.push(`${remainder} egg${remainder === 1 ? '' : 's'}`);
        }

        return parts.join(' + ');
      };
      const formatActivityTotal = (row) => {
        const label = String(row && row.total_label ? row.total_label : '').trim();
        return label !== '' ? label : formatTrayCount(row && row.total ? row.total : 0);
      };

      const sizeClassColorSource = {!! json_encode($sizeClassColorMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
      const sizeClassColorMap = new Map(Object.entries(sizeClassColorSource || {}));

      const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) {
          el.textContent = value;
        }
      };

      const formatAsOf = (iso) => {
        if (!iso) {
          return 'As of --';
        }

        const parsed = new Date(iso);
        if (Number.isNaN(parsed.getTime())) {
          return 'As of --';
        }

        return `As of ${asOfFormatter.format(parsed)}`;
      };

      const resetAutoFit = () => {
        if (!autoFitRoot) {
          return;
        }

        appRoot.style.transform = 'none';
        appRoot.style.width = '100%';
        autoFitRoot.style.height = 'auto';
      };

      const applyAutoFit = () => {
        if (!autoFitRoot) {
          return;
        }

        if (window.innerWidth < 992) {
          resetAutoFit();
          return;
        }

        appRoot.style.transform = 'none';
        appRoot.style.width = '100%';
        autoFitRoot.style.height = 'auto';

        const topOffset = autoFitRoot.getBoundingClientRect().top;
        const availableHeight = Math.max(220, window.innerHeight - topOffset - 8);
        const naturalHeight = Math.max(appRoot.scrollHeight, 1);
        const computedScale = availableHeight / naturalHeight;
        const fitScale = Math.max(0.82, Math.min(autoFitBaseScale, computedScale));

        appRoot.style.transform = `scale(${fitScale})`;
        appRoot.style.width = `${100 / fitScale}%`;
        autoFitRoot.style.height = `${Math.ceil(naturalHeight * fitScale)}px`;
      };

      const queueAutoFit = () => {
        if (!autoFitRoot) {
          return;
        }

        if (fitRaf) {
          window.cancelAnimationFrame(fitRaf);
        }

        fitRaf = window.requestAnimationFrame(function() {
          fitRaf = 0;
          applyAutoFit();
        });
      };

      const updateDeviceOptionsByFarm = () => {
        if (!farmSwitcher || !deviceSwitcher) {
          return;
        }

        const selectedFarm = farmSwitcher.value;
        let selectedOptionHidden = false;

        Array.from(deviceSwitcher.options).forEach((option) => {
          if (option.value === '') {
            option.hidden = false;
            return;
          }

          const farmId = option.getAttribute('data-farm-id') || '';
          const visible = selectedFarm === '' || farmId === '' || farmId === selectedFarm;
          option.hidden = !visible;

          if (!visible && option.selected) {
            selectedOptionHidden = true;
          }
        });

        if (selectedOptionHidden) {
          deviceSwitcher.value = '';
        }
      };

      const renderSizeBreakdown = (rows) => {
        const tbody = document.getElementById('dashboardSizeTableBody');
        if (!tbody) {
          return;
        }

        const list = Array.isArray(rows) ? rows : [];
        if (list.length === 0) {
          tbody.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary py-2">No records in selected range.</td></tr>';
          return;
        }

        tbody.innerHTML = list.map((row) => `
          <tr>
            <td>
              <span class="dashboard-class-chip" style="--dashboard-chip-color: ${escapeHtml(row.color || '#6b7280')};">
                ${escapeHtml(row.size_class || '-')}
              </span>
            </td>
            <td class="text-end">
              <span class="d-block">${formatInt(row.count)}</span>
              <small class="text-body-secondary">${escapeHtml(formatTrayCount(row.count))}</small>
            </td>
            <td class="text-end">${formatDecimal(row.percent, 1)}%</td>
            <td class="text-end">${formatDecimal(row.avg_weight)} g</td>
          </tr>
        `).join('');
      };

      const renderActivityBreakdown = (rows) => {
        const tbody = document.getElementById('dashboardActivityTableBody');
        if (!tbody) {
          return;
        }

        const list = Array.isArray(rows) ? rows : [];
        if (list.length === 0) {
          tbody.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary py-2">No records in selected range.</td></tr>';
          return;
        }

        tbody.innerHTML = list.map((row) => `
          <tr>
            <td>
              <span class="dashboard-source-chip" style="--dashboard-chip-color: ${escapeHtml(row.color || '#6b7280')};">
                ${escapeHtml(row.label || '-')}
              </span>
            </td>
            <td class="text-end">
              <span class="d-block">${formatInt(row.total)}</span>
              <small class="text-body-secondary">${escapeHtml(formatActivityTotal(row))}</small>
            </td>
            <td class="text-end">${formatDecimal(row.activity_percent, 1)}%</td>
            <td class="text-end">${formatDecimal(row.score_percent, 1)}%</td>
          </tr>
        `).join('');
      };

      const renderTopActive = (rows) => {
        const listEl = document.getElementById('dashboardTopActiveList');
        if (!listEl) {
          return;
        }

        const list = Array.isArray(rows) ? rows : [];
        if (list.length === 0) {
          listEl.innerHTML = '<li class="list-group-item px-0 text-body-secondary">No activity records yet.</li>';
          return;
        }

        const resolveTopColor = (row) => {
          const label = String(row.label || '');
          if (sizeClassColorMap.has(label)) {
            return sizeClassColorMap.get(label);
          }

          const icon = String(row.icon || '');
          if (icon === 'bx-home-smile') {
            return '#3bb273';
          }

          if (icon === 'bx-chip') {
            return '#2f80ed';
          }

          return '#696cff';
        };

        listEl.innerHTML = list.map((row) => `
          <li class="list-group-item px-0 d-flex justify-content-between align-items-center dashboard-top-active-item">
            <div class="dashboard-top-active-label">
              <span class="dashboard-top-active-icon" style="background: ${resolveTopColor(row)}20; color: ${resolveTopColor(row)};">
                <img src="${escapeHtml(topActiveIconMap[row.icon] || topActiveIconMap.default)}" alt="" class="app-shell-icon app-shell-icon--top-active" />
              </span>
              <div>
                <strong>${escapeHtml(row.label || '-')}</strong>
                <div class="text-body-secondary small">${escapeHtml(row.sub_label || '')}</div>
              </div>
            </div>
            <span class="badge dashboard-top-active-count" style="background: ${resolveTopColor(row)}20; color: ${resolveTopColor(row)};">${formatInt(row.value)}</span>
          </li>
        `).join('');
      };

      const renderTimeline = (rows, rangeLabel) => {
        const list = Array.isArray(rows) ? rows : [];
        const labels = list.map((point) => point.label || '');
        const eggs = list.map((point) => Number(point.eggs || 0));
        const quality = list.map((point) => Number(point.quality_score || 0));

        const total = eggs.reduce((sum, value) => sum + value, 0);
        const now = eggs.length > 0 ? eggs[eggs.length - 1] : 0;

        setText('dashboardTimelineNow', formatInt(now));
        setText('dashboardTimelineTotal', formatInt(total));

        if (!window.ApexCharts || !chartRoot) {
          return;
        }

        const options = {
          chart: {
            type: 'line',
            height: 165,
            fontFamily: chartFontFamily,
            toolbar: {
              show: false
            },
          },
          stroke: {
            width: [3, 2],
            curve: 'smooth',
          },
          colors: ['#696cff', '#71dd37'],
          series: [{
              name: 'Eggs',
              data: eggs,
            },
            {
              name: 'Quality %',
              data: quality,
            },
          ],
          labels,
          yaxis: [{
              title: {
                text: 'Eggs'
              },
            },
            {
              opposite: true,
              max: 100,
              min: 0,
              title: {
                text: 'Quality %'
              },
            },
          ],
          xaxis: {
            labels: {
              trim: true,
              style: {
                fontFamily: chartFontFamily,
              },
            },
          },
          tooltip: {
            shared: true,
          },
          legend: {
            position: 'top',
            fontFamily: chartFontFamily,
          },
          noData: {
            text: 'No timeline data',
          },
        };

        if (timelineChart) {
          timelineChart.updateOptions(options);
        } else {
          timelineChart = new ApexCharts(chartRoot, options);
          timelineChart.render();
        }
      };

      const applyPayload = (payload) => {
        const summary = payload && payload.summary ? payload.summary : {};
        const profile = summary.profile || {};
        const timelineRows = Array.isArray(payload && payload.timeline ? payload.timeline : []) ? payload.timeline : [];
        const timelineTotal = timelineRows.reduce((sum, point) => sum + Number(point.eggs || 0), 0);
        const timelineNow = timelineRows.length > 0 ? Number(timelineRows[timelineRows.length - 1].eggs || 0) : 0;

        setText('dashboardAsOfLabel', formatAsOf(payload ? payload.as_of : null));
        setText('dashboardSummaryTotalEggs', formatInt(summary.total_eggs));
        setText('dashboardSummaryTotalEggsTrays', formatTrayCount(summary.total_eggs));
        setText('dashboardSummaryWeight', `${formatDecimal(summary.total_weight_grams)} g`);
        setText('dashboardSummaryQuality', `${formatDecimal(summary.quality_score, 1)}%`);
        setText('dashboardSummaryActiveFarms', formatInt(summary.active_farms));
        setText('dashboardSummaryActiveDevices', formatInt(summary.active_devices));

        setText('dashboardProfileDeviceName', profile.device_name || 'No active device selected');
        setText('dashboardProfileDeviceSerial', profile.serial ? `Serial ${profile.serial}` : 'Serial not available');
        setText('dashboardProfileFarmName', profile.farm_name || '-');
        setText('dashboardProfileOwnerName', profile.owner_name || '-');
        setText('dashboardProfileLastSeen', profile.last_seen_label || 'No signal');
        setText('dashboardProfileHealth', profile.ingest_health || 'No active device selected');
        setText('dashboardEventsPerMinute', formatDecimal(profile.events_per_minute));
        setText('dashboardCyclePulseCount', `${formatInt(timelineNow)} eggs now`);
        setText('dashboardCyclePulseTrays', formatTrayCount(timelineNow));

        const qualityCard = document.getElementById('dashboardQualityCard');
        if (qualityCard) {
          qualityCard.classList.remove('is-warn', 'is-bad');
          const quality = Number(summary.quality_score || 0);
          if (quality < 60) {
            qualityCard.classList.add('is-bad');
          } else if (quality < 85) {
            qualityCard.classList.add('is-warn');
          }
        }

        const healthBadge = document.getElementById('dashboardProfileHealthBadge');
        if (healthBadge) {
          healthBadge.classList.remove('bg-label-success', 'bg-label-warning', 'bg-label-danger', 'bg-label-secondary');
          const tone = String(profile.ingest_health_tone || 'neutral');
          if (tone === 'good') {
            healthBadge.classList.add('bg-label-success');
          } else if (tone === 'warn') {
            healthBadge.classList.add('bg-label-warning');
          } else if (tone === 'bad') {
            healthBadge.classList.add('bg-label-danger');
          } else {
            healthBadge.classList.add('bg-label-secondary');
          }
          healthBadge.textContent = profile.ingest_health || 'No active device selected';
        }

        const healthStatus = document.getElementById('dashboardProfileHealth');
        if (healthStatus) {
          healthStatus.classList.remove('text-success', 'text-warning', 'text-danger', 'text-body-secondary');
          const tone = String(profile.ingest_health_tone || 'neutral');
          if (tone === 'good') {
            healthStatus.classList.add('text-success');
          } else if (tone === 'warn') {
            healthStatus.classList.add('text-warning');
          } else if (tone === 'bad') {
            healthStatus.classList.add('text-danger');
          } else {
            healthStatus.classList.add('text-body-secondary');
          }
        }

        renderSizeBreakdown(payload ? payload.size_breakdown : []);
        renderActivityBreakdown(payload ? payload.activity_breakdown : []);
        renderTopActive(payload ? payload.top_active : []);
        renderTimeline(timelineRows, payload ? payload.range : '');
        setText('dashboardTimelineTotalTrays', formatTrayCount(timelineTotal));
        queueAutoFit();
      };

      const refreshPayload = async () => {
        if (!dataUrl) {
          return;
        }

        try {
          const response = await fetch(dataUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
              Accept: 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
          });

          if (!response.ok) {
            throw new Error(`Request failed (${response.status})`);
          }

          const payload = await response.json();
          if (!payload || payload.ok === false) {
            throw new Error(payload && payload.message ? payload.message : 'Dashboard refresh failed.');
          }

          applyPayload(payload);
          if (refreshMessage) {
            refreshMessage.textContent = `Last refresh: ${timeOnlyFormatter.format(new Date())}`;
          }
        } catch (error) {
          if (refreshMessage) {
            refreshMessage.textContent = error instanceof Error ? error.message : 'Unable to refresh dashboard.';
          }
        }
      };

      if (farmSwitcher) {
        farmSwitcher.addEventListener('change', function() {
          updateDeviceOptionsByFarm();
        });
      }

      updateDeviceOptionsByFarm();

      const initialPayloadEl = document.getElementById('dashboardInitialPayload');
      if (initialPayloadEl) {
        try {
          const initialPayload = JSON.parse(initialPayloadEl.textContent || '{}');
          applyPayload(initialPayload);
        } catch (error) {
          if (refreshMessage) {
            refreshMessage.textContent = 'Unable to read initial dashboard payload.';
          }
        }
      }

      window.addEventListener('resize', function() {
        queueAutoFit();
      });

      window.addEventListener('orientationchange', function() {
        queueAutoFit();
      });

      queueAutoFit();
      window.setInterval(refreshPayload, 10000);

      function resolveChartFontFamily() {
        const fromCssVar = window
          .getComputedStyle(document.documentElement)
          .getPropertyValue('--bs-body-font-family')
          .trim();

        return fromCssVar || "'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
      }
    })();
  </script>
@endsection
