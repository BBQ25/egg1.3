@extends('layouts.admin')

@section('title', 'APEWSD - Batch Detail')

@section('content')
  @php
    $payload = $batchDetailPayload ?? [];
    $summary = $payload['summary'] ?? null;
    $sizeBreakdown = $payload['size_breakdown'] ?? collect();
    $records = $payload['records'] ?? null;
    $context = $batchContext ?? [];
    $selectedRange = (string) ($selectedRange ?? '1d');
    $selectedSearch = (string) ($selectedSearch ?? '');
    $selectedStatus = (string) request()->query('status', 'all');

    $backQuery = [
      'range' => $selectedRange,
      'context_farm_id' => $context['selected']['farm_id'] ?? null,
      'context_device_id' => $context['selected']['device_id'] ?? null,
      'q' => $selectedSearch !== '' ? $selectedSearch : null,
      'status' => $selectedStatus !== 'all' ? $selectedStatus : null,
    ];

    $formatInt = static fn ($value): string => number_format((int) ($value ?? 0));
    $formatWeight = static fn ($value): string => number_format((float) ($value ?? 0), 2) . ' g';
    $formatDateTime = static function ($value): string {
        return \App\Support\BatchCodeFormatter::formatPhilippineDateTime($value);
    };
    $durationLabel = static function ($start, $end): string {
        if (!$start) {
            return 'N/A';
        }

        if (!$end) {
            return 'In progress';
        }

        $minutes = \App\Support\BatchCodeFormatter::toPhilippineTime($start)
            ->diffInMinutes(\App\Support\BatchCodeFormatter::toPhilippineTime($end));

        if ($minutes < 1) {
            return 'Under 1 minute';
        }

        return $minutes . ' min';
    };
    $statusTheme = match ((string) ($summary->status ?? '')) {
      'closed' => 'bg-label-success',
      'open' => 'bg-label-warning',
      default => 'bg-label-secondary',
    };
    $sizeClassThemes = [
      'Reject' => 'bg-label-danger',
      'Peewee' => 'bg-label-secondary',
      'Pullet' => 'bg-label-info',
      'Small' => 'bg-label-primary',
      'Medium' => 'bg-label-success',
      'Large' => 'bg-label-warning',
      'Extra-Large' => 'bg-label-dark',
      'Jumbo' => 'bg-label-danger',
    ];
  @endphp

  <style>
    .batch-detail-shell {
      display: grid;
      gap: 1.5rem;
    }

    .batch-detail-card {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.35rem;
      background: #fff;
      box-shadow: 0 0.9rem 2rem rgba(67, 89, 113, 0.08);
    }

    .batch-detail-card-body {
      padding: 1.35rem 1.45rem;
    }

    .batch-detail-metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 0.85rem;
    }

    .batch-detail-metric {
      border-radius: 1rem;
      border: 1px solid rgba(67, 89, 113, 0.1);
      background: rgba(248, 250, 252, 0.9);
      padding: 1rem 1.05rem;
    }

    .batch-detail-metric-label {
      font-size: 0.73rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #8592a3;
    }

    .batch-detail-metric-value {
      margin-top: 0.35rem;
      font-size: 1.45rem;
      line-height: 1;
      font-weight: 800;
      color: #243448;
    }

    .batch-detail-grid {
      display: grid;
      grid-template-columns: 1.1fr 1.6fr;
      gap: 1.5rem;
      align-items: start;
    }

    .batch-detail-table-wrap {
      overflow-x: auto;
    }

    @media (max-width: 991.98px) {
      .batch-detail-grid,
      .batch-detail-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 575.98px) {
      .batch-detail-grid,
      .batch-detail-metrics {
        grid-template-columns: minmax(0, 1fr);
      }
    }
  </style>

  <div class="batch-detail-shell">
    <section class="batch-detail-card">
      <div class="batch-detail-card-body">
        @if (session('status'))
          <div class="alert alert-success mb-3">{{ session('status') }}</div>
        @endif
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
          <div>
            <a href="{{ route('monitoring.batches.index', array_filter($backQuery, static fn ($value) => $value !== null && $value !== '')) }}" class="btn btn-sm btn-outline-secondary mb-3">Back to Batches</a>
            <div class="text-uppercase small text-body-secondary fw-semibold">Batch Detail</div>
            <h1 class="h3 mb-2">{{ $summary->batch_code ?? 'Batch' }}</h1>
            <div class="text-body-secondary">
              {{ $summary->farm_name ?? 'Farm' }} · {{ $summary->device_name ?? 'Device' }} · Serial {{ $summary->device_serial ?? 'N/A' }}
            </div>
            <div class="text-body-secondary small mt-1">Times shown in Philippine Standard Time.</div>
            <div class="mt-2">
              <span class="badge {{ $statusTheme }}">{{ ucfirst((string) ($summary->status ?? 'unknown')) }}</span>
            </div>
          </div>
          <div class="text-body-secondary">
            <div class="mb-2 text-end">
              <a href="{{ route('monitoring.batches.show.export', [
                  'farm' => $summary->farm_id,
                  'device' => $summary->device_id,
                  'batchCode' => $summary->batch_code,
                  'range' => $selectedRange,
                  'context_farm_id' => $context['selected']['farm_id'] ?? null,
                  'context_device_id' => $context['selected']['device_id'] ?? null,
                  'q' => $selectedSearch !== '' ? $selectedSearch : null,
                  'status' => $selectedStatus !== 'all' ? $selectedStatus : null,
              ]) }}" class="btn btn-sm btn-outline-primary">
                Export CSV
              </a>
            </div>
            @if (($summary->id ?? null) && ($summary->status ?? '') === 'open')
              <form method="POST" action="{{ route('monitoring.batches.close', [
                  'farm' => $summary->farm_id,
                  'device' => $summary->device_id,
                  'batchCode' => $summary->batch_code,
                  'range' => $selectedRange,
                  'context_farm_id' => $context['selected']['farm_id'] ?? null,
                  'context_device_id' => $context['selected']['device_id'] ?? null,
                  'q' => $selectedSearch !== '' ? $selectedSearch : null,
                  'status' => $selectedStatus !== 'all' ? $selectedStatus : null,
              ]) }}" class="mb-2 text-end">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn btn-sm btn-warning">Close Batch</button>
              </form>
            @endif
            <div><span class="fw-semibold">Started:</span> {{ $formatDateTime($summary->started_at ?? null) }}</div>
            <div><span class="fw-semibold">Ended:</span> {{ $summary->ended_at ? $formatDateTime($summary->ended_at) : 'In progress' }}</div>
            <div><span class="fw-semibold">Duration:</span> {{ $durationLabel($summary->started_at ?? null, $summary->ended_at ?? null) }}</div>
          </div>
        </div>
      </div>
    </section>

    <section class="batch-detail-metrics">
      <article class="batch-detail-metric">
        <div class="batch-detail-metric-label">Egg Records</div>
        <div class="batch-detail-metric-value">{{ $formatInt($summary->total_eggs ?? 0) }}</div>
      </article>
      <article class="batch-detail-metric">
        <div class="batch-detail-metric-label">Reject Eggs</div>
        <div class="batch-detail-metric-value">{{ $formatInt($summary->reject_count ?? 0) }}</div>
      </article>
      <article class="batch-detail-metric">
        <div class="batch-detail-metric-label">Average Weight</div>
        <div class="batch-detail-metric-value">{{ $formatWeight($summary->avg_weight_grams ?? 0) }}</div>
      </article>
      <article class="batch-detail-metric">
        <div class="batch-detail-metric-label">Total Weight</div>
        <div class="batch-detail-metric-value">{{ $formatWeight($summary->total_weight_grams ?? 0) }}</div>
      </article>
    </section>

    <section class="batch-detail-grid">
      <article class="batch-detail-card">
        <div class="batch-detail-card-body">
          <h2 class="h5 mb-3">Size Breakdown</h2>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Size Class</th>
                  <th>Eggs</th>
                  <th>Total Weight</th>
                </tr>
              </thead>
              <tbody>
                @forelse ($sizeBreakdown as $row)
                  <tr>
                    <td><span class="badge {{ $sizeClassThemes[$row->size_class] ?? 'bg-label-primary' }}">{{ $row->size_class }}</span></td>
                    <td>{{ $formatInt($row->eggs) }}</td>
                    <td>{{ $formatWeight($row->total_weight_grams) }}</td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="3" class="text-center text-body-secondary">No classified records available.</td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </article>

      <article class="batch-detail-card">
        <div class="batch-detail-card-body">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
              <h2 class="h5 mb-1">Egg Records</h2>
              <div class="text-body-secondary">Individual ingest records captured for this batch.</div>
            </div>
          </div>

          @if ($records && $records->count() > 0)
            <div class="batch-detail-table-wrap">
              <table class="table table-hover align-middle mb-0">
                <thead>
                  <tr>
                    <th>Recorded At</th>
                    <th>Egg UID</th>
                    <th>Size Class</th>
                    <th>Weight</th>
                    <th>Source IP</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach ($records as $record)
                    <tr>
                      <td>{{ $formatDateTime($record->recorded_at) }}</td>
                      <td>{{ $record->egg_uid ?: 'Not set' }}</td>
                      <td><span class="badge {{ $sizeClassThemes[$record->size_class] ?? 'bg-label-primary' }}">{{ $record->size_class }}</span></td>
                      <td>{{ $formatWeight($record->weight_grams) }}</td>
                      <td>{{ $record->source_ip ?: 'N/A' }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>

            <div class="mt-3">
              {{ $records->links() }}
            </div>
          @else
            <div class="text-body-secondary">No records are available for this batch.</div>
          @endif
        </div>
      </article>
    </section>
  </div>
@endsection
