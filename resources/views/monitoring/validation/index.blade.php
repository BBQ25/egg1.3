@extends('layouts.admin')

@section('title', 'APEWSD - Validation & Accuracy')

@section('content')
  @php
    $payload = $validationPayload ?? [];
    $isValidationAvailable = (bool) ($payload['available'] ?? true);
    $missingValidationTables = is_array($payload['missing_tables'] ?? null) ? $payload['missing_tables'] : [];
    $summary = $payload['summary'] ?? (object) [];
    $runs = $payload['runs'] ?? collect();
    $selectedRun = $payload['selected_run'] ?? null;
    $measurements = $payload['measurements'] ?? collect();
    $candidateRecords = $payload['candidate_records'] ?? collect();
    $confusionMatrix = $payload['confusion_matrix'] ?? ['columns' => [], 'rows' => []];
    $window = $payload['window'] ?? [];
    $context = $validationContext ?? [];
    $filters = $filters ?? [];
    $selectedFarmId = $context['selected']['farm_id'] ?? null;
    $selectedDeviceId = $context['selected']['device_id'] ?? null;
    $selectedRange = (string) ($selectedRange ?? '1d');
    $selectedStatus = (string) ($filters['status'] ?? 'all');
    $selectedRunId = $selectedRun?->id ?? ($filters['run_id'] ?? null);
    $rangeOptions = $rangeOptions ?? [];
    $statusOptions = $statusOptions ?? [];
    $sizeClassOptions = $sizeClassOptions ?? [];

    $formatInt = static fn ($value): string => number_format((int) ($value ?? 0));
    $formatWeight = static fn ($value): string => number_format((float) ($value ?? 0), 2) . ' g';
    $formatPercent = static fn ($value): string => number_format((float) ($value ?? 0), 2) . '%';
    $formatDateTime = static function ($value): string {
        if (!$value) {
            return 'N/A';
        }

        return \Illuminate\Support\Carbon::parse($value)->format('M j, Y g:i A');
    };
    $runStatusTheme = static function ($status): string {
        return match ((string) $status) {
            'completed' => 'bg-label-success',
            default => 'bg-label-warning',
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
    .validation-shell {
      display: grid;
      gap: 1.5rem;
    }

    .validation-hero,
    .validation-card {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.35rem;
      background: #fff;
      box-shadow: 0 0.9rem 2rem rgba(67, 89, 113, 0.08);
    }

    .validation-hero {
      overflow: hidden;
      background:
        radial-gradient(circle at top right, rgba(32, 201, 151, 0.12), transparent 30%),
        linear-gradient(135deg, #f5fff8 0%, #ffffff 45%, #f4f7ff 100%);
    }

    .validation-hero-body,
    .validation-card-body {
      padding: 1.35rem 1.45rem;
    }

    .validation-kicker {
      font-size: 0.75rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      font-weight: 700;
      color: #8592a3;
    }

    .validation-title {
      margin: 0.35rem 0 0;
      font-size: clamp(1.45rem, 1.2rem + 0.5vw, 2rem);
      line-height: 1.08;
      letter-spacing: -0.03em;
      color: #243448;
    }

    .validation-lead {
      max-width: 58rem;
      color: #66788a;
      margin: 0.7rem 0 0;
    }

    .validation-pill-row,
    .validation-filter-row {
      display: flex;
      flex-wrap: wrap;
      gap: 0.85rem;
    }

    .validation-pill {
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

    .validation-metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 0.85rem;
    }

    .validation-metric {
      padding: 1rem 1.05rem;
      border-radius: 1rem;
      background: rgba(255, 255, 255, 0.92);
      border: 1px solid rgba(67, 89, 113, 0.1);
    }

    .validation-metric-label {
      font-size: 0.73rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #8592a3;
    }

    .validation-metric-value {
      margin-top: 0.35rem;
      font-size: 1.45rem;
      line-height: 1;
      font-weight: 800;
      color: #243448;
    }

    .validation-grid {
      display: grid;
      grid-template-columns: 0.95fr 1.45fr;
      gap: 1.5rem;
      align-items: start;
    }

    .validation-table-wrap {
      overflow-x: auto;
    }

    .validation-empty {
      padding: 1.8rem;
      text-align: center;
      color: #6e7f92;
      border: 1px dashed rgba(67, 89, 113, 0.18);
      border-radius: 1rem;
      background: rgba(245, 247, 250, 0.72);
    }

    .validation-run-metrics {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 0.85rem;
    }

    @media (max-width: 1199.98px) {
      .validation-grid,
      .validation-metrics,
      .validation-run-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 767.98px) {
      .validation-grid,
      .validation-metrics,
      .validation-run-metrics {
        grid-template-columns: minmax(0, 1fr);
      }
    }
  </style>

  <div class="validation-shell">
    <section class="validation-hero">
      <div class="validation-hero-body">
        <div class="validation-kicker">Thesis Evaluation</div>
        <h1 class="validation-title">Validation &amp; Accuracy</h1>
        <p class="validation-lead">
          Record reference-scale measurements, compare manual and automated size classifications, and generate run-based accuracy evidence for Chapter IV and defense reporting.
        </p>

        <div class="validation-pill-row mt-3">
          <span class="validation-pill">Window: {{ $formatDateTime($window['start'] ?? null) }} to {{ $formatDateTime($window['end'] ?? null) }}</span>
          <span class="validation-pill">Selected run: {{ $selectedRun?->run_code ? $selectedRun->run_code . ' · ' . $selectedRun->title : 'No run selected' }}</span>
        </div>
      </div>
    </section>

    @if (session('status'))
      <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    @if (!$isValidationAvailable)
      <div class="alert alert-warning mb-0">
        Validation storage is not ready in this environment yet. Run the pending monitoring migrations first.
        @if ($missingValidationTables !== [])
          Missing tables: {{ implode(', ', $missingValidationTables) }}.
        @endif
      </div>
    @endif

    <section class="validation-metrics">
      <article class="validation-metric">
        <div class="validation-metric-label">Visible Runs</div>
        <div class="validation-metric-value">{{ $formatInt($summary->total_runs ?? 0) }}</div>
      </article>
      <article class="validation-metric">
        <div class="validation-metric-label">In Progress</div>
        <div class="validation-metric-value">{{ $formatInt($summary->active_runs ?? 0) }}</div>
      </article>
      <article class="validation-metric">
        <div class="validation-metric-label">Completed</div>
        <div class="validation-metric-value">{{ $formatInt($summary->completed_runs ?? 0) }}</div>
      </article>
      <article class="validation-metric">
        <div class="validation-metric-label">Captured Samples</div>
        <div class="validation-metric-value">{{ $formatInt($summary->total_measurements ?? 0) }}</div>
      </article>
    </section>

    <section class="validation-card">
      <div class="validation-card-body">
        <form method="GET" class="validation-filter-row align-items-end justify-content-between">
          <div class="validation-filter-row align-items-end">
            <div>
              <label for="validation_range" class="form-label mb-1">Range</label>
              <select id="validation_range" name="range" class="form-select">
                @foreach ($rangeOptions as $rangeValue => $rangeLabel)
                  <option value="{{ $rangeValue }}" @selected($selectedRange === (string) $rangeValue)>{{ $rangeLabel }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="validation_farm" class="form-label mb-1">Farm</label>
              <select id="validation_farm" name="context_farm_id" class="form-select">
                <option value="">All Farms</option>
                @foreach (($context['switcher']['farms'] ?? []) as $farmOption)
                  <option value="{{ $farmOption['id'] }}" @selected((int) ($selectedFarmId ?? 0) === (int) $farmOption['id'])>{{ $farmOption['name'] }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="validation_device" class="form-label mb-1">Device</label>
              <select id="validation_device" name="context_device_id" class="form-select">
                <option value="">All Devices</option>
                @foreach (($context['switcher']['devices'] ?? []) as $deviceOption)
                  <option value="{{ $deviceOption['id'] }}" @selected((int) ($selectedDeviceId ?? 0) === (int) $deviceOption['id'])>{{ $deviceOption['name'] }} ({{ $deviceOption['serial'] }})</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="validation_status" class="form-label mb-1">Run Status</label>
              <select id="validation_status" name="status" class="form-select">
                @foreach ($statusOptions as $statusValue => $statusLabel)
                  <option value="{{ $statusValue }}" @selected($selectedStatus === (string) $statusValue)>{{ $statusLabel }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="validation_run" class="form-label mb-1">Run</label>
              <select id="validation_run" name="run" class="form-select">
                <option value="">Latest Visible Run</option>
                @foreach ($runs as $run)
                  <option value="{{ $run->id }}" @selected((int) ($selectedRunId ?? 0) === (int) $run->id)>{{ $run->run_code }} · {{ $run->title }}</option>
                @endforeach
              </select>
            </div>
          </div>

          <div class="validation-filter-row align-items-end">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="{{ route('monitoring.validation.index') }}" class="btn btn-outline-secondary">Reset</a>
          </div>
        </form>
      </div>
    </section>

    <section class="validation-grid">
      <article class="validation-card">
        <div class="validation-card-body">
          <h2 class="h5 mb-3">Create Validation Run</h2>
          @if ($isValidationAvailable)
            <form method="POST" action="{{ route('monitoring.validation.runs.store', [
                'range' => $selectedRange,
                'context_farm_id' => $selectedFarmId,
                'context_device_id' => $selectedDeviceId,
                'status' => $selectedStatus,
                'run' => $selectedRunId,
            ]) }}" class="row g-3">
              @csrf
              <div class="col-12">
                <label for="validation_create_farm" class="form-label">Farm</label>
                <select id="validation_create_farm" name="farm_id" class="form-select @error('farm_id') is-invalid @enderror" required>
                  <option value="">Select farm</option>
                  @foreach (($context['switcher']['farms'] ?? []) as $farmOption)
                    <option value="{{ $farmOption['id'] }}" @selected((int) old('farm_id', (int) ($selectedFarmId ?? 0)) === (int) $farmOption['id'])>{{ $farmOption['name'] }}</option>
                  @endforeach
                </select>
                @error('farm_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-12">
                <label for="validation_create_device" class="form-label">Device</label>
                <select id="validation_create_device" name="device_id" class="form-select @error('device_id') is-invalid @enderror" required>
                  <option value="">Select device</option>
                  @foreach (($context['switcher']['devices'] ?? []) as $deviceOption)
                    <option value="{{ $deviceOption['id'] }}" @selected((int) old('device_id', (int) ($selectedDeviceId ?? 0)) === (int) $deviceOption['id'])>{{ $deviceOption['name'] }} ({{ $deviceOption['serial'] }})</option>
                  @endforeach
                </select>
                @error('device_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-md-6">
                <label for="validation_run_code" class="form-label">Run Code</label>
                <input type="text" id="validation_run_code" name="run_code" class="form-control @error('run_code') is-invalid @enderror" value="{{ old('run_code') }}" maxlength="80" placeholder="e.g. RUN-VAL-001" required />
                @error('run_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-md-6">
                <label for="validation_sample_target" class="form-label">Sample Target</label>
                <input type="number" min="1" max="5000" id="validation_sample_target" name="sample_size_target" class="form-control @error('sample_size_target') is-invalid @enderror" value="{{ old('sample_size_target') }}" placeholder="Optional target" />
                @error('sample_size_target')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-12">
                <label for="validation_title_input" class="form-label">Title</label>
                <input type="text" id="validation_title_input" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title') }}" maxlength="150" placeholder="e.g. ESP32 vs Reference Scale Batch A" required />
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-12">
                <label for="validation_run_notes" class="form-label">Notes</label>
                <textarea id="validation_run_notes" name="notes" rows="3" class="form-control @error('notes') is-invalid @enderror" placeholder="Optional operator notes">{{ old('notes') }}</textarea>
                @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="col-12 d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">Open Validation Run</button>
              </div>
            </form>
          @else
            <div class="validation-empty">Validation run storage is unavailable until the pending monitoring migrations are applied.</div>
          @endif
        </div>
      </article>

      <article class="validation-card">
        <div class="validation-card-body">
          <h2 class="h5 mb-3">Visible Evaluation Runs</h2>

          @if ($runs->count() > 0)
            <div class="validation-table-wrap">
              <table class="table table-hover align-middle mb-0">
                <thead>
                  <tr>
                    <th>Run</th>
                    <th>Status</th>
                    <th>Samples</th>
                    <th>MAE</th>
                    <th>Accuracy</th>
                    <th class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach ($runs as $run)
                    <tr>
                      <td>
                        <div class="fw-semibold">{{ $run->run_code }} · {{ $run->title }}</div>
                        <div class="text-body-secondary small">{{ $run->farm_name }} · {{ $run->device_name }} ({{ $run->device_serial }})</div>
                        <div class="text-body-secondary small">Started {{ $formatDateTime($run->started_at) }}</div>
                      </td>
                      <td><span class="badge {{ $runStatusTheme($run->status) }}">{{ $run->status === 'completed' ? 'Completed' : 'In Progress' }}</span></td>
                      <td>
                        <div>{{ $formatInt($run->total_measurements) }}</div>
                        @if ($run->sample_size_target)
                          <div class="text-body-secondary small">Target {{ $formatInt($run->sample_size_target) }} · {{ $formatPercent($run->completion_percent ?? 0) }}</div>
                        @endif
                      </td>
                      <td>{{ $formatWeight($run->mae_grams) }}</td>
                      <td>{{ $formatPercent($run->accuracy_percent) }}</td>
                      <td class="text-end">
                        <a href="{{ route('monitoring.validation.index', array_filter([
                            'range' => $selectedRange,
                            'context_farm_id' => $run->farm_id,
                            'context_device_id' => $run->device_id,
                            'status' => $selectedStatus !== 'all' ? $selectedStatus : null,
                            'run' => $run->id,
                        ], static fn ($value) => $value !== null && $value !== '')) }}" class="btn btn-sm btn-outline-primary">
                          View Run
                        </a>
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @else
            <div class="validation-empty">No validation runs matched the selected monitoring scope.</div>
          @endif
        </div>
      </article>
    </section>

    @if ($selectedRun)
      <section class="validation-card">
        <div class="validation-card-body">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
              <h2 class="h5 mb-1">Selected Run: {{ $selectedRun->run_code }}</h2>
              <div class="text-body-secondary">{{ $selectedRun->title }} · {{ $selectedRun->farm_name }} · {{ $selectedRun->device_name }} ({{ $selectedRun->device_serial }})</div>
            </div>
            <a href="{{ route('monitoring.validation.export', [
                'run' => $selectedRun->id,
                'range' => $selectedRange,
                'context_farm_id' => $selectedRun->farm_id,
                'context_device_id' => $selectedRun->device_id,
            ]) }}" class="btn btn-outline-primary">
              Export Run CSV
            </a>
          </div>

          <div class="validation-run-metrics">
            <article class="validation-metric">
              <div class="validation-metric-label">Samples</div>
              <div class="validation-metric-value">{{ $formatInt($selectedRun->total_measurements) }}</div>
            </article>
            <article class="validation-metric">
              <div class="validation-metric-label">MAE</div>
              <div class="validation-metric-value">{{ $formatWeight($selectedRun->mae_grams) }}</div>
            </article>
            <article class="validation-metric">
              <div class="validation-metric-label">Mean Error</div>
              <div class="validation-metric-value">{{ $formatWeight($selectedRun->mean_error_grams) }}</div>
            </article>
            <article class="validation-metric">
              <div class="validation-metric-label">Class Accuracy</div>
              <div class="validation-metric-value">{{ $formatPercent($selectedRun->accuracy_percent) }}</div>
            </article>
            <article class="validation-metric">
              <div class="validation-metric-label">Last Measured</div>
              <div class="validation-metric-value" style="font-size: 1rem; line-height: 1.3;">{{ $formatDateTime($selectedRun->latest_measured_at) }}</div>
            </article>
          </div>
        </div>
      </section>

      <section class="validation-grid">
        <article class="validation-card">
          <div class="validation-card-body">
            <h2 class="h5 mb-3">Add Reference Measurement</h2>
            @if (!$isValidationAvailable)
              <div class="alert alert-info mb-0">Reference measurement storage is unavailable until the pending monitoring migrations are applied.</div>
            @elseif ($selectedRun->status === 'completed')
              <div class="alert alert-info mb-0">This validation run is completed. Create a new run to record more samples.</div>
            @elseif ($candidateRecords->count() > 0)
              <form method="POST" action="{{ route('monitoring.validation.measurements.store', [
                  'run' => $selectedRun->id,
                  'range' => $selectedRange,
                  'context_farm_id' => $selectedRun->farm_id,
                  'context_device_id' => $selectedRun->device_id,
                  'status' => $selectedStatus,
              ]) }}" class="row g-3">
                @csrf
                <div class="col-12">
                  <label for="validation_event_id" class="form-label">Automated Record</label>
                  <select id="validation_event_id" name="device_ingest_event_id" class="form-select @error('device_ingest_event_id') is-invalid @enderror" required>
                    <option value="">Select ingest record</option>
                    @foreach ($candidateRecords as $record)
                      <option value="{{ $record->id }}" @selected((int) old('device_ingest_event_id') === (int) $record->id)>
                        {{ $record->egg_uid ?: 'No UID' }} · {{ $record->size_class }} · {{ number_format((float) $record->weight_grams, 2) }} g · {{ $record->batch_code ?: 'No batch' }} · {{ $formatDateTime($record->recorded_at) }}
                      </option>
                    @endforeach
                  </select>
                  @error('device_ingest_event_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                  <label for="validation_reference_weight" class="form-label">Reference Weight</label>
                  <input type="number" step="0.01" min="0.01" max="999.99" id="validation_reference_weight" name="reference_weight_grams" class="form-control @error('reference_weight_grams') is-invalid @enderror" value="{{ old('reference_weight_grams') }}" placeholder="e.g. 61.20" required />
                  @error('reference_weight_grams')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                  <label for="validation_manual_size_class" class="form-label">Manual Size Class</label>
                  <select id="validation_manual_size_class" name="manual_size_class" class="form-select @error('manual_size_class') is-invalid @enderror" required>
                    <option value="">Select class</option>
                    @foreach ($sizeClassOptions as $sizeClass)
                      <option value="{{ $sizeClass }}" @selected(old('manual_size_class') === $sizeClass)>{{ $sizeClass }}</option>
                    @endforeach
                  </select>
                  @error('manual_size_class')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                  <label for="validation_measured_at" class="form-label">Measured At</label>
                  <input type="datetime-local" id="validation_measured_at" name="measured_at" class="form-control @error('measured_at') is-invalid @enderror" value="{{ old('measured_at') }}" />
                  @error('measured_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                  <label for="validation_measurement_notes" class="form-label">Notes</label>
                  <textarea id="validation_measurement_notes" name="notes" rows="3" class="form-control @error('notes') is-invalid @enderror" placeholder="Optional panel or operator note">{{ old('notes') }}</textarea>
                  @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 d-flex justify-content-end">
                  <button type="submit" class="btn btn-primary">Record Measurement</button>
                </div>
              </form>
            @else
              <div class="validation-empty">No unmeasured ingest records are available inside this run window.</div>
            @endif
          </div>
        </article>

        <article class="validation-card">
          <div class="validation-card-body">
            <h2 class="h5 mb-3">Confusion Matrix</h2>
            <div class="validation-table-wrap">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Manual \ Automated</th>
                    @foreach ($confusionMatrix['columns'] as $column)
                      <th>{{ $column }}</th>
                    @endforeach
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach ($confusionMatrix['rows'] as $row)
                    <tr>
                      <th>{{ $row['manual_size_class'] }}</th>
                      @foreach ($confusionMatrix['columns'] as $column)
                        <td>{{ $formatInt($row['counts'][$column] ?? 0) }}</td>
                      @endforeach
                      <td>{{ $formatInt($row['total'] ?? 0) }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
        </article>
      </section>

      <section class="validation-card">
        <div class="validation-card-body">
          <h2 class="h5 mb-3">Measured Samples</h2>

          @if ($measurements->count() > 0)
            <div class="validation-table-wrap">
              <table class="table table-hover align-middle mb-0">
                <thead>
                  <tr>
                    <th>Measured At</th>
                    <th>Egg</th>
                    <th>Reference</th>
                    <th>Automated</th>
                    <th>Error</th>
                    <th>Classes</th>
                    <th>Match</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach ($measurements as $measurement)
                    <tr>
                      <td>{{ $formatDateTime($measurement->measured_at) }}</td>
                      <td>
                        <div>{{ $measurement->egg_uid ?: 'No UID' }}</div>
                        <div class="text-body-secondary small">{{ $measurement->batch_code ?: 'No batch' }}</div>
                      </td>
                      <td>{{ $formatWeight($measurement->reference_weight_grams) }}</td>
                      <td>
                        <div>{{ $formatWeight($measurement->automated_weight_grams) }}</div>
                        <div class="text-body-secondary small">Captured {{ $formatDateTime($measurement->automated_recorded_at) }}</div>
                      </td>
                      <td>
                        <div>{{ $formatWeight($measurement->weight_error_grams) }}</div>
                        <div class="text-body-secondary small">Abs {{ $formatWeight($measurement->absolute_error_grams) }}</div>
                      </td>
                      <td>
                        <div><span class="badge {{ $sizeTheme($measurement->manual_size_class) }}">Manual {{ $measurement->manual_size_class }}</span></div>
                        <div class="small mt-1"><span class="badge {{ $sizeTheme($measurement->automated_size_class) }}">Auto {{ $measurement->automated_size_class }}</span></div>
                      </td>
                      <td>
                        <span class="badge {{ $measurement->class_match ? 'bg-label-success' : 'bg-label-danger' }}">
                          {{ $measurement->class_match ? 'Match' : 'Mismatch' }}
                        </span>
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @else
            <div class="validation-empty">No validation samples have been recorded for this run yet.</div>
          @endif
        </div>
      </section>
    @endif
  </div>
@endsection
