@extends('layouts.admin')

@section('title', 'APEWSD - Inventory')

@section('content')
  @php
    $stats = $inventoryStats ?? [];
    $farmRows = $farms ?? collect();
    $itemRows = $items ?? null;
    $movementItemRows = $movementItems ?? collect();
    $movementRows = $recentMovements ?? collect();
    $priceMatrixRows = $priceMatrix ?? collect();
    $movementItemGroups = $movementItemRows->groupBy(fn ($item) => $item->farm?->farm_name ?? 'Unassigned Farm');
    $currency = static fn ($value) => 'PHP ' . number_format((float) $value, 2);
    $openItemModal = (bool) ($openItemModal ?? false);
    $openMovementModal = (bool) ($openMovementModal ?? false);
    $oldInventoryFormMode = (string) old('inventory_form_mode', 'create-item');
    $oldEditItemId = (int) old('item_id', 0);
    $canManagePricing = auth()->user()?->isOwner() ?? false;
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
    $movementThemes = [
      'IN' => ['badge' => 'bg-label-success', 'icon' => 'resources/icons/dusk/check/animated/icons8-checked--v2.gif'],
      'OUT' => ['badge' => 'bg-label-danger', 'icon' => 'resources/icons/dusk/business/animated/icons8-delivery--v2.gif'],
      'ADJUSTMENT' => ['badge' => 'bg-label-warning', 'icon' => 'resources/icons/dusk/filter/animated/icons8-horizontal-settings-mixer--v3.gif'],
    ];
    $trayLabel = static fn ($count) => \App\Support\EggTrayFormatter::trayLabel((int) $count);
    $traySummary = static fn ($count) => \App\Support\EggTrayFormatter::summary((int) $count);
    $eggCountLabel = static fn ($count) => \App\Support\EggTrayFormatter::eggCountLabel((int) $count);
  @endphp

  <style>
    .inventory-shell {
      display: grid;
      gap: 1.5rem;
    }

    .inventory-hero {
      position: relative;
      overflow: hidden;
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.5rem;
      background:
        radial-gradient(circle at top right, rgba(255, 183, 77, 0.28), transparent 34%),
        linear-gradient(135deg, #fff8ec 0%, #ffffff 52%, #eef5ff 100%);
      box-shadow: 0 1rem 2.5rem rgba(67, 89, 113, 0.09);
    }

    .inventory-hero::after {
      content: '';
      position: absolute;
      inset: auto -3rem -4rem auto;
      width: 16rem;
      height: 16rem;
      background: radial-gradient(circle, rgba(105, 108, 255, 0.12), transparent 68%);
      pointer-events: none;
    }

    .inventory-hero-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.7fr) minmax(280px, 1fr);
      gap: 1.5rem;
      align-items: stretch;
    }

    .inventory-kicker {
      font-size: 0.74rem;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      font-weight: 700;
      color: #8592a3;
    }

    .inventory-hero-title {
      font-size: clamp(2rem, 1.65rem + 1vw, 2.9rem);
      line-height: 1.02;
      letter-spacing: -0.03em;
      color: #243448;
    }

    .inventory-hero-lead {
      max-width: 44rem;
      color: #66788a;
      font-size: 1rem;
      line-height: 1.65;
    }

    .inventory-hero-pill-row {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
    }

    .inventory-hero-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.65rem;
      padding: 0.75rem 0.95rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.78);
      border: 1px solid rgba(67, 89, 113, 0.1);
      box-shadow: 0 0.45rem 1rem rgba(67, 89, 113, 0.08);
      color: #44576b;
      font-weight: 600;
    }

    .inventory-hero-pill .app-shell-icon,
    .inventory-hero-art-card .app-shell-icon,
    .inventory-stat-card .app-shell-icon,
    .inventory-workflow-card .app-shell-icon,
    .inventory-section-title .app-shell-icon,
    .inventory-farm-card .app-shell-icon,
    .inventory-movement-card .app-shell-icon,
    .inventory-table-item .app-shell-icon,
    .inventory-search-chip .app-shell-icon {
      width: 1.3rem;
      height: 1.3rem;
      flex-shrink: 0;
      object-fit: contain;
    }

    .inventory-hero-art {
      position: relative;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 1rem;
      align-content: start;
    }

    .inventory-hero-art-card {
      display: grid;
      gap: 0.65rem;
      padding: 1rem;
      border-radius: 1.25rem;
      border: 1px solid rgba(67, 89, 113, 0.12);
      background: rgba(255, 255, 255, 0.88);
      box-shadow: 0 0.75rem 1.5rem rgba(67, 89, 113, 0.09);
    }

    .inventory-hero-art-card strong {
      color: #233446;
      font-size: 1rem;
    }

    .inventory-hero-art-card span {
      color: #6d7c8c;
      font-size: 0.84rem;
      line-height: 1.45;
    }

    .inventory-stock-note {
      display: block;
      color: #8592a3;
      font-size: 0.76rem;
      line-height: 1.4;
    }

    .inventory-stat-card {
      position: relative;
      overflow: hidden;
      height: 100%;
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.2rem;
      box-shadow: 0 0.8rem 1.75rem rgba(67, 89, 113, 0.08);
    }

    .inventory-stat-card .card-body {
      display: grid;
      gap: 0.85rem;
      padding: 1.15rem;
    }

    .inventory-stat-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
    }

    .inventory-stat-icon {
      width: 2.8rem;
      height: 2.8rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 1rem;
      background: rgba(105, 108, 255, 0.1);
    }

    .inventory-stat-label {
      font-size: 0.74rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #8592a3;
    }

    .inventory-stat-value {
      font-size: clamp(1.7rem, 1.45rem + 0.6vw, 2.1rem);
      line-height: 1;
      color: #233446;
      font-weight: 800;
    }

    .inventory-stat-hint {
      color: #6d7c8c;
      font-size: 0.88rem;
      line-height: 1.45;
    }

    .inventory-surface {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.3rem;
      box-shadow: 0 0.8rem 1.75rem rgba(67, 89, 113, 0.08);
      overflow: hidden;
    }

    .inventory-section-title {
      display: flex;
      align-items: flex-start;
      gap: 0.9rem;
    }

    .inventory-section-title .inventory-stat-icon {
      width: 3rem;
      height: 3rem;
      border-radius: 1rem;
    }

    .inventory-workflow-card {
      height: 100%;
      border-radius: 1.15rem;
      border: 1px solid rgba(67, 89, 113, 0.12);
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(245, 247, 250, 0.92));
      padding: 1rem;
      box-shadow: 0 0.65rem 1.4rem rgba(67, 89, 113, 0.07);
    }

    .inventory-pricing-grid {
      display: grid;
      gap: 1rem;
    }

    .inventory-pricing-card {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1.15rem;
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(245, 247, 250, 0.92));
      box-shadow: 0 0.65rem 1.4rem rgba(67, 89, 113, 0.07);
    }

    .inventory-pricing-table td,
    .inventory-pricing-table th {
      vertical-align: middle;
    }

    .inventory-price-weight {
      color: #6d7c8c;
      font-size: 0.8rem;
    }

    .inventory-pricing-help {
      border-radius: 1rem;
      background: rgba(105, 108, 255, 0.08);
      border: 1px solid rgba(105, 108, 255, 0.12);
      padding: 0.9rem 1rem;
      color: #566a7f;
    }

    .inventory-workflow-card-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.75rem;
      margin-bottom: 0.85rem;
    }

    .inventory-workflow-tag {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.35rem 0.65rem;
      border-radius: 999px;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      background: rgba(105, 108, 255, 0.1);
      color: #4e5ef7;
    }

    .inventory-dusk-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.65rem;
      min-height: 3rem;
      border-radius: 1rem;
      border-width: 1px;
      font-weight: 700;
      letter-spacing: 0.01em;
      color: #314457;
      background: rgba(255, 255, 255, 0.84);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7), 0 0.55rem 1.2rem rgba(67, 89, 113, 0.08);
      transition: transform 180ms ease, box-shadow 180ms ease, border-color 180ms ease, background 180ms ease, color 180ms ease;
    }

    .inventory-dusk-btn:hover,
    .inventory-dusk-btn:focus-visible {
      transform: translateY(-1px);
      color: #243448;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8), 0 0.8rem 1.45rem rgba(67, 89, 113, 0.11);
    }

    .inventory-dusk-btn:focus-visible {
      outline: none;
    }

    .inventory-dusk-btn .inventory-dusk-btn-icon {
      width: 1.1rem;
      height: 1.1rem;
      flex-shrink: 0;
    }

    .inventory-dusk-btn--in {
      border-color: rgba(113, 221, 55, 0.24);
      background: linear-gradient(180deg, rgba(247, 255, 243, 0.96), rgba(240, 251, 234, 0.9));
    }

    .inventory-dusk-btn--in:hover,
    .inventory-dusk-btn--in:focus-visible {
      border-color: rgba(113, 221, 55, 0.34);
      background: linear-gradient(180deg, rgba(249, 255, 246, 0.98), rgba(236, 250, 229, 0.94));
    }

    .inventory-dusk-btn--out {
      border-color: rgba(255, 62, 29, 0.2);
      background: linear-gradient(180deg, rgba(255, 248, 246, 0.96), rgba(255, 241, 238, 0.9));
    }

    .inventory-dusk-btn--out:hover,
    .inventory-dusk-btn--out:focus-visible {
      border-color: rgba(255, 62, 29, 0.28);
      background: linear-gradient(180deg, rgba(255, 250, 248, 0.98), rgba(255, 238, 234, 0.94));
    }

    .inventory-dusk-btn--adjustment {
      border-color: rgba(255, 171, 0, 0.24);
      background: linear-gradient(180deg, rgba(255, 252, 244, 0.96), rgba(255, 247, 230, 0.9));
    }

    .inventory-dusk-btn--adjustment:hover,
    .inventory-dusk-btn--adjustment:focus-visible {
      border-color: rgba(255, 171, 0, 0.32);
      background: linear-gradient(180deg, rgba(255, 253, 246, 0.98), rgba(255, 245, 224, 0.94));
    }

    .inventory-search-panel {
      background: linear-gradient(135deg, rgba(255, 250, 240, 0.86), rgba(238, 245, 255, 0.86));
    }

    .inventory-search-chip-row {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
    }

    .inventory-search-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.6rem;
      border-radius: 1rem;
      border: 1px solid rgba(67, 89, 113, 0.1);
      background: rgba(255, 255, 255, 0.9);
      padding: 0.8rem 0.9rem;
      width: 100%;
      height: 100%;
    }

    .inventory-table-item {
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }

    .inventory-table-icon {
      width: 2.5rem;
      height: 2.5rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 0.95rem;
      background: rgba(255, 183, 77, 0.14);
      flex-shrink: 0;
    }

    .inventory-side-stack {
      display: grid;
      gap: 1.5rem;
    }

    .inventory-farm-card,
    .inventory-movement-card {
      border: 1px solid rgba(67, 89, 113, 0.1);
      border-radius: 1rem;
      background: rgba(255, 255, 255, 0.92);
      padding: 1rem;
      box-shadow: 0 0.5rem 1.15rem rgba(67, 89, 113, 0.06);
    }

    .inventory-farm-card {
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }

    .inventory-movement-card-head {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 0.75rem;
      margin-bottom: 0.65rem;
    }

    .inventory-empty-state {
      border: 1px dashed rgba(67, 89, 113, 0.18);
      border-radius: 1rem;
      padding: 1.5rem;
      text-align: center;
      color: #7a8896;
      background: rgba(245, 247, 250, 0.72);
    }

    @media (max-width: 1199.98px) {
      .inventory-hero-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 767.98px) {
      .inventory-hero-art {
        grid-template-columns: 1fr;
      }

      .inventory-search-form {
        flex-direction: column;
      }

      .inventory-search-form > * {
        width: 100%;
      }
    }
  </style>

  <div class="inventory-shell">
    <div class="inventory-hero">
      <div class="card-body p-4 p-xl-5">
        <div class="inventory-hero-grid">
          <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
              <span class="badge bg-label-primary">{{ $scopeLabel }}</span>
              <span class="badge bg-label-warning">{{ $farmRows->count() }} farm{{ $farmRows->count() === 1 ? '' : 's' }} in scope</span>
            </div>
            <div class="inventory-kicker mb-2">Poultry Stock Control</div>
            <h4 class="inventory-hero-title mb-3">Inventory</h4>
            <p class="inventory-hero-lead mb-4">
              Review egg stock, reorder risk, and recent inventory movements within your allowed farm scope. Use the live poultry workflow below to open stock-in, stock-out, and adjustment actions faster.
            </p>
            <div class="inventory-hero-pill-row">
              <span class="inventory-hero-pill">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/animals/icons/icons8-chicken.png',
                  'alt' => 'Poultry',
                  'classes' => '',
                ])
                Farm-aware stock watch
              </span>
              <span class="inventory-hero-pill">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/check/animated/icons8-ok--v2.gif',
                  'alt' => 'Tracked',
                  'classes' => '',
                ])
                Audited movement history
              </span>
              <span class="inventory-hero-pill">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/location/animated/icons8-compass--v2.gif',
                  'alt' => 'Scoped',
                  'classes' => '',
                ])
                Scoped to assigned farms
              </span>
            </div>
          </div>
          <div class="inventory-hero-art">
            <div class="inventory-hero-art-card">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/food/icons/icons8-kawaii-egg.png',
                'alt' => 'Egg stock',
                'classes' => '',
              ])
              <strong>{{ $eggCountLabel($stats['stock_total'] ?? 0) }} live</strong>
              <small class="inventory-stock-note">{{ $trayLabel($stats['stock_total'] ?? 0) }}</small>
              <span>Current tracked egg stock across the farms that belong to your scope.</span>
            </div>
            <div class="inventory-hero-art-card">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/business/animated/icons8-combo-chart--v2.gif',
                'alt' => 'Movement analytics',
                'classes' => '',
              ])
              <strong>{{ number_format((int) (($stats['movement_in_total'] ?? 0) + ($stats['movement_out_total'] ?? 0) + ($stats['movement_adjustment_total'] ?? 0))) }} logged moves</strong>
              <span>Movement actions stay visible through recent history, stock preview, and farm grouping.</span>
            </div>
            <div class="inventory-hero-art-card">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/bank/animated/icons8-money-box--v2.gif',
                'alt' => 'Inventory value',
                'classes' => '',
              ])
              <strong>{{ $currency($stats['inventory_value'] ?? 0) }}</strong>
              <span>Estimated inventory value based on selling price with cost fallback.</span>
            </div>
            <div class="inventory-hero-art-card">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/warning/icons/icons8-stop-sign.png',
                'alt' => 'Low stock',
                'classes' => '',
              ])
              <strong>{{ number_format((int) ($stats['low_stock_total'] ?? 0)) }} low stock items</strong>
              <span>Reorder pressure is flagged early so farm supply remains stable.</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    @if (session('status'))
      <div class="alert alert-success inventory-surface mb-0" role="alert">
        {{ session('status') }}
      </div>
    @endif

    @if ($errors->any())
      <div class="alert alert-danger inventory-surface mb-0" role="alert">
        {{ $errors->first() }}
      </div>
    @endif

    <div class="row g-3">
      <div class="col-sm-6 col-xl-3">
        <div class="card inventory-stat-card">
          <div class="card-body">
            <div class="inventory-stat-meta">
              <div>
                <div class="inventory-stat-label">Stock Keeping Units</div>
                <div class="inventory-stat-value">{{ number_format((int) ($stats['sku_total'] ?? 0)) }}</div>
              </div>
              <span class="inventory-stat-icon">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/ecommerce/animated/icons8-box--v2.gif',
                  'alt' => 'SKU',
                  'classes' => '',
                ])
              </span>
            </div>
            <div class="inventory-stat-hint">Registered egg inventory items across the farms visible to your account.</div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="card inventory-stat-card">
          <div class="card-body">
            <div class="inventory-stat-meta">
              <div>
                <div class="inventory-stat-label">Current Egg Stock</div>
                <div class="inventory-stat-value">{{ number_format((int) ($stats['stock_total'] ?? 0)) }}</div>
              </div>
              <span class="inventory-stat-icon">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/food/icons/icons8-kawaii-egg.png',
                  'alt' => 'Egg stock',
                  'classes' => '',
                ])
              </span>
            </div>
            <div class="inventory-stat-hint">Live stock total based on all tracked inventory items in scope. {{ $trayLabel($stats['stock_total'] ?? 0) }}.</div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="card inventory-stat-card">
          <div class="card-body">
            <div class="inventory-stat-meta">
              <div>
                <div class="inventory-stat-label">Low Stock Items</div>
                <div class="inventory-stat-value {{ (int) ($stats['low_stock_total'] ?? 0) > 0 ? 'text-warning' : '' }}">{{ number_format((int) ($stats['low_stock_total'] ?? 0)) }}</div>
              </div>
              <span class="inventory-stat-icon">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/warning/icons/icons8-stop-sign.png',
                  'alt' => 'Low stock',
                  'classes' => '',
                ])
              </span>
            </div>
            <div class="inventory-stat-hint">Items at or below reorder level so you can intervene before stock gaps widen.</div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-xl-3">
        <div class="card inventory-stat-card">
          <div class="card-body">
            <div class="inventory-stat-meta">
              <div>
                <div class="inventory-stat-label">Inventory Value</div>
                <div class="inventory-stat-value">{{ $currency($stats['inventory_value'] ?? 0) }}</div>
              </div>
              <span class="inventory-stat-icon">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/bank/animated/icons8-money-box--v2.gif',
                  'alt' => 'Inventory value',
                  'classes' => '',
                ])
              </span>
            </div>
            <div class="inventory-stat-hint">Selling-price weighted view of the inventory you currently have on hand.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card inventory-surface">
    <div class="card-header">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div class="inventory-section-title">
          <span class="inventory-stat-icon">
            @include('partials.curated-shell-icon', [
              'src' => 'resources/icons/dusk/filter/animated/icons8-horizontal-settings-mixer--v3.gif',
              'alt' => 'Workflow',
              'classes' => '',
            ])
          </span>
          <div>
            <h5 class="mb-1">Inventory Workflow</h5>
            <div class="small text-body-secondary">Record stock in, stock out, and manual adjustments against items within your farm scope.</div>
          </div>
        </div>
        <button
          type="button"
          class="btn btn-primary inventory-item-create-trigger"
          data-bs-toggle="modal"
          data-bs-target="#inventoryItemModal"
          data-form-mode="create-item"
          data-form-title="Create Inventory Item"
          data-form-description="Register a new egg inventory SKU for one of your farms."
          data-submit-label="Create Item">
          Add Inventory Item
        </button>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <div class="inventory-workflow-card">
            <div class="inventory-workflow-card-head">
              <div>
                <div class="inventory-workflow-tag">
                  @include('partials.curated-shell-icon', [
                    'src' => 'resources/icons/dusk/check/animated/icons8-checked--v2.gif',
                    'alt' => 'In',
                    'classes' => '',
                  ])
                  IN
                </div>
                <div class="fw-semibold mt-3 mb-1">Add counted eggs</div>
              </div>
            </div>
            <div class="small text-body-secondary mb-3">Add newly counted eggs or received stock to an inventory item.</div>
            <button
              type="button"
              class="btn inventory-dusk-btn inventory-dusk-btn--in w-100 inventory-movement-trigger"
              data-bs-toggle="modal"
              data-bs-target="#inventoryMovementModal"
              data-movement-type="IN"
              data-movement-title="Record Stock In"
              data-movement-description="Increase the current stock for the selected egg inventory item."
              data-submit-label="Save Stock In">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/check/animated/icons8-checked--v2.gif',
                'alt' => 'Record stock in',
                'classes' => 'inventory-dusk-btn-icon',
              ])
              Record IN
            </button>
          </div>
        </div>
        <div class="col-md-4">
          <div class="inventory-workflow-card">
            <div class="inventory-workflow-card-head">
              <div>
                <div class="inventory-workflow-tag">
                  @include('partials.curated-shell-icon', [
                    'src' => 'resources/icons/dusk/business/animated/icons8-delivery--v2.gif',
                    'alt' => 'Out',
                    'classes' => '',
                  ])
                  OUT
                </div>
                <div class="fw-semibold mt-3 mb-1">Release or deduct stock</div>
              </div>
            </div>
            <div class="small text-body-secondary mb-3">Deduct sold, transferred, or damaged egg stock from an inventory item.</div>
            <button
              type="button"
              class="btn inventory-dusk-btn inventory-dusk-btn--out w-100 inventory-movement-trigger"
              data-bs-toggle="modal"
              data-bs-target="#inventoryMovementModal"
              data-movement-type="OUT"
              data-movement-title="Record Stock Out"
              data-movement-description="Decrease the current stock for the selected egg inventory item."
              data-submit-label="Save Stock Out">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/business/animated/icons8-delivery--v2.gif',
                'alt' => 'Record stock out',
                'classes' => 'inventory-dusk-btn-icon',
              ])
              Record OUT
            </button>
          </div>
        </div>
        <div class="col-md-4">
          <div class="inventory-workflow-card">
            <div class="inventory-workflow-card-head">
              <div>
                <div class="inventory-workflow-tag">
                  @include('partials.curated-shell-icon', [
                    'src' => 'resources/icons/dusk/filter/animated/icons8-horizontal-settings-mixer--v3.gif',
                    'alt' => 'Adjustment',
                    'classes' => '',
                  ])
                  ADJUSTMENT
                </div>
                <div class="fw-semibold mt-3 mb-1">Correct a variance</div>
              </div>
            </div>
            <div class="small text-body-secondary mb-3">Correct stock variance by increasing or decreasing the current item count.</div>
            <button
              type="button"
              class="btn inventory-dusk-btn inventory-dusk-btn--adjustment w-100 inventory-movement-trigger"
              data-bs-toggle="modal"
              data-bs-target="#inventoryMovementModal"
              data-movement-type="ADJUSTMENT"
              data-movement-title="Record Stock Adjustment"
              data-movement-description="Apply a controlled stock correction when actual count differs from the system count."
              data-submit-label="Save Adjustment">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/filter/animated/icons8-horizontal-settings-mixer--v3.gif',
                'alt' => 'Record stock adjustment',
                'classes' => 'inventory-dusk-btn-icon',
              ])
              Record ADJUSTMENT
            </button>
          </div>
        </div>
      </div>
    </div>
    </div>

    <div class="card inventory-surface inventory-search-panel">
    <div class="card-body">
      <div class="row g-3 align-items-end">
        <div class="col-lg-7">
          <form method="GET" action="{{ route('inventory.index') }}" class="d-flex gap-2 inventory-search-form">
            <input
              type="search"
              name="q"
              class="form-control"
              placeholder="Search item code, egg type, size class, or farm"
              value="{{ $search }}" />
            <button type="submit" class="btn btn-primary text-nowrap">Search</button>
            @if ($search !== '')
              <a href="{{ route('inventory.index') }}" class="btn btn-outline-secondary text-nowrap">Reset</a>
            @endif
          </form>
        </div>
        <div class="col-lg-5">
          <div class="row g-2">
            <div class="col-4">
              <div class="inventory-search-chip">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/check/animated/icons8-checked--v2.gif',
                  'alt' => 'IN',
                  'classes' => '',
                ])
                <div>
                  <div class="small text-body-secondary">IN</div>
                  <div class="fw-semibold">{{ number_format((int) ($stats['movement_in_total'] ?? 0)) }}</div>
                </div>
              </div>
            </div>
            <div class="col-4">
              <div class="inventory-search-chip">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/business/animated/icons8-delivery--v2.gif',
                  'alt' => 'OUT',
                  'classes' => '',
                ])
                <div>
                  <div class="small text-body-secondary">OUT</div>
                  <div class="fw-semibold">{{ number_format((int) ($stats['movement_out_total'] ?? 0)) }}</div>
                </div>
              </div>
            </div>
            <div class="col-4">
              <div class="inventory-search-chip">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/filter/animated/icons8-horizontal-settings-mixer--v3.gif',
                  'alt' => 'ADJ',
                  'classes' => '',
                ])
                <div>
                  <div class="small text-body-secondary">ADJ</div>
                  <div class="fw-semibold">{{ number_format((int) ($stats['movement_adjustment_total'] ?? 0)) }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    </div>

    <div class="modal fade" id="inventoryMovementModal" tabindex="-1" aria-labelledby="inventoryMovementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title mb-1" id="inventoryMovementModalLabel">Record Inventory Movement</h5>
            <div class="small text-body-secondary" id="inventoryMovementModalDescription">
              Create a new stock movement for an item in your farm scope.
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="{{ route('inventory.movements.store') }}" id="inventoryMovementForm">
          @csrf
          <input type="hidden" name="movement_type" id="inventory_movement_type" value="{{ old('movement_type', 'IN') }}" />
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label" for="inventory_item_id">Inventory Item</label>
                <select id="inventory_item_id" name="item_id" class="form-select" required>
                  <option value="">Select inventory item</option>
                  @foreach ($movementItemGroups as $farmName => $groupedItems)
                    <optgroup label="{{ $farmName }}">
                      @foreach ($groupedItems as $item)
                        <option
                          value="{{ $item->id }}"
                          data-stock="{{ (int) $item->current_stock }}"
                          data-unit-cost="{{ number_format((float) $item->unit_cost, 2, '.', '') }}"
                          data-item-label="{{ $item->item_code }} · {{ $item->egg_type }} · {{ $item->size_class }}"
                          @selected((int) old('item_id') === (int) $item->id)>
                          {{ $item->item_code }} · {{ $item->egg_type }} · {{ $item->size_class }}
                        </option>
                      @endforeach
                    </optgroup>
                  @endforeach
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="inventory_quantity_entry_mode">Quantity Entry</label>
                <select id="inventory_quantity_entry_mode" name="quantity_entry_mode" class="form-select">
                  <option value="EGGS" @selected(old('quantity_entry_mode', 'EGGS') === 'EGGS')>Egg count</option>
                  <option value="TRAYS" @selected(old('quantity_entry_mode') === 'TRAYS')>Tray breakdown</option>
                </select>
              </div>
              <div class="col-12 col-md-6 inventory-eggs-only-field" id="inventory_quantity_wrap">
                <label class="form-label" for="inventory_quantity">Quantity</label>
                <input type="number" min="1" step="1" id="inventory_quantity" name="quantity" class="form-control" value="{{ old('quantity', 1) }}" required />
              </div>
              <div class="col-12 d-none" id="inventory_quantity_tray_wrap">
                <div class="border rounded-3 p-3 bg-lighter">
                  <div class="row g-3">
                    <div class="col-12 col-md-4">
                      <label class="form-label" for="inventory_quantity_full_trays">Full Trays</label>
                      <input type="number" min="0" step="1" id="inventory_quantity_full_trays" name="quantity_full_trays" class="form-control" value="{{ old('quantity_full_trays', 0) }}" />
                      <div class="form-text">30 eggs each</div>
                    </div>
                    <div class="col-12 col-md-4">
                      <label class="form-label" for="inventory_quantity_half_trays">Half Trays</label>
                      <input type="number" min="0" step="1" id="inventory_quantity_half_trays" name="quantity_half_trays" class="form-control" value="{{ old('quantity_half_trays', 0) }}" />
                      <div class="form-text">15 eggs each</div>
                    </div>
                    <div class="col-12 col-md-4">
                      <label class="form-label" for="inventory_quantity_loose_eggs">Loose Eggs</label>
                      <input type="number" min="0" step="1" id="inventory_quantity_loose_eggs" name="quantity_loose_eggs" class="form-control" value="{{ old('quantity_loose_eggs', 0) }}" />
                    </div>
                  </div>
                  <div class="small text-body-secondary mt-2" id="inventory_quantity_total_note">Tray entry total: {{ $eggCountLabel(old('quantity', 1)) }}</div>
                </div>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="inventory_movement_date">Movement Date</label>
                <input type="date" id="inventory_movement_date" name="movement_date" class="form-control" value="{{ old('movement_date', now()->toDateString()) }}" required />
              </div>
              <div class="col-12 col-md-6 d-none" id="inventory_adjustment_direction_wrap">
                <label class="form-label" for="inventory_adjustment_direction">Adjustment Direction</label>
                <select id="inventory_adjustment_direction" name="adjustment_direction" class="form-select">
                  <option value="">Select direction</option>
                  <option value="INCREASE" @selected(old('adjustment_direction') === 'INCREASE')>Increase stock</option>
                  <option value="DECREASE" @selected(old('adjustment_direction') === 'DECREASE')>Decrease stock</option>
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="inventory_unit_cost">Unit Cost</label>
                <input type="number" min="0" step="0.01" id="inventory_unit_cost" name="unit_cost" class="form-control" value="{{ old('unit_cost') }}" placeholder="Optional override" />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="inventory_reference_no">Reference No.</label>
                <input type="text" id="inventory_reference_no" name="reference_no" class="form-control" maxlength="80" value="{{ old('reference_no') }}" required />
              </div>
              <div class="col-12">
                <label class="form-label" for="inventory_notes">Notes</label>
                <textarea id="inventory_notes" name="notes" class="form-control" rows="2" maxlength="255" placeholder="Optional remarks">{{ old('notes') }}</textarea>
              </div>
              <div class="col-12">
                <div class="border rounded-3 p-3 bg-lighter">
                  <div class="small text-body-secondary mb-2">Stock preview</div>
                  <div class="fw-semibold" id="inventory_stock_preview">Select an item to preview stock movement.</div>
                  <div class="small text-body-secondary mt-1" id="inventory_stock_preview_hint">Projected stock will update while you fill in the form.</div>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" id="inventoryMovementSubmitLabel">Save Movement</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="inventoryItemModal" tabindex="-1" aria-labelledby="inventoryItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title mb-1" id="inventoryItemModalLabel">Create Inventory Item</h5>
            <div class="small text-body-secondary" id="inventoryItemModalDescription">
              Register a new egg inventory SKU for one of your farms.
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="{{ route('inventory.items.store') }}" id="inventoryItemForm">
          @csrf
          <input type="hidden" name="inventory_form_mode" id="inventory_form_mode" value="{{ $oldInventoryFormMode }}" />
          <input type="hidden" name="item_id" id="inventory_form_item_id" value="{{ $oldEditItemId > 0 ? $oldEditItemId : '' }}" />
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label" for="inventory_item_farm_id">Farm</label>
                <select id="inventory_item_farm_id" name="farm_id" class="form-select" required>
                  <option value="">Select farm</option>
                  @foreach ($farmRows as $farm)
                    <option value="{{ $farm->id }}" @selected((int) old('farm_id') === (int) $farm->id)>{{ $farm->farm_name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="inventory_item_code">Item Code</label>
                <input type="text" id="inventory_item_code" name="item_code" class="form-control" maxlength="40" value="{{ old('item_code') }}" required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="inventory_egg_type">Egg Type</label>
                <input type="text" id="inventory_egg_type" name="egg_type" class="form-control" maxlength="80" value="{{ old('egg_type', 'Chicken Egg') }}" required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="inventory_size_class">Size Class</label>
                <select id="inventory_size_class" name="size_class" class="form-select" required>
                  @foreach (['Reject', 'Peewee', 'Pullet', 'Small', 'Medium', 'Large', 'Extra-Large', 'Jumbo'] as $sizeClass)
                    <option value="{{ $sizeClass }}" @selected(old('size_class', 'Large') === $sizeClass)>{{ $sizeClass }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label" for="inventory_reorder_level">Reorder Level</label>
                <input type="number" min="0" step="1" id="inventory_reorder_level" name="reorder_level" class="form-control" value="{{ old('reorder_level', 50) }}" required />
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label" for="inventory_item_unit_cost">Unit Cost</label>
                <input type="number" min="0" step="0.01" id="inventory_item_unit_cost" name="unit_cost" class="form-control" value="{{ old('unit_cost', '0.00') }}" required />
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label" for="inventory_item_selling_price">Selling Price</label>
                <input type="number" min="0" step="0.01" id="inventory_item_selling_price" name="selling_price" class="form-control" value="{{ old('selling_price', '0.00') }}" required />
              </div>
              <div class="col-12" id="inventory_opening_stock_wrap">
                <div class="border rounded-3 p-3 bg-lighter">
                  <div class="row g-3">
                    <div class="col-12 col-md-4">
                      <label class="form-label" for="inventory_opening_stock_entry_mode">Opening Stock Entry</label>
                      <select id="inventory_opening_stock_entry_mode" name="opening_stock_entry_mode" class="form-select">
                        <option value="EGGS" @selected(old('opening_stock_entry_mode', 'EGGS') === 'EGGS')>Egg count</option>
                        <option value="TRAYS" @selected(old('opening_stock_entry_mode') === 'TRAYS')>Tray breakdown</option>
                      </select>
                    </div>
                    <div class="col-12 col-md-4 inventory-eggs-only-field" id="inventory_opening_stock_input_wrap">
                      <label class="form-label" for="inventory_opening_stock">Opening Stock</label>
                      <input type="number" min="0" step="1" id="inventory_opening_stock" name="opening_stock" class="form-control" value="{{ old('opening_stock', 0) }}" />
                    </div>
                    <div class="col-12 col-md-4">
                      <label class="form-label" for="inventory_opening_reference_no">Opening Reference No.</label>
                      <input type="text" id="inventory_opening_reference_no" name="opening_reference_no" class="form-control" maxlength="80" value="{{ old('opening_reference_no') }}" />
                    </div>
                    <div class="col-12 d-none" id="inventory_opening_tray_wrap">
                      <div class="row g-3">
                        <div class="col-12 col-md-4">
                          <label class="form-label" for="inventory_opening_full_trays">Full Trays</label>
                          <input type="number" min="0" step="1" id="inventory_opening_full_trays" name="opening_full_trays" class="form-control" value="{{ old('opening_full_trays', 0) }}" />
                          <div class="form-text">30 eggs each</div>
                        </div>
                        <div class="col-12 col-md-4">
                          <label class="form-label" for="inventory_opening_half_trays">Half Trays</label>
                          <input type="number" min="0" step="1" id="inventory_opening_half_trays" name="opening_half_trays" class="form-control" value="{{ old('opening_half_trays', 0) }}" />
                          <div class="form-text">15 eggs each</div>
                        </div>
                        <div class="col-12 col-md-4">
                          <label class="form-label" for="inventory_opening_loose_eggs">Loose Eggs</label>
                          <input type="number" min="0" step="1" id="inventory_opening_loose_eggs" name="opening_loose_eggs" class="form-control" value="{{ old('opening_loose_eggs', 0) }}" />
                        </div>
                      </div>
                      <div class="small text-body-secondary mt-2" id="inventory_opening_total_note">Opening stock total: {{ $eggCountLabel(old('opening_stock', 0)) }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                      <label class="form-label" for="inventory_opening_notes">Opening Notes</label>
                      <input type="text" id="inventory_opening_notes" name="opening_notes" class="form-control" maxlength="255" value="{{ old('opening_notes') }}" />
                    </div>
                  </div>
                  <div class="small text-body-secondary mt-2">Opening stock creates an initial audited stock-in movement for the new item.</div>
                </div>
              </div>
              <div class="col-12 d-none" id="inventory_item_stock_note">
                <div class="alert alert-label-info mb-0">
                  Current stock is maintained through the IN, OUT, and ADJUSTMENT workflow. Edit item maintenance here for code, egg type, reorder level, and pricing only.
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" id="inventoryItemSubmitLabel">Create Item</button>
          </div>
        </form>
      </div>
    </div>
    </div>

    @if ($canManagePricing)
      <div class="card inventory-surface">
        <div class="card-header">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div class="inventory-section-title">
              <span class="inventory-stat-icon">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/bank/animated/icons8-money-box--v2.gif',
                  'alt' => 'Pricing',
                  'classes' => '',
                ])
              </span>
              <div>
                <h5 class="mb-1">Owner Price Matrix</h5>
                <div class="small text-body-secondary">Set selling prices per egg size and weight band for each owned farm.</div>
              </div>
            </div>
            <div class="inventory-pricing-help small">
              This updates the `selling_price` of all matching inventory items in the selected farm.
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="inventory-pricing-grid">
            @forelse ($priceMatrixRows as $farmPricing)
              <div class="inventory-pricing-card">
                <div class="card-body">
                  <form method="POST" action="{{ route('inventory.pricing.update') }}">
                    @csrf
                    <input type="hidden" name="farm_id" value="{{ $farmPricing['id'] }}" />
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                      <div>
                        <h6 class="mb-1">{{ $farmPricing['farm_name'] }}</h6>
                        <div class="small text-body-secondary">Price schedule for size classes mapped to the active egg weight ranges.</div>
                      </div>
                      <button type="submit" class="btn btn-primary">Save Price Matrix</button>
                    </div>
                    <div class="table-responsive">
                      <table class="table inventory-pricing-table align-middle mb-0">
                        <thead>
                          <tr>
                            <th>Size Class</th>
                            <th>Weight Range</th>
                            <th>Items</th>
                            <th>Current Price</th>
                            <th>New Selling Price</th>
                          </tr>
                        </thead>
                        <tbody>
                          @foreach ($farmPricing['rows'] as $row)
                            <tr>
                              <td>
                                <span class="badge {{ $sizeClassThemes[$row['class']] ?? 'bg-label-primary' }}">{{ $row['label'] }}</span>
                              </td>
                              <td class="inventory-price-weight">{{ number_format((float) $row['min'], 2) }}g to {{ number_format((float) $row['max'], 2) }}g</td>
                              <td>
                                <div class="fw-semibold">{{ number_format((int) $row['item_count']) }} item{{ (int) $row['item_count'] === 1 ? '' : 's' }}</div>
                                <div class="small text-body-secondary">{{ $traySummary($row['stock_total']) }} in stock</div>
                              </td>
                              <td>
                                @if ((int) $row['item_count'] === 0)
                                  <span class="text-body-secondary small">No inventory item yet</span>
                                @elseif ($row['is_mixed'])
                                  <span class="text-warning small fw-semibold">Mixed prices</span>
                                @else
                                  <span class="fw-semibold">{{ $currency($row['current_price']) }}</span>
                                @endif
                              </td>
                              <td>
                                <input
                                  type="number"
                                  min="0"
                                  step="0.01"
                                  name="price_matrix[{{ $row['slug'] }}]"
                                  class="form-control"
                                  value="{{ old('farm_id') == $farmPricing['id'] ? old('price_matrix.' . $row['slug'], $row['current_price']) : $row['current_price'] }}"
                                  placeholder="0.00" />
                              </td>
                            </tr>
                          @endforeach
                        </tbody>
                      </table>
                    </div>
                  </form>
                </div>
              </div>
            @empty
              <div class="inventory-empty-state">No owned farms are available for owner price management yet.</div>
            @endforelse
          </div>
        </div>
      </div>
    @endif

    <div class="row g-4">
    <div class="col-12 col-xl-8">
      <div class="card inventory-surface h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div class="inventory-section-title">
            <span class="inventory-stat-icon">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/ecommerce/animated/icons8-box--v2.gif',
                'alt' => 'Inventory items',
                'classes' => '',
              ])
            </span>
            <div>
              <h5 class="mb-1">Inventory Items</h5>
              <div class="small text-body-secondary">Egg item stock per farm and size classification.</div>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-label-primary">{{ number_format($itemRows?->total() ?? 0) }} items</span>
            <button
              type="button"
              class="btn btn-sm btn-outline-primary inventory-item-create-trigger"
              data-bs-toggle="modal"
              data-bs-target="#inventoryItemModal"
              data-form-mode="create-item"
              data-form-title="Create Inventory Item"
              data-form-description="Register a new egg inventory SKU for one of your farms."
              data-submit-label="Create Item">
              New Item
            </button>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Farm</th>
                  <th>Stock</th>
                  <th>Reorder</th>
                  <th>Unit Price</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                @forelse ($itemRows as $item)
                  @php
                    $unitPrice = (float) ($item->selling_price ?: $item->unit_cost);
                    $isLowStock = (int) $item->current_stock <= (int) $item->reorder_level;
                    $sizeTheme = $sizeClassThemes[$item->size_class] ?? 'bg-label-primary';
                  @endphp
                  <tr>
                    <td>
                      <div class="inventory-table-item">
                        <span class="inventory-table-icon">
                          @include('partials.curated-shell-icon', [
                            'src' => 'resources/icons/dusk/food/icons/icons8-kawaii-egg.png',
                            'alt' => $item->size_class,
                            'classes' => '',
                          ])
                        </span>
                        <div>
                          <div class="fw-semibold">{{ $item->item_code }}</div>
                          <div class="small text-body-secondary">{{ $item->egg_type }}</div>
                          <div class="mt-1">
                            <span class="badge {{ $sizeTheme }}">{{ $item->size_class }}</span>
                          </div>
                        </div>
                      </div>
                    </td>
                    <td>{{ $item->farm?->farm_name ?? '-' }}</td>
                    <td>
                      <div class="fw-semibold">{{ $eggCountLabel($item->current_stock) }}</div>
                      <div class="small text-body-secondary">{{ $trayLabel($item->current_stock) }}</div>
                    </td>
                    <td>{{ number_format((int) $item->reorder_level) }}</td>
                    <td>{{ $currency($unitPrice) }}</td>
                    <td>
                      @if ($isLowStock)
                        <span class="badge bg-label-warning">Low Stock</span>
                      @else
                        <span class="badge bg-label-success">Healthy</span>
                      @endif
                    </td>
                    <td class="text-end">
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-primary inventory-item-edit-trigger"
                        data-bs-toggle="modal"
                        data-bs-target="#inventoryItemModal"
                        data-edit-url="{{ route('inventory.items.update', $item) }}"
                        data-item-id="{{ $item->id }}"
                        data-farm-id="{{ $item->farm_id }}"
                        data-item-code="{{ $item->item_code }}"
                        data-egg-type="{{ $item->egg_type }}"
                        data-size-class="{{ $item->size_class }}"
                        data-reorder-level="{{ $item->reorder_level }}"
                        data-unit-cost="{{ number_format((float) $item->unit_cost, 2, '.', '') }}"
                        data-selling-price="{{ number_format((float) $item->selling_price, 2, '.', '') }}">
                        Edit
                      </button>
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="7" class="py-5">
                      <div class="inventory-empty-state">
                        No inventory items found in your current scope.
                      </div>
                    </td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        @if ($itemRows && $itemRows->hasPages())
          <div class="card-footer">
            {{ $itemRows->links() }}
          </div>
        @endif
      </div>
    </div>

    <div class="col-12 col-xl-4">
      <div class="inventory-side-stack">
      <div class="card inventory-surface">
        <div class="card-header">
          <div class="inventory-section-title">
            <span class="inventory-stat-icon">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/man/icons/icons8-farmer-male.png',
                'alt' => 'Farm scope',
                'classes' => '',
              ])
            </span>
            <div>
              <h5 class="mb-1">Farm Scope</h5>
              <div class="small text-body-secondary">Only farms assigned to your account are listed here.</div>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="d-grid gap-2">
            @forelse ($farmRows as $farm)
              <div class="inventory-farm-card">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/animals/icons/icons8-chicken.png',
                  'alt' => 'Farm',
                  'classes' => '',
                ])
                <div>
                  <div class="fw-semibold">{{ $farm->farm_name }}</div>
                  <div class="small text-body-secondary">{{ number_format((int) ($farm->egg_items_count ?? 0)) }} items · {{ $traySummary($farm->farm_stock_total ?? 0) }}</div>
                </div>
              </div>
            @empty
              <div class="inventory-empty-state">No active farms are linked to your account yet.</div>
            @endforelse
          </div>
        </div>
      </div>

      <div class="card inventory-surface">
        <div class="card-header">
          <div class="inventory-section-title">
            <span class="inventory-stat-icon">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/clock/animated/icons8-clock--v2.gif',
                'alt' => 'Recent stock movements',
                'classes' => '',
              ])
            </span>
            <div>
              <h5 class="mb-1">Recent Stock Movements</h5>
              <div class="small text-body-secondary">Latest inventory entries recorded in your farm scope.</div>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="d-grid gap-3">
            @forelse ($movementRows as $movement)
              <div class="inventory-movement-card">
                <div class="inventory-movement-card-head">
                  <div>
                    <div class="fw-semibold">{{ $movement->item?->item_code ?? 'Inventory item' }}</div>
                    <div class="small text-body-secondary">{{ $movement->item?->farm?->farm_name ?? '-' }} · {{ $movement->movement_date?->format('Y-m-d') ?? '-' }}</div>
                  </div>
                  <span class="badge {{ $movementThemes[$movement->movement_type]['badge'] ?? 'bg-label-secondary' }}">
                    {{ $movement->movement_type }}
                  </span>
                </div>
                <div class="small text-body-secondary">
                  Qty {{ $eggCountLabel($movement->quantity) }} ({{ $trayLabel($movement->quantity) }}) · Stock {{ number_format((int) $movement->stock_before) }} to {{ number_format((int) $movement->stock_after) }}
                </div>
                <div class="small text-body-secondary">{{ $trayLabel($movement->stock_before) }} to {{ $trayLabel($movement->stock_after) }}</div>
                @if ($movement->reference_no || $movement->notes)
                  <div class="small mt-2">
                    @if ($movement->reference_no)
                      <span class="fw-semibold">Ref:</span> {{ $movement->reference_no }}
                    @endif
                    @if ($movement->notes)
                      <div class="text-body-secondary">{{ $movement->notes }}</div>
                    @endif
                  </div>
                @endif
              </div>
            @empty
              <div class="inventory-empty-state">No stock movements recorded yet.</div>
            @endforelse
          </div>
        </div>
      </div>
      </div>
    </div>
  </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const formatTrayCount = function (count) {
        const normalized = Math.max(0, Number(count || 0));
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

      const modalElement = document.getElementById('inventoryMovementModal');
      const modalLabel = document.getElementById('inventoryMovementModalLabel');
      const modalDescription = document.getElementById('inventoryMovementModalDescription');
      const movementTypeInput = document.getElementById('inventory_movement_type');
      const adjustmentDirectionWrap = document.getElementById('inventory_adjustment_direction_wrap');
      const adjustmentDirectionSelect = document.getElementById('inventory_adjustment_direction');
      const submitLabel = document.getElementById('inventoryMovementSubmitLabel');
      const itemSelect = document.getElementById('inventory_item_id');
      const quantityEntryModeSelect = document.getElementById('inventory_quantity_entry_mode');
      const quantityWrap = document.getElementById('inventory_quantity_wrap');
      const quantityInput = document.getElementById('inventory_quantity');
      const quantityTrayWrap = document.getElementById('inventory_quantity_tray_wrap');
      const quantityFullTraysInput = document.getElementById('inventory_quantity_full_trays');
      const quantityHalfTraysInput = document.getElementById('inventory_quantity_half_trays');
      const quantityLooseEggsInput = document.getElementById('inventory_quantity_loose_eggs');
      const quantityTotalNote = document.getElementById('inventory_quantity_total_note');
      const unitCostInput = document.getElementById('inventory_unit_cost');
      const stockPreview = document.getElementById('inventory_stock_preview');
      const stockPreviewHint = document.getElementById('inventory_stock_preview_hint');
      const itemModalElement = document.getElementById('inventoryItemModal');
      const itemModalLabel = document.getElementById('inventoryItemModalLabel');
      const itemModalDescription = document.getElementById('inventoryItemModalDescription');
      const itemModal = itemModalElement && typeof window.bootstrap !== 'undefined'
        ? new window.bootstrap.Modal(itemModalElement)
        : null;
      const itemForm = document.getElementById('inventoryItemForm');
      const itemFormModeInput = document.getElementById('inventory_form_mode');
      const itemFormItemIdInput = document.getElementById('inventory_form_item_id');
      const itemFarmInput = document.getElementById('inventory_item_farm_id');
      const itemCodeInput = document.getElementById('inventory_item_code');
      const itemEggTypeInput = document.getElementById('inventory_egg_type');
      const itemSizeClassInput = document.getElementById('inventory_size_class');
      const itemReorderLevelInput = document.getElementById('inventory_reorder_level');
      const itemUnitCostInput = document.getElementById('inventory_item_unit_cost');
      const itemSellingPriceInput = document.getElementById('inventory_item_selling_price');
      const itemOpeningStockWrap = document.getElementById('inventory_opening_stock_wrap');
      const itemOpeningStockEntryModeSelect = document.getElementById('inventory_opening_stock_entry_mode');
      const itemOpeningStockInputWrap = document.getElementById('inventory_opening_stock_input_wrap');
      const itemOpeningStockInput = document.getElementById('inventory_opening_stock');
      const itemOpeningTrayWrap = document.getElementById('inventory_opening_tray_wrap');
      const itemOpeningFullTraysInput = document.getElementById('inventory_opening_full_trays');
      const itemOpeningHalfTraysInput = document.getElementById('inventory_opening_half_trays');
      const itemOpeningLooseEggsInput = document.getElementById('inventory_opening_loose_eggs');
      const itemOpeningTotalNote = document.getElementById('inventory_opening_total_note');
      const itemOpeningReferenceInput = document.getElementById('inventory_opening_reference_no');
      const itemOpeningNotesInput = document.getElementById('inventory_opening_notes');
      const itemStockNote = document.getElementById('inventory_item_stock_note');
      const itemSubmitLabel = document.getElementById('inventoryItemSubmitLabel');
      const createItemDefaults = {
        farmId: @json((int) old('farm_id')),
        itemCode: @json(old('item_code', '')),
        eggType: @json(old('egg_type', 'Chicken Egg')),
        sizeClass: @json(old('size_class', 'Large')),
        reorderLevel: @json(old('reorder_level', 50)),
        unitCost: @json(old('unit_cost', '0.00')),
        sellingPrice: @json(old('selling_price', '0.00')),
        openingStockEntryMode: @json(old('opening_stock_entry_mode', 'EGGS')),
        openingStock: @json(old('opening_stock', 0)),
        openingFullTrays: @json(old('opening_full_trays', 0)),
        openingHalfTrays: @json(old('opening_half_trays', 0)),
        openingLooseEggs: @json(old('opening_loose_eggs', 0)),
        openingReferenceNo: @json(old('opening_reference_no', '')),
        openingNotes: @json(old('opening_notes', '')),
      };
      const movementModal = modalElement && typeof window.bootstrap !== 'undefined'
        ? new window.bootstrap.Modal(modalElement)
        : null;

      const syncMovementMode = (mode, title, description, submitText) => {
        const normalizedMode = String(mode || 'IN').toUpperCase();
        movementTypeInput.value = normalizedMode;
        if (modalLabel) {
          modalLabel.textContent = title || 'Record Inventory Movement';
        }
        if (modalDescription) {
          modalDescription.textContent = description || 'Create a new stock movement for an item in your farm scope.';
        }
        if (submitLabel) {
          submitLabel.textContent = submitText || 'Save Movement';
        }

        const isAdjustment = normalizedMode === 'ADJUSTMENT';
        adjustmentDirectionWrap.classList.toggle('d-none', !isAdjustment);
        adjustmentDirectionSelect.required = isAdjustment;
        if (!isAdjustment) {
          adjustmentDirectionSelect.value = '';
        }
      };

      const getTrayEggCount = (fullTraysInput, halfTraysInput, looseEggsInput) => {
        const fullTrays = Math.max(0, Number(fullTraysInput?.value || 0));
        const halfTrays = Math.max(0, Number(halfTraysInput?.value || 0));
        const looseEggs = Math.max(0, Number(looseEggsInput?.value || 0));

        return (fullTrays * 30) + (halfTrays * 15) + looseEggs;
      };

      const syncMovementQuantityMode = () => {
        const isTrayMode = String(quantityEntryModeSelect?.value || 'EGGS').toUpperCase() === 'TRAYS';
        quantityWrap.classList.toggle('d-none', isTrayMode);
        quantityTrayWrap.classList.toggle('d-none', !isTrayMode);
        quantityInput.required = !isTrayMode;

        if (isTrayMode) {
          const trayEggCount = getTrayEggCount(quantityFullTraysInput, quantityHalfTraysInput, quantityLooseEggsInput);
          quantityInput.value = String(trayEggCount);
          quantityTotalNote.textContent = `Tray entry total: ${trayEggCount} egg${trayEggCount === 1 ? '' : 's'} (${formatTrayCount(trayEggCount)})`;
        } else {
          quantityTotalNote.textContent = `Tray entry total: ${quantityInput.value || 0} egg${Number(quantityInput.value || 0) === 1 ? '' : 's'}`;
        }
      };

      const syncOpeningStockMode = () => {
        const isTrayMode = String(itemOpeningStockEntryModeSelect?.value || 'EGGS').toUpperCase() === 'TRAYS';
        itemOpeningStockInputWrap.classList.toggle('d-none', isTrayMode);
        itemOpeningTrayWrap.classList.toggle('d-none', !isTrayMode);

        if (isTrayMode) {
          const trayEggCount = getTrayEggCount(itemOpeningFullTraysInput, itemOpeningHalfTraysInput, itemOpeningLooseEggsInput);
          itemOpeningStockInput.value = String(trayEggCount);
          itemOpeningTotalNote.textContent = `Opening stock total: ${trayEggCount} egg${trayEggCount === 1 ? '' : 's'} (${formatTrayCount(trayEggCount)})`;
        } else {
          const openingStock = Math.max(0, Number(itemOpeningStockInput.value || 0));
          itemOpeningTotalNote.textContent = `Opening stock total: ${openingStock} egg${openingStock === 1 ? '' : 's'} (${formatTrayCount(openingStock)})`;
        }
      };

      const syncPreview = () => {
        const selectedOption = itemSelect.options[itemSelect.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
          stockPreview.textContent = 'Select an item to preview stock movement.';
          stockPreviewHint.textContent = 'Projected stock will update while you fill in the form.';
          return;
        }

        const currentStock = Number(selectedOption.getAttribute('data-stock') || 0);
        const unitCost = selectedOption.getAttribute('data-unit-cost') || '';
        const isTrayMode = String(quantityEntryModeSelect?.value || 'EGGS').toUpperCase() === 'TRAYS';
        const quantity = isTrayMode
          ? getTrayEggCount(quantityFullTraysInput, quantityHalfTraysInput, quantityLooseEggsInput)
          : Number(quantityInput.value || 0);
        const movementType = String(movementTypeInput.value || 'IN').toUpperCase();
        const adjustmentDirection = String(adjustmentDirectionSelect.value || '');
        let projectedStock = currentStock;

        if (movementType === 'IN') {
          projectedStock = currentStock + quantity;
        } else if (movementType === 'OUT') {
          projectedStock = currentStock - quantity;
        } else if (movementType === 'ADJUSTMENT') {
          projectedStock = adjustmentDirection === 'DECREASE'
            ? currentStock - quantity
            : currentStock + quantity;
        }

        if (!unitCostInput.value && unitCost !== '') {
          unitCostInput.value = unitCost;
        }

        quantityInput.value = String(quantity);
        stockPreview.textContent = `${selectedOption.getAttribute('data-item-label') || 'Inventory item'}: ${currentStock} eggs (${formatTrayCount(currentStock)}) -> ${projectedStock} eggs (${formatTrayCount(projectedStock)})`;
        stockPreviewHint.textContent = projectedStock < 0
          ? 'Projected stock is below zero. Reduce the quantity or switch movement mode.'
          : 'Reference and notes will be stored in movement history.';
      };

      document.querySelectorAll('.inventory-movement-trigger').forEach((button) => {
        button.addEventListener('click', function () {
          syncMovementMode(
            button.getAttribute('data-movement-type'),
            button.getAttribute('data-movement-title'),
            button.getAttribute('data-movement-description'),
            button.getAttribute('data-submit-label')
          );
          syncPreview();
        });
      });

      quantityEntryModeSelect.addEventListener('change', function () {
        syncMovementQuantityMode();
        syncPreview();
      });
      itemSelect.addEventListener('change', syncPreview);
      quantityInput.addEventListener('input', syncPreview);
      quantityFullTraysInput.addEventListener('input', function () {
        syncMovementQuantityMode();
        syncPreview();
      });
      quantityHalfTraysInput.addEventListener('input', function () {
        syncMovementQuantityMode();
        syncPreview();
      });
      quantityLooseEggsInput.addEventListener('input', function () {
        syncMovementQuantityMode();
        syncPreview();
      });
      adjustmentDirectionSelect.addEventListener('change', syncPreview);
      itemOpeningStockEntryModeSelect.addEventListener('change', syncOpeningStockMode);
      itemOpeningStockInput.addEventListener('input', syncOpeningStockMode);
      itemOpeningFullTraysInput.addEventListener('input', syncOpeningStockMode);
      itemOpeningHalfTraysInput.addEventListener('input', syncOpeningStockMode);
      itemOpeningLooseEggsInput.addEventListener('input', syncOpeningStockMode);

      const resetItemFormToCreate = () => {
        itemForm.method = 'POST';
        itemForm.action = @json(route('inventory.items.store'));
        itemForm.querySelectorAll('input[name="_method"]').forEach((input) => input.remove());
        itemFormModeInput.value = 'create-item';
        itemFormItemIdInput.value = '';
        itemFarmInput.disabled = false;
        itemFarmInput.value = createItemDefaults.farmId ? String(createItemDefaults.farmId) : '';
        itemCodeInput.value = createItemDefaults.itemCode;
        itemEggTypeInput.value = createItemDefaults.eggType;
        itemSizeClassInput.value = createItemDefaults.sizeClass;
        itemReorderLevelInput.value = String(createItemDefaults.reorderLevel);
        itemUnitCostInput.value = String(createItemDefaults.unitCost);
        itemSellingPriceInput.value = String(createItemDefaults.sellingPrice);
        itemOpeningStockWrap.classList.remove('d-none');
        itemStockNote.classList.add('d-none');
        itemOpeningStockEntryModeSelect.value = createItemDefaults.openingStockEntryMode || 'EGGS';
        itemOpeningStockInput.value = String(createItemDefaults.openingStock);
        itemOpeningFullTraysInput.value = String(createItemDefaults.openingFullTrays);
        itemOpeningHalfTraysInput.value = String(createItemDefaults.openingHalfTrays);
        itemOpeningLooseEggsInput.value = String(createItemDefaults.openingLooseEggs);
        itemOpeningReferenceInput.value = createItemDefaults.openingReferenceNo;
        itemOpeningNotesInput.value = createItemDefaults.openingNotes;
        itemSubmitLabel.textContent = 'Create Item';
        itemModalLabel.textContent = 'Create Inventory Item';
        itemModalDescription.textContent = 'Register a new egg inventory SKU for one of your farms.';
        syncOpeningStockMode();
      };

      const setItemFormMethodPut = () => {
        let methodInput = itemForm.querySelector('input[name="_method"]');
        if (!methodInput) {
          methodInput = document.createElement('input');
          methodInput.type = 'hidden';
          methodInput.name = '_method';
          itemForm.appendChild(methodInput);
        }
        methodInput.value = 'PUT';
      };

      document.querySelectorAll('.inventory-item-create-trigger').forEach((button) => {
        button.addEventListener('click', function () {
          resetItemFormToCreate();
          itemSubmitLabel.textContent = button.getAttribute('data-submit-label') || 'Create Item';
          itemModalLabel.textContent = button.getAttribute('data-form-title') || 'Create Inventory Item';
          itemModalDescription.textContent = button.getAttribute('data-form-description') || 'Register a new egg inventory SKU for one of your farms.';
        });
      });

      document.querySelectorAll('.inventory-item-edit-trigger').forEach((button) => {
        button.addEventListener('click', function () {
          setItemFormMethodPut();
          itemForm.action = button.getAttribute('data-edit-url') || @json(route('inventory.items.store'));
          itemFormModeInput.value = 'edit-item';
          itemFormItemIdInput.value = button.getAttribute('data-item-id') || '';
          itemFarmInput.value = button.getAttribute('data-farm-id') || '';
          itemFarmInput.disabled = true;
          itemCodeInput.value = button.getAttribute('data-item-code') || '';
          itemEggTypeInput.value = button.getAttribute('data-egg-type') || '';
          itemSizeClassInput.value = button.getAttribute('data-size-class') || 'Large';
          itemReorderLevelInput.value = button.getAttribute('data-reorder-level') || '0';
          itemUnitCostInput.value = button.getAttribute('data-unit-cost') || '0.00';
          itemSellingPriceInput.value = button.getAttribute('data-selling-price') || '0.00';
          itemOpeningStockWrap.classList.add('d-none');
          itemStockNote.classList.remove('d-none');
          itemSubmitLabel.textContent = 'Save Item';
          itemModalLabel.textContent = 'Edit Inventory Item';
          itemModalDescription.textContent = 'Update item code, egg type, reorder level, and pricing for the selected inventory item.';
        });
      });

      syncMovementMode(
        @json(old('movement_type', 'IN')),
        'Record Inventory Movement',
        'Create a new stock movement for an item in your farm scope.',
        'Save Movement'
      );
      quantityEntryModeSelect.value = @json(old('quantity_entry_mode', 'EGGS'));
      syncMovementQuantityMode();
      syncPreview();

      if (@json($oldInventoryFormMode) === 'edit-item') {
        const targetEditButton = document.querySelector('.inventory-item-edit-trigger[data-item-id="' + String(@json($oldEditItemId)) + '"]');
        if (targetEditButton) {
          targetEditButton.click();
        }
      } else {
        resetItemFormToCreate();
      }

      if (@json($openMovementModal) && movementModal) {
        movementModal.show();
      }

      if (@json($openItemModal) && itemModal) {
        if (@json($oldInventoryFormMode) !== 'edit-item') {
          resetItemFormToCreate();
        }
        itemModal.show();
      }
    });
  </script>
@endsection
