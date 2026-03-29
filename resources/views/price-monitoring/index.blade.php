@extends('layouts.admin')

@section('title', 'APEWSD - Price Monitoring')

@section('content')
  @php
    $summary = $priceSummary ?? [];
    $sizeRows = $sizeComparisons ?? collect();
    $farmRows = $farmComparisons ?? collect();
    $sizeOptions = $sizeClassOptions ?? collect();
    $selectedSizeClass = (string) ($selectedSizeClass ?? '');
    $filteredRows = $filteredFarmPrices ?? collect();
    $currency = static fn ($value) => 'PHP ' . number_format((float) $value, 2);
    $trayLabel = static fn ($count) => \App\Support\EggTrayFormatter::trayLabel((int) $count);
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
    .price-monitor-shell {
      display: grid;
      gap: 1.5rem;
    }

    .price-monitor-hero {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.5rem;
      overflow: hidden;
      background:
        radial-gradient(circle at top right, rgba(105, 108, 255, 0.18), transparent 34%),
        linear-gradient(135deg, #f7fbff 0%, #ffffff 48%, #fff8ec 100%);
      box-shadow: 0 1rem 2.5rem rgba(67, 89, 113, 0.08);
    }

    .price-monitor-hero-body {
      display: grid;
      gap: 1rem;
      padding: 1.25rem 1.4rem;
    }

    .price-monitor-grid {
      display: grid;
      gap: 1.15rem;
      align-items: start;
    }

    .price-monitor-hero-top {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
    }

    .price-monitor-copy {
      display: grid;
      gap: 0.55rem;
      max-width: 48rem;
    }

    .price-monitor-kicker {
      font-size: 0.75rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      font-weight: 700;
      color: #8592a3;
    }

    .price-monitor-title {
      font-size: clamp(1.45rem, 1.2rem + 0.45vw, 1.95rem);
      line-height: 1.1;
      letter-spacing: -0.03em;
      color: #243448;
      max-width: 24ch;
      margin: 0;
    }

    .price-monitor-lead {
      max-width: 52rem;
      color: #66788a;
      line-height: 1.45;
      font-size: 0.96rem;
      margin: 0;
    }

    .price-monitor-pill-row {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
    }

    .price-monitor-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.65rem;
      padding: 0.45rem 0.75rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.82);
      border: 1px solid rgba(67, 89, 113, 0.1);
      box-shadow: 0 0.45rem 1rem rgba(67, 89, 113, 0.08);
      color: #44576b;
      font-weight: 600;
      font-size: 0.88rem;
    }

    .price-monitor-pill .app-shell-icon,
    .price-monitor-metric .app-shell-icon,
    .price-monitor-card .app-shell-icon {
      width: 1.25rem;
      height: 1.25rem;
      object-fit: contain;
      flex-shrink: 0;
    }

    .price-monitor-metric-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.85rem;
    }

    .price-monitor-metric {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.1rem;
      background: rgba(255, 255, 255, 0.9);
      box-shadow: 0 0.75rem 1.5rem rgba(67, 89, 113, 0.09);
      padding: 0.75rem 0.9rem;
      display: grid;
      gap: 0.25rem;
      min-height: 0;
    }

    .price-monitor-metric-label {
      font-size: 0.7rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #8592a3;
    }

    .price-monitor-metric-value {
      font-size: 1.35rem;
      line-height: 1;
      font-weight: 800;
      color: #243448;
    }

    .price-monitor-card {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.3rem;
      box-shadow: 0 0.8rem 1.75rem rgba(67, 89, 113, 0.08);
      overflow: hidden;
    }

    .price-monitor-card-head {
      display: flex;
      align-items: flex-start;
      gap: 0.9rem;
    }

    .price-monitor-icon-wrap {
      width: 3rem;
      height: 3rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 1rem;
      background: rgba(105, 108, 255, 0.1);
      flex-shrink: 0;
    }

    .price-monitor-empty {
      border: 1px dashed rgba(67, 89, 113, 0.18);
      border-radius: 1rem;
      padding: 1.5rem;
      text-align: center;
      color: #7a8896;
      background: rgba(245, 247, 250, 0.72);
    }

    .price-monitor-filter-bar {
      display: flex;
      flex-wrap: wrap;
      align-items: end;
      justify-content: space-between;
      gap: 1rem;
      padding: 1rem 1.25rem 0;
    }

    .price-monitor-filter-form {
      display: flex;
      flex-wrap: wrap;
      align-items: end;
      gap: 0.75rem;
    }

    .price-monitor-filter-form .form-select {
      min-width: 220px;
    }

    .price-monitor-lowest-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.35rem 0.7rem;
      border-radius: 999px;
      background: rgba(113, 221, 55, 0.16);
      color: #1d7c38;
      font-size: 0.78rem;
      font-weight: 700;
      border: 1px solid rgba(113, 221, 55, 0.28);
      white-space: nowrap;
    }

    .price-monitor-lowest-badge .app-shell-icon {
      width: 1rem;
      height: 1rem;
    }

    .price-monitor-stock-note {
      display: block;
      margin-top: 0.15rem;
      color: #8592a3;
      font-size: 0.76rem;
      line-height: 1.35;
    }

    @media (max-width: 991.98px) {
      .price-monitor-metric-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .price-monitor-hero-top {
        flex-direction: column;
      }
    }

    @media (max-width: 575.98px) {
      .price-monitor-metric-grid {
        grid-template-columns: 1fr;
      }

      .price-monitor-title {
        max-width: none;
      }
    }
  </style>

  <div class="price-monitor-shell">
    <section class="price-monitor-hero">
      <div class="price-monitor-hero-body">
        <div class="price-monitor-grid">
          <div class="price-monitor-hero-top">
            <div class="price-monitor-copy">
            <div class="badge bg-label-primary mb-3">Read-only market watch</div>
            <div class="price-monitor-kicker mb-2">Price Monitoring</div>
            <h4 class="price-monitor-title">Egg price comparisons across farms and size classes</h4>
            <p class="price-monitor-lead">
              Monitor current selling prices without changing stock data. This page compares egg size bands, weight ranges, and farm-level price positioning for admin and customer review.
            </p>
            </div>

            <div class="price-monitor-metric-grid">
              <div class="price-monitor-metric">
                <div class="price-monitor-metric-label">Tracked Farms</div>
                <div class="price-monitor-metric-value">{{ number_format((int) ($summary['tracked_farms'] ?? 0)) }}</div>
                <div class="small text-body-secondary">Contributing farms</div>
              </div>
              <div class="price-monitor-metric">
                <div class="price-monitor-metric-label">Live Price Points</div>
                <div class="price-monitor-metric-value">{{ number_format((int) ($summary['live_price_points'] ?? 0)) }}</div>
                <div class="small text-body-secondary">Distinct prices</div>
              </div>
              <div class="price-monitor-metric">
                <div class="price-monitor-metric-label">Widest Spread</div>
                <div class="price-monitor-metric-value">{{ $currency($summary['widest_spread'] ?? 0) }}</div>
                <div class="small text-body-secondary">
                  {{ $summary['highest_class'] ?? 'N/A' }} to {{ $summary['lowest_class'] ?? 'N/A' }}
                </div>
              </div>
            </div>
          </div>

          <div>
            <div class="price-monitor-pill-row">
              <span class="price-monitor-pill">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/business/animated/icons8-combo-chart--v2.gif',
                  'alt' => 'Price comparisons',
                  'classes' => '',
                ])
                Size-to-size price comparison
              </span>
              <span class="price-monitor-pill">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/location/animated/icons8-compass--v2.gif',
                  'alt' => 'Farm spread',
                  'classes' => '',
                ])
                Farm-level price spread
              </span>
              <span class="price-monitor-pill">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/book/animated/icons8-open-book--v2.gif',
                  'alt' => 'Weight bands',
                  'classes' => '',
                ])
                Weight-band aligned review
              </span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <div class="d-grid gap-4">
      <section class="card price-monitor-card">
          <div class="card-header">
            <div class="price-monitor-card-head">
              <span class="price-monitor-icon-wrap">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/food/icons/icons8-kawaii-egg.png',
                  'alt' => 'Size comparison',
                  'classes' => '',
                ])
              </span>
              <div>
                <h5 class="mb-1">Size Class Price Comparison</h5>
                <div class="small text-body-secondary">Compare average, min, and max selling prices by size and weight band.</div>
              </div>
            </div>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Size</th>
                    <th>Weight Range</th>
                    <th class="text-end">Farms</th>
                    <th>Stock</th>
                    <th class="text-end">Current Range</th>
                    <th class="text-end">Spread</th>
                  </tr>
                </thead>
                <tbody>
                  @forelse ($sizeRows as $row)
                    <tr>
                      <td><span class="badge {{ $sizeClassThemes[$row['size_class']] ?? 'bg-label-primary' }}">{{ $row['size_class'] }}</span></td>
                      <td>{{ $row['weight_range'] }}</td>
                      <td class="text-end">{{ number_format((int) $row['farm_count']) }}</td>
                      <td>
                        <span class="fw-semibold">{{ number_format((int) $row['stock_total']) }} eggs</span>
                        <span class="price-monitor-stock-note">{{ $trayLabel($row['stock_total']) }}</span>
                      </td>
                      <td class="text-end fw-semibold">{{ $currency($row['min_price']) }} to {{ $currency($row['max_price']) }}</td>
                      <td class="text-end">{{ $currency($row['spread']) }}</td>
                    </tr>
                  @empty
                    <tr>
                      <td colspan="6" class="py-5">
                        <div class="price-monitor-empty">No pricing records are available yet.</div>
                      </td>
                    </tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
      </section>

      <section class="card price-monitor-card">
          <div class="card-header">
            <div class="price-monitor-card-head">
              <span class="price-monitor-icon-wrap">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/man/icons/icons8-farmer-male.png',
                  'alt' => 'Farm comparison',
                  'classes' => '',
                ])
              </span>
              <div>
                <h5 class="mb-1">Farm Price Comparison</h5>
                <div class="small text-body-secondary">Compare weighted average prices and price ranges per farm.</div>
              </div>
            </div>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover mb-0 align-middle">
                <thead>
                  <tr>
                    <th>Farm</th>
                    <th class="text-end">SKUs</th>
                    <th>Stock</th>
                    <th>Size Prices</th>
                    <th class="text-end">Range</th>
                  </tr>
                </thead>
                <tbody>
                  @forelse ($farmRows as $row)
                    <tr>
                      <td class="fw-semibold">{{ $row['farm_name'] }}</td>
                      <td class="text-end">{{ number_format((int) $row['item_count']) }}</td>
                      <td>
                        <span class="fw-semibold">{{ number_format((int) $row['stock_total']) }} eggs</span>
                        <span class="price-monitor-stock-note">{{ $trayLabel($row['stock_total']) }}</span>
                      </td>
                      <td class="small">
                        @if ($row['size_prices'] === [])
                          <span class="text-body-secondary">No size pricing yet</span>
                        @else
                          @foreach ($row['size_prices'] as $priceRow)
                            <div><span class="fw-semibold">{{ $priceRow['size_class'] }}:</span> PHP {{ $priceRow['display_price'] }}</div>
                          @endforeach
                        @endif
                      </td>
                      <td class="text-end">{{ $currency($row['min_price']) }} to {{ $currency($row['max_price']) }}</td>
                    </tr>
                  @empty
                    <tr>
                      <td colspan="5" class="py-5">
                        <div class="price-monitor-empty">No farm price comparisons are available yet.</div>
                      </td>
                    </tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
      </section>
    </div>

    <section class="card price-monitor-card">
      <div class="card-header">
        <div class="price-monitor-card-head">
          <span class="price-monitor-icon-wrap">
            @include('partials.curated-shell-icon', [
              'src' => 'resources/icons/dusk/location/animated/icons8-compass--v2.gif',
              'alt' => 'Filtered farm prices',
              'classes' => '',
            ])
          </span>
          <div>
            <h5 class="mb-1">Farm Prices By Selected Weight Size</h5>
            <div class="small text-body-secondary">Filter a size class, then view all farms carrying that class and compare their current prices directly.</div>
          </div>
        </div>
      </div>
      <div class="price-monitor-filter-bar">
        <form method="GET" action="{{ route('price-monitoring.index') }}" class="price-monitor-filter-form">
          <div>
            <label for="price_monitor_size_class" class="form-label mb-1">Weight size</label>
            <select id="price_monitor_size_class" name="size_class" class="form-select">
              <option value="">Choose a size class</option>
              @foreach ($sizeOptions as $option)
                <option value="{{ $option['class'] }}" @selected($selectedSizeClass === $option['class'])>
                  {{ $option['label'] }} ({{ number_format((float) $option['min'], 2) }}g to {{ number_format((float) $option['max'], 2) }}g)
                </option>
              @endforeach
            </select>
          </div>
          <button type="submit" class="btn btn-primary">Filter Farms</button>
          <a href="{{ route('price-monitoring.index') }}" class="btn btn-outline-secondary">Reset</a>
        </form>
        @if ($selectedSizeClass !== '')
          <div class="small text-body-secondary">
            Showing farms with <span class="fw-semibold">{{ $selectedSizeClass }}</span> inventory pricing.
          </div>
        @endif
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead>
              <tr>
                <th>Farm</th>
                <th>Weight Range</th>
                <th class="text-end">SKUs</th>
                <th>Stock</th>
                <th class="text-end">Current Price</th>
                <th class="text-end">Marker</th>
              </tr>
            </thead>
            <tbody>
              @if ($selectedSizeClass === '')
                <tr>
                  <td colspan="6" class="py-5">
                    <div class="price-monitor-empty">Select a weight size first to compare farm prices.</div>
                  </td>
                </tr>
              @elseif ($filteredRows->isEmpty())
                <tr>
                  <td colspan="6" class="py-5">
                    <div class="price-monitor-empty">No farms currently have {{ $selectedSizeClass }} price records.</div>
                  </td>
                </tr>
              @else
                @foreach ($filteredRows as $row)
                  <tr>
                    <td class="fw-semibold">{{ $row['farm_name'] }}</td>
                    <td>{{ $row['weight_range'] }}</td>
                    <td class="text-end">{{ number_format((int) $row['item_count']) }}</td>
                    <td>
                      <span class="fw-semibold">{{ number_format((int) $row['stock_total']) }} eggs</span>
                      <span class="price-monitor-stock-note">{{ $trayLabel($row['stock_total']) }}</span>
                    </td>
                    <td class="text-end fw-semibold">PHP {{ $row['price_label'] }}</td>
                    <td class="text-end">
                      @if ($row['is_lowest'])
                        <span class="price-monitor-lowest-badge">
                          @include('partials.curated-shell-icon', [
                            'src' => 'resources/icons/dusk/check/animated/icons8-checked--v2.gif',
                            'alt' => 'Lowest price',
                            'classes' => '',
                          ])
                          Lowest Price
                        </span>
                      @else
                        <span class="text-body-secondary small">-</span>
                      @endif
                    </td>
                  </tr>
                @endforeach
              @endif
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
@endsection
