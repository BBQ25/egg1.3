@extends('layouts.admin')

@section('title', 'APEWSD - Admin Settings')

@section('content')
  <style>
    .visibility-toggle:not(:checked)~label .visibility-icon-on {
      display: inline-block !important;
    }

    .visibility-toggle:not(:checked)~label .visibility-icon-off {
      display: none !important;
    }

    .visibility-toggle:checked~label .visibility-icon-on {
      display: none !important;
    }

    .visibility-toggle:checked~label .visibility-icon-off {
      display: inline-block !important;
    }

    .disabled-pages-toolbar .form-control {
      min-height: 2.8rem;
    }

    .disabled-pages-stat {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1rem;
      padding: 1rem 1.1rem;
      background: #fff;
      box-shadow: 0 0.5rem 1.125rem rgba(67, 89, 113, 0.08);
      height: 100%;
    }

    .disabled-pages-stat-value {
      font-size: 1.4rem;
      font-weight: 700;
      line-height: 1;
      color: #566a7f;
    }

    .disabled-pages-stat-label {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #8592a3;
    }

    .disabled-pages-summary {
      border: 1px dashed rgba(105, 108, 255, 0.3);
      border-radius: 1rem;
      background: rgba(105, 108, 255, 0.04);
    }

    .disabled-pages-chip {
      border-radius: 999px;
      border: 1px solid rgba(255, 62, 29, 0.18);
      background: rgba(255, 62, 29, 0.08);
      color: #b42318;
      padding: 0.45rem 0.75rem;
      font-size: 0.8rem;
      line-height: 1;
    }

    .disabled-pages-chip:hover {
      background: rgba(255, 62, 29, 0.14);
      color: #8a120f;
    }

    .disabled-page-tab-count {
      min-width: 1.75rem;
    }

    .disabled-page-collapse-btn {
      min-width: 7rem;
      white-space: nowrap;
    }

    .disabled-pages-empty {
      border: 1px dashed rgba(67, 89, 113, 0.18);
      border-radius: 1rem;
      padding: 1rem;
      text-align: center;
      color: #8592a3;
      background: rgba(67, 89, 113, 0.03);
    }

    .disabled-pages-actionbar {
      position: sticky;
      bottom: 0;
      z-index: 5;
      border-top: 1px solid rgba(67, 89, 113, 0.12);
      background: rgba(255, 255, 255, 0.92);
      backdrop-filter: blur(10px);
      padding-top: 1rem;
      margin-top: 1.5rem;
    }

    .settings-overview-card {
      border-radius: 1.35rem;
      border: 1px solid rgba(67, 89, 113, 0.14);
      box-shadow: 0 1rem 2.25rem rgba(67, 89, 113, 0.08);
    }

    .settings-overview-title {
      font-size: clamp(1.7rem, 1.45rem + 0.75vw, 2.05rem);
      line-height: 1.1;
      letter-spacing: -0.02em;
      color: #233446;
    }

    .settings-overview-lead {
      max-width: 54rem;
      font-size: 1rem;
      line-height: 1.55;
      color: #697a8d;
    }

    .settings-overview-icon {
      width: 3.5rem;
      height: 3.5rem;
      border-radius: 1.1rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: rgba(105, 108, 255, 0.12);
      color: #696cff;
      flex-shrink: 0;
    }

    .settings-overview-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 1rem;
    }

    .settings-overview-stat {
      border-radius: 1rem;
      border: 1px solid rgba(67, 89, 113, 0.12);
      background: rgba(245, 247, 250, 0.72);
      padding: 1rem;
      display: flex;
      align-items: center;
      gap: 0.9rem;
    }

    .settings-overview-stat-icon {
      width: 2.75rem;
      height: 2.75rem;
      border-radius: 0.9rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      font-size: 1.1rem;
    }

    .settings-overview-stat-label {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #8592a3;
    }

    .settings-overview-stat-value {
      font-size: 1rem;
      font-weight: 700;
      line-height: 1.25;
      margin-top: 0.2rem;
      color: #233446;
    }

    .settings-save-panel {
      min-width: min(100%, 19rem);
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1rem;
      padding: 1rem 1rem 0.95rem;
      background: rgba(245, 247, 250, 0.72);
    }

    .settings-save-panel .btn {
      min-height: 2.9rem;
      font-size: 1rem;
      font-weight: 600;
    }

    .settings-dusk-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .settings-dusk-icon-img {
      width: 1rem;
      height: 1rem;
      display: inline-block;
      flex-shrink: 0;
    }

    .settings-save-panel .small {
      font-size: 0.82rem;
      line-height: 1.45;
    }

    .settings-section-card {
      border-radius: 1.15rem;
      border: 1px solid rgba(67, 89, 113, 0.12);
      box-shadow: 0 0.75rem 1.75rem rgba(67, 89, 113, 0.08);
      overflow: hidden;
    }

    .settings-section-card .card-header {
      padding-top: 1.15rem;
      padding-bottom: 1.15rem;
      background: #fff;
      border-bottom: 1px solid rgba(67, 89, 113, 0.1);
    }

    .settings-section-card .card-body {
      padding: 1.35rem;
    }

    .settings-section-anchor {
      scroll-margin-top: 6rem;
    }

    .settings-section-icon {
      width: 3rem;
      height: 3rem;
      border-radius: 1rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: rgba(105, 108, 255, 0.12);
      color: #696cff;
      flex-shrink: 0;
    }

    .settings-section-title {
      color: #233446;
      font-weight: 700;
      font-size: 1.15rem;
      line-height: 1.2;
    }

    .settings-section-eyebrow {
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-weight: 700;
      color: #8592a3;
    }

    .settings-section-subtitle {
      color: #697a8d;
      font-size: 0.88rem;
      line-height: 1.5;
      margin-bottom: 0;
    }

    .settings-status-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 0.75rem;
    }

    .settings-status-card {
      border-radius: 1rem;
      border: 1px solid rgba(67, 89, 113, 0.12);
      background: rgba(245, 247, 250, 0.7);
      padding: 0.9rem 1rem;
    }

    .settings-status-card .label {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #8592a3;
      margin-bottom: 0.25rem;
    }

    .settings-status-card .value {
      font-size: 1rem;
      line-height: 1.25;
      font-weight: 700;
      color: #233446;
    }

    .settings-form-note {
      font-size: 0.82rem;
      color: #8592a3;
    }

    .settings-typography-card .card-header {
      padding-top: 0.95rem;
      padding-bottom: 0.95rem;
    }

    .settings-typography-card {
      overflow: visible;
    }

    .settings-typography-card .card-body {
      padding: 0.95rem 1rem 1rem;
      overflow: visible;
    }

    .settings-typography-card .settings-status-grid {
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 0.55rem;
      margin-bottom: 0.85rem !important;
    }

    .settings-typography-card .settings-status-card {
      padding: 0.65rem 0.8rem;
    }

    .settings-font-selector-row {
      --bs-gutter-x: 0.9rem;
      --bs-gutter-y: 0.9rem;
    }

    .settings-font-picker-card,
    .settings-font-preview-card {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1rem;
      background: #fff;
      box-shadow: none;
      height: 100%;
    }

    .settings-font-picker-label {
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-weight: 700;
      color: #8592a3;
      margin-bottom: 0.55rem;
    }

    .settings-font-trigger {
      min-height: 3.35rem;
      border-radius: 0.9rem;
      padding: 0.75rem 0.9rem;
    }

    .settings-font-trigger-meta {
      font-size: 0.72rem;
      color: #8592a3;
      line-height: 1.2;
    }

    .settings-font-trigger-label {
      font-weight: 600;
      color: #233446;
      line-height: 1.2;
    }

    .settings-font-dropdown-menu {
      width: 100%;
      padding: 0.45rem;
      border-radius: 0.9rem;
      border: 1px solid rgba(67, 89, 113, 0.12);
      box-shadow: 0 0.75rem 1.5rem rgba(67, 89, 113, 0.14);
    }

    .settings-font-dropdown-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      border-radius: 0.8rem;
      padding: 0.7rem 0.8rem;
      color: #566a7f;
      transition: 0.16s ease;
    }

    .settings-font-dropdown-item:hover,
    .settings-font-dropdown-item:focus,
    .settings-font-dropdown-item.active {
      background: rgba(105, 108, 255, 0.08);
      color: #233446;
    }

    .settings-font-dropdown-item-sample {
      font-size: 0.76rem;
      color: #697a8d;
      white-space: nowrap;
    }

    .settings-font-preview-sample {
      font-size: 1.25rem;
      line-height: 1.35;
      color: #233446;
    }

    .settings-font-preview-caption {
      font-size: 0.78rem;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #8592a3;
    }

    .settings-page-grid {
      align-items: start;
    }

    .settings-sidebar {
      position: sticky;
      top: 100px;
      z-index: 10;
    }

    .settings-sidebar-card {
      border-radius: 1.15rem;
      border: 1px solid rgba(67, 89, 113, 0.12);
      box-shadow: 0 0.75rem 1.75rem rgba(67, 89, 113, 0.08);
    }

    .settings-sidebar-nav a {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      padding: 0.85rem 0.95rem;
      border-radius: 0.95rem;
      color: #566a7f;
      text-decoration: none;
      border: 1px solid rgba(67, 89, 113, 0.08);
      transition: 0.18s ease;
    }

    .settings-sidebar-nav a:hover,
    .settings-sidebar-nav a:focus {
      color: #1f2f9b;
      border-color: rgba(105, 108, 255, 0.24);
      background: rgba(105, 108, 255, 0.05);
    }

    .settings-quick-link {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      padding: 0.8rem 0.95rem;
      border-radius: 0.95rem;
      border: 1px solid rgba(67, 89, 113, 0.1);
      background: #fff;
      color: #566a7f;
      font-size: 0.94rem;
      text-decoration: none;
      transition: 0.18s ease;
    }

    .settings-quick-link:hover,
    .settings-quick-link:focus {
      color: #1f2f9b;
      border-color: rgba(105, 108, 255, 0.24);
      background: rgba(105, 108, 255, 0.05);
    }

    .settings-quick-link-icon {
      width: 2.25rem;
      height: 2.25rem;
      border-radius: 0.8rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: rgba(105, 108, 255, 0.12);
      color: #696cff;
      flex-shrink: 0;
    }

    .settings-quick-link-label {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      min-width: 0;
    }

    .settings-mini-stat-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.75rem;
    }

    .settings-mini-stat {
      border-radius: 1rem;
      border: 1px solid rgba(67, 89, 113, 0.1);
      background: #fff;
      padding: 0.9rem 1rem;
    }

    .settings-mini-stat-label {
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #8592a3;
      margin-bottom: 0.3rem;
    }

    .settings-mini-stat-value {
      font-size: 1rem;
      line-height: 1.25;
      font-weight: 700;
      color: #233446;
    }

    .settings-summary-preview {
      max-height: 15rem;
      overflow: auto;
      padding-right: 0.35rem;
    }

    .settings-summary-preview.collapsed {
      max-height: 4.75rem;
      overflow: hidden;
    }

    .settings-summary-chip {
      border-radius: 999px;
      font-size: 0.75rem;
      line-height: 1;
      padding: 0.4rem 0.65rem;
      background: rgba(255, 62, 29, 0.08);
      border: 1px solid rgba(255, 62, 29, 0.14);
      color: #b42318;
      display: inline-flex;
      align-items: center;
    }

    .settings-summary-list {
      display: flex;
      flex-wrap: wrap;
      gap: 0.45rem;
    }

    .settings-alert {
      border-radius: 1rem;
      border-width: 0;
      box-shadow: 0 12px 30px rgba(67, 89, 113, 0.08);
    }

    .settings-helper-stack > * + * {
      margin-top: 0.75rem;
    }

    .settings-helper-stack h6 {
      font-size: 0.92rem;
      font-weight: 700;
      color: #233446;
    }

    .settings-aside-save {
      border-radius: 1rem;
      background: #fff;
      border: 1px solid rgba(105, 108, 255, 0.14);
      box-shadow: inset 0 0 0 1px rgba(105, 108, 255, 0.02);
    }

    .settings-muted-list {
      display: grid;
      gap: 0.5rem;
    }

    .settings-muted-list-item {
      display: flex;
      align-items: flex-start;
      gap: 0.6rem;
      color: #697a8d;
      font-size: 0.9rem;
    }

    .settings-weight-range-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1rem;
    }

    .settings-weight-range-card {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1rem;
      background: #fff;
      padding: 1rem;
      box-shadow: 0 0.5rem 1.25rem rgba(67, 89, 113, 0.06);
    }

    .settings-weight-range-class {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #8592a3;
    }

    .settings-weight-range-title {
      color: #233446;
      font-weight: 700;
      font-size: 1rem;
    }

    .settings-weight-range-preview {
      border-radius: 0.85rem;
      background: rgba(105, 108, 255, 0.06);
      color: #566a7f;
      padding: 0.65rem 0.8rem;
      font-size: 0.84rem;
    }

    .settings-weight-range-input .input-group-text {
      min-width: 4.5rem;
      justify-content: center;
      font-weight: 600;
    }

    @media (max-width: 1199.98px) {
      .settings-sidebar {
        position: static;
        top: auto;
      }
    }

    @media (max-width: 767.98px) {
      .settings-section-card .card-body {
        padding: 1rem;
      }

      .settings-mini-stat-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
  @php
    $disabledPagesCount = count($disabledPages ?? []);
    $sidebarDisabledPreview = array_slice($disabledPages ?? [], 0, 8);
    $sidebarDisabledOverflow = max(0, $disabledPagesCount - count($sidebarDisabledPreview));
    $checkedPages = old('disabled_pages', $disabledPages);
    $checkedPages = array_values(array_unique(array_map('strval', is_array($checkedPages) ? $checkedPages : [])));
    $storedEggWeightRanges = is_array($eggWeightRanges ?? null) ? $eggWeightRanges : [];
    $submittedEggWeightRanges = old('egg_weight_ranges');
    $resolvedEggWeightRanges = [];

    foreach (\App\Support\EggWeightRanges::definitions() as $rangeDefinition) {
      $rangeSlug = $rangeDefinition['slug'];
      $submittedEntry = is_array($submittedEggWeightRanges[$rangeSlug] ?? null) ? $submittedEggWeightRanges[$rangeSlug] : null;
      $storedEntry = is_array($storedEggWeightRanges[$rangeSlug] ?? null) ? $storedEggWeightRanges[$rangeSlug] : null;

      $resolvedEggWeightRanges[$rangeSlug] = [
        'slug' => $rangeSlug,
        'class' => $rangeDefinition['class'],
        'label' => $rangeDefinition['label'],
        'min' => (string) ($submittedEntry['min'] ?? $storedEntry['min'] ?? number_format((float) $rangeDefinition['min'], 2, '.', '')),
        'max' => (string) ($submittedEntry['max'] ?? $storedEntry['max'] ?? number_format((float) $rangeDefinition['max'], 2, '.', '')),
      ];
    }

    $collectLeafValues = function (array $tree) use (&$collectLeafValues): array {
      $values = [];

      foreach ($tree as $branchKey => $branch) {
        if ($branchKey === '__leaves') {
          foreach ($branch as $leaf) {
            if (is_array($leaf) && isset($leaf['value'])) {
              $values[] = (string) $leaf['value'];
            }
          }
          continue;
        }

        if (is_array($branch)) {
          $values = array_merge($values, $collectLeafValues($branch));
        }
      }

      return array_values(array_unique($values));
    };

    $allDiscoveredPages = [];
    foreach ($categorizedPages as $categoryData) {
      $allDiscoveredPages = array_merge($allDiscoveredPages, $collectLeafValues($categoryData['hierarchy']));
    }

    $allDiscoveredPages = array_values(array_unique($allDiscoveredPages));
    $disabledPagesTotal = count($checkedPages);
    $pageDiscoveryTotal = count($allDiscoveredPages);
    $pageVisibleTotal = max(0, $pageDiscoveryTotal - $disabledPagesTotal);
    $eggWeightRangeCount = count($resolvedEggWeightRanges);
    $eggWeightRangeReject = $resolvedEggWeightRanges['reject'] ?? null;
    $eggWeightRangeJumbo = $resolvedEggWeightRanges['jumbo'] ?? null;
    $selectedAppTimezone = old('app_timezone', $currentAppTimezone ?? ($appTimezoneCode ?? \App\Support\AppTimezone::current()));
    $selectedAppTimezoneLabel = $timezoneOptions[$selectedAppTimezone] ?? ($currentAppTimezoneLabel ?? ($appTimezoneLabel ?? \App\Support\AppTimezone::label($selectedAppTimezone)));
    $loginBypassAvailable = (bool) ($loginBypassAvailable ?? false);
    $loginBypassEnabledValue = (bool) (old('login_bypass_enabled', $loginBypassEnabled ?? false));
    $loginBypassRulesForm = old('login_bypass_rules', $loginBypassRules ?? []);
    $loginBypassRulesForm = is_array($loginBypassRulesForm) ? array_values($loginBypassRulesForm) : [];
    $loginBypassRuleCount = count($loginBypassRulesForm);
    $loginBypassEnabledCount = 0;
    foreach ($loginBypassRulesForm as $ruleEntry) {
      $isEnabled = filter_var($ruleEntry['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
      if ($isEnabled) {
        $loginBypassEnabledCount++;
      }
    }
    $loginBypassUsers = is_array($loginBypassUsers ?? null) ? $loginBypassUsers : [];
    $userRoleLabels = is_array($userRoleLabels ?? null) ? $userRoleLabels : [];
  @endphp

  <div class="card settings-overview-card mb-4">
    <div class="card-body p-4 p-lg-4">
      <div class="d-flex flex-column flex-xl-row align-items-start justify-content-between gap-4">
        <div class="flex-grow-1 pe-xl-4">
          <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge rounded-pill bg-label-primary">Admin Controls</span>
            <span class="badge rounded-pill bg-label-info">System Configuration</span>
            <span class="badge rounded-pill bg-label-warning">Live Visibility Rules</span>
          </div>
          <div class="d-flex align-items-start gap-3">
            <div class="settings-overview-icon">
              <i class="bx bx-cog fs-2"></i>
            </div>
            <div>
              <h1 class="settings-overview-title mb-2">Admin Settings</h1>
              <p class="settings-overview-lead mb-0">
                Manage visual defaults, access perimeter rules, location overlays, and sidebar visibility from one place.
                Keep high-impact system changes visible, structured, and easy to review before saving.
              </p>
            </div>
          </div>
        </div>
        <div class="settings-save-panel">
          <div class="small text-uppercase text-body-secondary fw-semibold mb-2">Live Apply</div>
          <button type="submit" form="admin-settings-form" class="btn btn-primary w-100 settings-dusk-btn">
            @include('partials.curated-shell-icon', [
              'src' => 'resources/icons/dusk/save/animated/icons8-save--v2.gif',
              'alt' => 'Save Settings',
              'classes' => 'settings-dusk-icon-img me-1',
            ])
            Save Settings
          </button>
          <div class="small text-body-secondary mt-2">Changes apply immediately after save.</div>
        </div>
      </div>
      <div class="settings-overview-grid mt-4">
        <div class="settings-overview-stat">
          <span class="settings-overview-stat-icon bg-label-info">
            <i class="bx bx-time-five"></i>
          </span>
          <div>
            <div class="settings-overview-stat-label">Timezone</div>
            <div class="settings-overview-stat-value">{{ $selectedAppTimezoneLabel }}</div>
          </div>
        </div>
        <div class="settings-overview-stat">
          <span class="settings-overview-stat-icon bg-label-primary">
            <i class="bx bx-font-family"></i>
          </span>
          <div>
            <div class="settings-overview-stat-label">Typography</div>
            <div class="settings-overview-stat-value">{{ $fontOptions[$currentFontStyle] ?? 'Figtree' }}</div>
          </div>
        </div>
        <div class="settings-overview-stat">
          <span class="settings-overview-stat-icon bg-label-warning">
            <i class="bx bx-slider-alt"></i>
          </span>
          <div>
            <div class="settings-overview-stat-label">Weight Ranges</div>
            <div class="settings-overview-stat-value">{{ $eggWeightRangeCount }} classes</div>
          </div>
        </div>
        <div class="settings-overview-stat">
          <span class="settings-overview-stat-icon bg-label-danger">
            <i class="bx bx-hide"></i>
          </span>
          <div>
            <div class="settings-overview-stat-label">Hidden Pages</div>
            <div class="settings-overview-stat-value">{{ number_format($disabledPagesCount) }}</div>
          </div>
        </div>
        <div class="settings-overview-stat">
          <span class="settings-overview-stat-icon bg-label-success">
            <i class="bx bx-check-shield"></i>
          </span>
          <div>
            <div class="settings-overview-stat-label">Access Boundary</div>
            <div class="settings-overview-stat-value">Separate page</div>
          </div>
        </div>
        <div class="settings-overview-stat">
          <span class="settings-overview-stat-icon bg-label-info">
            <i class="bx bx-map-alt"></i>
          </span>
          <div>
            <div class="settings-overview-stat-label">Location Overview</div>
            <div class="settings-overview-stat-value">Separate page</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  @if (session('status'))
    <div class="alert alert-success settings-alert" role="alert">
      <div class="fw-semibold mb-1">Settings saved</div>
      <div>{{ session('status') }}</div>
    </div>
  @endif

  @if ($errors->any())
    <div class="alert alert-danger settings-alert" role="alert">
      <div class="fw-semibold mb-1">Please review the highlighted settings</div>
      <div class="small">{{ $errors->count() }} issue{{ $errors->count() === 1 ? '' : 's' }} need attention before saving.</div>
      <ul class="mb-0 mt-2 ps-3 small">
        @foreach (array_slice($errors->all(), 0, 4) as $errorMessage)
          <li>{{ $errorMessage }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="row g-4 g-xl-5 settings-page-grid">
    <div class="col-12 col-xl-8 col-xxl-9">
      <form id="admin-settings-form" method="POST" action="{{ route('admin.settings.update') }}">
        @csrf
        @method('PUT')

        <section id="settings-typography" class="settings-section-anchor">
        @php
          $selectedFontValue = old('font_style', $currentFontStyle);
          $selectedFontLabel = $fontOptions[$selectedFontValue] ?? 'Figtree';
        @endphp
        <div class="card settings-section-card settings-typography-card mb-4">
          <div class="card-header">
            <div class="d-flex align-items-start gap-3">
              <div class="settings-section-icon">
                <i class="bx bx-font fs-3"></i>
              </div>
              <div class="flex-grow-1 min-w-0">
                <div class="settings-section-eyebrow mb-1">Visual Foundation</div>
                <h5 class="card-title settings-section-title mb-1">Typography Design</h5>
                <p class="settings-section-subtitle">Choose the global font family used across layouts, dashboards, forms, and reports.</p>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="settings-status-grid mb-4">
              <div class="settings-status-card">
                <div class="label">Current Font</div>
                <div class="value">{{ $fontOptions[$currentFontStyle] ?? 'Figtree' }}</div>
              </div>
              <div class="settings-status-card">
                <div class="label">Applies To</div>
                <div class="value">Global admin interface</div>
              </div>
            </div>
            <div class="row settings-font-selector-row">
              <div class="col-lg-5">
                <div class="card settings-font-picker-card">
                  <div class="card-body p-3">
                    <div class="settings-font-picker-label">Font Family</div>
                    <input type="hidden" name="font_style" id="font_style_selected" value="{{ $selectedFontValue }}" />
                    <div class="dropdown" data-font-dropdown>
                      <button
                        type="button"
                        class="btn btn-outline-primary settings-font-trigger dropdown-toggle w-100 d-flex align-items-center justify-content-between"
                        id="font-style-dropdown"
                        data-bs-toggle="dropdown"
                        aria-expanded="false">
                        <span class="d-flex align-items-center gap-2 text-start">
                          <span class="avatar avatar-sm bg-label-primary">
                            <i class="bx bx-font-family"></i>
                          </span>
                          <span class="min-w-0">
                            <span class="d-block settings-font-trigger-meta">Selected font</span>
                            <span class="d-block settings-font-trigger-label" id="font-style-dropdown-label">{{ $selectedFontLabel }}</span>
                          </span>
                        </span>
                      </button>
                      <div class="dropdown-menu settings-font-dropdown-menu" aria-labelledby="font-style-dropdown">
                        @foreach ($fontOptions as $fontValue => $fontLabel)
                          <button
                            type="button"
                            class="dropdown-item settings-font-dropdown-item {{ $selectedFontValue === $fontValue ? 'active' : '' }}"
                            data-font-value="{{ $fontValue }}"
                            data-font-label="{{ $fontLabel }}"
                            data-font-family="{{ $fontLabel }}">
                            <span class="min-w-0">
                              <span class="d-block fw-semibold text-truncate">{{ $fontLabel }}</span>
                              <span class="d-block small text-body-secondary">Hover to preview</span>
                            </span>
                            <span
                              class="settings-font-dropdown-item-sample"
                              style="font-family: {{ $fontLabel }} !important;">AaBbCcDdEe</span>
                          </button>
                        @endforeach
                      </div>
                    </div>
                    <div class="settings-form-note mt-2">Open the dropdown, hover a font to preview it, then click to apply the selection.</div>
                  </div>
                </div>                
              </div>
              <div class="col-lg-7">
                <div class="card settings-font-preview-card">
                  <div class="card-body p-3">
                    <div class="d-flex align-items-start justify-content-between gap-3 mb-2">
                      <div>
                        <div class="settings-font-preview-caption">Sample Output</div>
                        <div class="fw-semibold text-heading" id="font-preview-title">{{ $selectedFontLabel }}</div>
                      </div>
                      <span class="badge bg-label-primary rounded-pill">Preview</span>
                    </div>
                    <div class="settings-font-preview-sample mb-2" id="font-preview-sample" style="font-family: {{ $selectedFontLabel }} !important;">The quick brown egg sorts cleanly.</div>
                    <div class="small text-body-secondary" id="font-preview-meta">Hover a dropdown option to preview it before clicking.</div>
                  </div>
                </div>
              </div>
            </div>
            @error('font_style')
              <div class="text-danger small mt-3">{{ $message }}</div>
            @enderror
            <div class="settings-form-note mt-3">Use this only for a system-wide visual change. Existing functionality and content stay the same.</div>
          </div>
        </div>
        </section>

        <section id="settings-timezone" class="settings-section-anchor">
        <div class="card settings-section-card mb-4">
          <div class="card-header">
            <div class="d-flex align-items-start gap-3">
              <div class="settings-section-icon">
                <i class="bx bx-time-five fs-3"></i>
              </div>
              <div class="flex-grow-1 min-w-0">
                <div class="settings-section-eyebrow mb-1">Clock Source</div>
                <h5 class="card-title settings-section-title mb-1">Timezone &amp; Clock</h5>
                <p class="settings-section-subtitle">Choose the timezone used for server-generated timestamps, ingest normalization, monitoring pages, and runtime config responses.</p>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="settings-status-grid mb-4">
              <div class="settings-status-card">
                <div class="label">Current Timezone</div>
                <div class="value">{{ $currentAppTimezoneLabel ?? ($appTimezoneLabel ?? \App\Support\AppTimezone::label()) }}</div>
              </div>
              <div class="settings-status-card">
                <div class="label">Timezone Code</div>
                <div class="value">{{ $currentAppTimezone ?? ($appTimezoneCode ?? \App\Support\AppTimezone::current()) }}</div>
              </div>
            </div>

            <div class="row g-3 align-items-start">
              <div class="col-lg-7">
                <label for="app_timezone" class="form-label fw-semibold">App Timezone</label>
                <select id="app_timezone" name="app_timezone" class="form-select @error('app_timezone') is-invalid @enderror">
                  @foreach ($timezoneOptions as $timezoneValue => $timezoneLabelOption)
                    <option value="{{ $timezoneValue }}" @selected($selectedAppTimezone === $timezoneValue)>{{ $timezoneLabelOption }} ({{ $timezoneValue }})</option>
                  @endforeach
                </select>
                @error('app_timezone')
                  <div class="invalid-feedback">{{ $message }}</div>
                @enderror
              </div>
              <div class="col-lg-5">
                <div class="settings-weight-range-preview">
                  Selected timezone: <strong>{{ $selectedAppTimezoneLabel }}</strong><br />
                  Code: {{ $selectedAppTimezone }}
                </div>
              </div>
            </div>

            <div class="settings-form-note mt-3">Changing this affects new ingest timestamps, batch code generation, monitoring displays, dashboard clocks, and runtime config timestamps. Historical records are not rewritten.</div>
          </div>
        </div>
        </section>

        <section id="settings-weight-ranges" class="settings-section-anchor">
        <div class="card settings-section-card mb-4">
          <div class="card-header">
            <div class="d-flex align-items-start gap-3">
              <div class="settings-section-icon">
                <i class="bx bx-slider-alt fs-3"></i>
              </div>
              <div class="flex-grow-1 min-w-0">
                <div class="settings-section-eyebrow mb-1">Egg Grading Bands</div>
                <h5 class="card-title settings-section-title mb-1">Weight Range Settings</h5>
                <p class="settings-section-subtitle">Define the gram thresholds used by the system as the canonical grading reference for Reject, Peewee, Pullet, Small, Medium, Large, Extra-Large, and Jumbo.</p>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="settings-status-grid mb-4">
              <div class="settings-status-card">
                <div class="label">Managed Classes</div>
                <div class="value">{{ $eggWeightRangeCount }}</div>
              </div>
              <div class="settings-status-card">
                <div class="label">Reject Window</div>
                <div class="value">{{ $eggWeightRangeReject['min'] ?? '0.00' }}g - {{ $eggWeightRangeReject['max'] ?? '0.00' }}g</div>
              </div>
              <div class="settings-status-card">
                <div class="label">Jumbo Window</div>
                <div class="value">{{ $eggWeightRangeJumbo['min'] ?? '0.00' }}g - {{ $eggWeightRangeJumbo['max'] ?? '0.00' }}g</div>
              </div>
            </div>

            <div class="alert alert-primary mb-4 py-3">
              Arrange the ranges from lightest to heaviest. Each minimum must be greater than the maximum of the class before it, so the grading bands never overlap.
            </div>

            <div class="settings-weight-range-grid">
              @foreach ($resolvedEggWeightRanges as $rangeEntry)
                @php
                  $minField = 'egg_weight_ranges.' . $rangeEntry['slug'] . '.min';
                  $maxField = 'egg_weight_ranges.' . $rangeEntry['slug'] . '.max';
                @endphp
                <div class="settings-weight-range-card">
                  <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                    <div>
                      <div class="settings-weight-range-class">{{ $rangeEntry['class'] }}</div>
                      <div class="settings-weight-range-title">{{ $rangeEntry['label'] }}</div>
                    </div>
                    <span class="badge bg-label-primary rounded-pill">grams</span>
                  </div>

                  <div class="settings-weight-range-preview mb-3">
                    Current band: {{ $rangeEntry['min'] }}g to {{ $rangeEntry['max'] }}g
                  </div>

                  <div class="settings-weight-range-input mb-3">
                    <label class="form-label fw-semibold" for="egg_weight_ranges_{{ $rangeEntry['slug'] }}_min">Minimum</label>
                    <div class="input-group">
                      <span class="input-group-text">Min</span>
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        class="form-control @error($minField) is-invalid @enderror"
                        id="egg_weight_ranges_{{ $rangeEntry['slug'] }}_min"
                        name="egg_weight_ranges[{{ $rangeEntry['slug'] }}][min]"
                        value="{{ $rangeEntry['min'] }}"
                        inputmode="decimal" />
                    </div>
                    @error($minField)
                      <div class="text-danger small mt-2">{{ $message }}</div>
                    @enderror
                  </div>

                  <div class="settings-weight-range-input">
                    <label class="form-label fw-semibold" for="egg_weight_ranges_{{ $rangeEntry['slug'] }}_max">Maximum</label>
                    <div class="input-group">
                      <span class="input-group-text">Max</span>
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        class="form-control @error($maxField) is-invalid @enderror"
                        id="egg_weight_ranges_{{ $rangeEntry['slug'] }}_max"
                        name="egg_weight_ranges[{{ $rangeEntry['slug'] }}][max]"
                        value="{{ $rangeEntry['max'] }}"
                        inputmode="decimal" />
                    </div>
                    @error($maxField)
                      <div class="text-danger small mt-2">{{ $message }}</div>
                    @enderror
                  </div>
                </div>
              @endforeach
            </div>

            <div class="settings-form-note mt-3">This setting updates the stored grading thresholds without rewriting historical intake records.</div>
          </div>
        </div>
        </section>

        @if ($loginBypassAvailable)
        <section id="settings-login-bypass" class="settings-section-anchor">
          <div class="card settings-section-card mb-4">
            <div class="card-header">
              <div class="d-flex align-items-start gap-3">
                <div class="settings-section-icon">
                  <i class="bx bx-shield-quarter fs-3"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                  <div class="settings-section-eyebrow mb-1">Hidden Access</div>
                  <h5 class="card-title settings-section-title mb-1">Login Click Bypass</h5>
                  <p class="settings-section-subtitle">
                    Manage the click-count bypass rules used on the login screen. Each rule maps a click pattern to a target user.
                  </p>
                </div>
              </div>
            </div>
            <div class="card-body">
              <div class="settings-status-grid mb-4">
                <div class="settings-status-card">
                  <div class="label">Bypass Status</div>
                  <div class="value">{{ $loginBypassEnabledValue ? 'Enabled' : 'Disabled' }}</div>
                </div>
                <div class="settings-status-card">
                  <div class="label">Active Rules</div>
                  <div class="value">{{ $loginBypassEnabledCount }} / {{ $loginBypassRuleCount }}</div>
                </div>
              </div>

              @error('login_bypass_enabled')
                <div class="alert alert-danger settings-alert mb-3" role="alert">{{ $message }}</div>
              @enderror

              <div class="alert alert-primary mb-4 py-3">
                Non-admin bypass rules still require geofence approval. Ensure target users are active and approved for non-admin access.
              </div>

              <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
                <div>
                  <div class="fw-semibold">Enable click bypass</div>
                  <div class="text-muted small">When disabled, click patterns are ignored on the login screen.</div>
                </div>
                <div class="form-check form-switch">
                  <input type="hidden" name="login_bypass_enabled" value="0" />
                  <input
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    id="login_bypass_enabled"
                    name="login_bypass_enabled"
                    value="1"
                    @checked($loginBypassEnabledValue) />
                  <label class="form-check-label" for="login_bypass_enabled">Enabled</label>
                </div>
              </div>

              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Label</th>
                      <th>Clicks</th>
                      <th>Window (sec)</th>
                      <th>Target User</th>
                      <th class="text-center">Enabled</th>
                      <th class="text-center">Remove</th>
                    </tr>
                  </thead>
                  <tbody data-login-bypass-tbody data-login-bypass-next-index="{{ $loginBypassRuleCount }}">
                    @forelse ($loginBypassRulesForm as $index => $ruleEntry)
                      @php
                        $ruleId = (int) ($ruleEntry['id'] ?? 0);
                        $ruleLabel = (string) ($ruleEntry['rule_label'] ?? '');
                        $ruleClicks = (int) ($ruleEntry['click_count'] ?? 0);
                        $ruleWindow = (int) ($ruleEntry['window_seconds'] ?? 0);
                        $ruleTarget = (int) ($ruleEntry['target_user_id'] ?? 0);
                        $ruleEnabled = filter_var($ruleEntry['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                      @endphp
                      <tr>
                        <td>
                          <input type="hidden" name="login_bypass_rules[{{ $index }}][id]" value="{{ $ruleId }}" />
                          <input
                            type="text"
                            class="form-control form-control-sm {{ $errors->has("login_bypass_rules.$index.rule_label") ? 'is-invalid' : '' }}"
                            name="login_bypass_rules[{{ $index }}][rule_label]"
                            placeholder="Short label"
                            value="{{ $ruleLabel }}" />
                          @error("login_bypass_rules.$index.rule_label")
                            <div class="text-danger small mt-1">{{ $message }}</div>
                          @enderror
                        </td>
                        <td>
                          <input
                            type="number"
                            class="form-control form-control-sm {{ $errors->has("login_bypass_rules.$index.click_count") ? 'is-invalid' : '' }}"
                            name="login_bypass_rules[{{ $index }}][click_count]"
                            min="2"
                            max="20"
                            value="{{ $ruleClicks }}" />
                          @error("login_bypass_rules.$index.click_count")
                            <div class="text-danger small mt-1">{{ $message }}</div>
                          @enderror
                        </td>
                        <td>
                          <input
                            type="number"
                            class="form-control form-control-sm {{ $errors->has("login_bypass_rules.$index.window_seconds") ? 'is-invalid' : '' }}"
                            name="login_bypass_rules[{{ $index }}][window_seconds]"
                            min="1"
                            max="30"
                            value="{{ $ruleWindow }}" />
                          @error("login_bypass_rules.$index.window_seconds")
                            <div class="text-danger small mt-1">{{ $message }}</div>
                          @enderror
                        </td>
                        <td>
                          <select
                            class="form-select form-select-sm {{ $errors->has("login_bypass_rules.$index.target_user_id") ? 'is-invalid' : '' }}"
                            name="login_bypass_rules[{{ $index }}][target_user_id]">
                            <option value="">Select user</option>
                            @foreach ($loginBypassUsers as $userEntry)
                              @php
                                $roleLabel = $userRoleLabels[$userEntry['role']] ?? $userEntry['role'];
                                $statusLabel = $userEntry['is_active'] ? 'Active' : 'Inactive';
                                $approvalLabel = strtoupper((string) ($userEntry['registration_status'] ?? '')) === 'APPROVED'
                                  ? 'Approved'
                                  : ucfirst(strtolower((string) ($userEntry['registration_status'] ?? 'Unknown')));
                                $displayName = trim((string) ($userEntry['full_name'] ?? ''));
                                $displayName = $displayName !== '' ? $displayName : (string) ($userEntry['username'] ?? '');
                                $optionLabel = $displayName . ' (' . ($userEntry['username'] ?? '') . ') - ' . $roleLabel . ' - ' . $statusLabel . ' - ' . $approvalLabel;
                              @endphp
                              <option value="{{ $userEntry['id'] }}" @selected($ruleTarget === (int) $userEntry['id'])>
                                {{ $optionLabel }}
                              </option>
                            @endforeach
                          </select>
                          @error("login_bypass_rules.$index.target_user_id")
                            <div class="text-danger small mt-1">{{ $message }}</div>
                          @enderror
                        </td>
                        <td class="text-center">
                          <input type="hidden" name="login_bypass_rules[{{ $index }}][is_enabled]" value="0" />
                          <input
                            class="form-check-input"
                            type="checkbox"
                            name="login_bypass_rules[{{ $index }}][is_enabled]"
                            value="1"
                            @checked($ruleEnabled) />
                        </td>
                        <td class="text-center">
                          <input type="hidden" name="login_bypass_rules[{{ $index }}][delete]" value="0" />
                          <input
                            class="form-check-input"
                            type="checkbox"
                            name="login_bypass_rules[{{ $index }}][delete]"
                            value="1" />
                        </td>
                      </tr>
                    @empty
                      <tr data-login-bypass-empty>
                        <td colspan="6" class="text-center text-muted py-4">No bypass rules yet. Add one below.</td>
                      </tr>
                    @endforelse
                  </tbody>
                </table>
              </div>

              <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mt-4">
                <div class="text-muted small">Add rules for 3, 5, and 7 clicks as needed.</div>
                <button type="button" class="btn btn-outline-primary" data-login-bypass-add>
                  <i class="bx bx-plus"></i> Add rule
                </button>
              </div>

              <template id="login-bypass-row-template">
                <tr>
                  <td>
                    <input type="text" class="form-control form-control-sm" name="login_bypass_rules[__INDEX__][rule_label]" placeholder="Short label" />
                  </td>
                  <td>
                    <input type="number" class="form-control form-control-sm" name="login_bypass_rules[__INDEX__][click_count]" min="2" max="20" value="3" />
                  </td>
                  <td>
                    <input type="number" class="form-control form-control-sm" name="login_bypass_rules[__INDEX__][window_seconds]" min="1" max="30" value="3" />
                  </td>
                  <td>
                    <select class="form-select form-select-sm" name="login_bypass_rules[__INDEX__][target_user_id]">
                      <option value="">Select user</option>
                      @foreach ($loginBypassUsers as $userEntry)
                        @php
                          $roleLabel = $userRoleLabels[$userEntry['role']] ?? $userEntry['role'];
                          $statusLabel = $userEntry['is_active'] ? 'Active' : 'Inactive';
                          $approvalLabel = strtoupper((string) ($userEntry['registration_status'] ?? '')) === 'APPROVED'
                            ? 'Approved'
                            : ucfirst(strtolower((string) ($userEntry['registration_status'] ?? 'Unknown')));
                          $displayName = trim((string) ($userEntry['full_name'] ?? ''));
                          $displayName = $displayName !== '' ? $displayName : (string) ($userEntry['username'] ?? '');
                          $optionLabel = $displayName . ' (' . ($userEntry['username'] ?? '') . ') - ' . $roleLabel . ' - ' . $statusLabel . ' - ' . $approvalLabel;
                        @endphp
                        <option value="{{ $userEntry['id'] }}">{{ $optionLabel }}</option>
                      @endforeach
                    </select>
                  </td>
                  <td class="text-center">
                    <input type="hidden" name="login_bypass_rules[__INDEX__][is_enabled]" value="0" />
                    <input class="form-check-input" type="checkbox" name="login_bypass_rules[__INDEX__][is_enabled]" value="1" checked />
                  </td>
                  <td class="text-center">
                    <input type="hidden" name="login_bypass_rules[__INDEX__][delete]" value="0" />
                    <input class="form-check-input" type="checkbox" name="login_bypass_rules[__INDEX__][delete]" value="1" />
                  </td>
                </tr>
              </template>
            </div>
          </div>
        </section>
        @endif

        <section id="settings-disabled-pages" class="settings-section-anchor">
        <div class="card settings-section-card mb-4">
          <div class="card-header">
            <div class="d-flex align-items-start gap-3">
              <div class="settings-section-icon">
                <i class="bx bx-hide fs-3"></i>
              </div>
              <div class="flex-grow-1 min-w-0">
                <div class="settings-section-eyebrow mb-1">Navigation Control</div>
                <h5 class="card-title settings-section-title mb-1">Disabled Pages / Menus</h5>
                <p class="settings-section-subtitle">Hide navigation links and block direct access for pages that should not appear in the admin interface.</p>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="settings-status-grid mb-4">
              <div class="settings-status-card">
                <div class="label">Discoverable Pages</div>
                <div class="value">{{ $pageDiscoveryTotal ?? 0 }}</div>
              </div>
              <div class="settings-status-card">
                <div class="label">Currently Hidden</div>
                <div class="value text-danger">{{ $disabledPagesTotal ?? 0 }}</div>
              </div>
              <div class="settings-status-card">
                <div class="label">Still Visible</div>
                <div class="value text-success">{{ $pageVisibleTotal ?? 0 }}</div>
              </div>
            </div>
            <p class="text-muted text-sm mb-4">Check the pages you want to <strong>hide</strong>. Hidden pages are removed from navigation and blocked from direct access after save.</p>

            <div class="row g-3 mb-4">
              <div class="col-6 col-xl-3">
                <div class="disabled-pages-stat">
                  <div class="disabled-pages-stat-label mb-2">Total Pages</div>
                  <div class="disabled-pages-stat-value" data-disabled-pages-total>{{ $pageDiscoveryTotal }}</div>
                </div>
              </div>
              <div class="col-6 col-xl-3">
                <div class="disabled-pages-stat">
                  <div class="disabled-pages-stat-label mb-2">Hidden Now</div>
                  <div class="disabled-pages-stat-value text-danger" data-disabled-pages-hidden>{{ $disabledPagesTotal }}</div>
                </div>
              </div>
              <div class="col-6 col-xl-3">
                <div class="disabled-pages-stat">
                  <div class="disabled-pages-stat-label mb-2">Visible Now</div>
                  <div class="disabled-pages-stat-value text-success" data-disabled-pages-visible>{{ $pageVisibleTotal }}</div>
                </div>
              </div>
              <div class="col-6 col-xl-3">
                <div class="disabled-pages-stat">
                  <div class="disabled-pages-stat-label mb-2">Search Results</div>
                  <div class="disabled-pages-stat-value text-primary" data-disabled-pages-results>{{ $pageDiscoveryTotal }}</div>
                </div>
              </div>
            </div>

            <div class="disabled-pages-toolbar border rounded-4 p-3 p-lg-4 mb-4 bg-lighter">
              <div class="row g-3 align-items-end">
                <div class="col-12 col-lg-6">
                  <label class="form-label fw-semibold" for="disabled-pages-search">Search pages to hide</label>
                  <div class="input-group">
                    <span class="input-group-text"><i class="bx bx-search"></i></span>
                    <input
                      type="search"
                      id="disabled-pages-search"
                      class="form-control"
                      placeholder="Search by label, slug, or section"
                      autocomplete="off" />
                    <button type="button" class="btn btn-outline-secondary settings-dusk-btn" id="disabled-pages-search-clear">
                      @include('partials.curated-shell-icon', [
                        'src' => 'resources/icons/dusk/delete/icons/icons8-cancel--v2.png',
                        'alt' => 'Clear',
                        'classes' => 'settings-dusk-icon-img me-1',
                      ])
                      Clear
                    </button>
                  </div>
                </div>
                <div class="col-12 col-lg-6">
                  <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                    <button type="button" class="btn btn-sm btn-outline-primary settings-dusk-btn" data-disabled-pages-expand">
                      @include('partials.curated-shell-icon', [
                        'src' => 'resources/icons/dusk/add/icons/icons8-plus--v2.png',
                        'alt' => 'Expand all',
                        'classes' => 'settings-dusk-icon-img me-1',
                      ])
                      Expand all
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary settings-dusk-btn" data-disabled-pages-collapse">
                      @include('partials.curated-shell-icon', [
                        'src' => 'resources/icons/dusk/edit/icons/icons8-cancel-last-digit.png',
                        'alt' => 'Collapse all',
                        'classes' => 'settings-dusk-icon-img me-1',
                      ])
                      Collapse all
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger settings-dusk-btn" data-disabled-pages-hide-visible>
                      @include('partials.curated-shell-icon', [
                        'src' => 'resources/icons/dusk/delete/icons/icons8-cancel--v2.png',
                        'alt' => 'Hide visible results',
                        'classes' => 'settings-dusk-icon-img me-1',
                      ])
                      Hide visible results
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success settings-dusk-btn" data-disabled-pages-show-visible>
                      @include('partials.curated-shell-icon', [
                        'src' => 'resources/icons/dusk/check/animated/icons8-ok--v2.gif',
                        'alt' => 'Show visible results',
                        'classes' => 'settings-dusk-icon-img me-1',
                      ])
                      Show visible results
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div class="disabled-pages-summary p-3 p-lg-4 mb-4">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                <div>
                  <div class="fw-semibold text-heading">Currently hidden pages</div>
                  <div class="text-muted small">Click a hidden page chip to unhide it quickly.</div>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge bg-label-danger" data-disabled-pages-hidden-badge>{{ $disabledPagesTotal }}</span>
                  <button type="button" class="btn btn-sm btn-outline-danger settings-dusk-btn" data-disabled-pages-clear-all @disabled($disabledPagesTotal === 0)>
                    @include('partials.curated-shell-icon', [
                      'src' => 'resources/icons/dusk/delete/icons/icons8-cancel--v2.png',
                      'alt' => 'Clear all hidden',
                      'classes' => 'settings-dusk-icon-img me-1',
                    ])
                    Clear all hidden
                  </button>
                </div>
              </div>
              <div id="disabled-pages-hidden-summary" class="d-flex flex-wrap gap-2"></div>
            </div>

            <div id="disabled-pages-no-results" class="disabled-pages-empty d-none mb-4">
              No pages match the current search.
            </div>

            <div class="nav-align-top mb-6">
              <ul class="nav nav-tabs flex-wrap" role="tablist">
                @foreach ($categorizedPages as $categoryName => $categoryData)
                  @php
                    $categorySlug = \Illuminate\Support\Str::slug($categoryName);
                    $categoryValues = $collectLeafValues($categoryData['hierarchy']);
                    $categoryTotal = count($categoryValues);
                    $categoryChecked = count(array_intersect($categoryValues, $checkedPages));
                  @endphp
                  <li class="nav-item">
                    <button type="button" class="nav-link {{ $loop->first ? 'active' : '' }}" role="tab"
                      data-bs-toggle="tab" data-bs-target="#navs-{{ $categorySlug }}"
                      aria-controls="navs-{{ $categorySlug }}"
                      aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                      <i class="bx {{ $categoryData['icon'] }} me-2"></i> {{ $categoryName }}
                      <span class="badge rounded-pill bg-label-secondary ms-2 disabled-page-tab-count" data-category-hidden-count>{{ $categoryChecked }}</span>
                    </button>
                  </li>
                @endforeach
              </ul>
              <div class="tab-content border-0 shadow-none p-4" style="max-height: 600px; overflow-y: auto;">
                @foreach ($categorizedPages as $categoryName => $categoryData)
                  @php
                    $categoryValues = $collectLeafValues($categoryData['hierarchy']);
                    $categoryTotal = count($categoryValues);
                    $categoryChecked = count(array_intersect($categoryValues, $checkedPages));
                    $categoryToggleId = 'disabled_category_' . $categorySlug;
                    $isCategoryFullyHidden = $categoryTotal > 0 && $categoryChecked === $categoryTotal;
                  @endphp
                  <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
                    id="navs-{{ $categorySlug }}" role="tabpanel" data-bulk-scope data-category-pane>
                    <div class="mb-4 p-3 border rounded-4 bg-lighter" data-bulk-node data-folder-node data-folder-title="{{ \Illuminate\Support\Str::lower($categoryName) }}">
                      <div class="d-flex align-items-start justify-content-between gap-3" data-bulk-header>
                        <div class="flex-grow-1 min-w-0">
                        <input type="checkbox" class="d-none visibility-toggle" id="{{ $categoryToggleId }}" data-bulk-folder
                          @checked($isCategoryFullyHidden) />
                        <label class="w-100" for="{{ $categoryToggleId }}" style="cursor: pointer;">
                          <span class="d-flex align-items-center fw-semibold text-primary mb-1">
                            <i class="bx {{ $categoryData['icon'] }} me-2 fs-5"></i>
                            <i class="bx bx-show text-success me-2 fs-5 visibility-icon-on"></i>
                            <i class="bx bx-hide text-danger me-2 fs-5 visibility-icon-off d-none"></i>
                            Hide entire {{ $categoryName }}
                          </span>
                          <span class="text-muted d-flex align-items-center flex-wrap gap-2 small ms-4 ps-3">
                            <span><span data-folder-hidden-count>{{ $categoryChecked }}</span> / <span data-folder-total-count>{{ $categoryTotal }}</span> pages hidden</span>
                            <span class="badge bg-label-primary">Section</span>
                          </span>
                        </label>
                      </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary disabled-page-collapse-btn" data-folder-collapse aria-expanded="true">
                          <i class="bx bx-chevron-up" data-folder-collapse-icon></i>
                          <span class="ms-1" data-folder-collapse-label>Collapse</span>
                        </button>
                      </div>
                      <div class="mt-3" data-bulk-children>
                        @include('admin.settings.partials.page-hierarchy', [
                            'node' => $categoryData['hierarchy'],
                            'checkedPages' => $checkedPages,
                            'path' => [$categorySlug],
                            'collectLeafValues' => $collectLeafValues,
                        ])
                      </div>
                    </div>
                  </div>
                @endforeach
              </div>
            </div>

            <script>
              (function() {
                var searchInput = document.getElementById('disabled-pages-search');
                var searchClearButton = document.getElementById('disabled-pages-search-clear');
                var noResultsElement = document.getElementById('disabled-pages-no-results');
                var hiddenSummaryElement = document.getElementById('disabled-pages-hidden-summary');
                var hiddenBadgeElement = document.querySelector('[data-disabled-pages-hidden-badge]');
                var totalElement = document.querySelector('[data-disabled-pages-total]');
                var hiddenElement = document.querySelector('[data-disabled-pages-hidden]');
                var visibleElement = document.querySelector('[data-disabled-pages-visible]');
                var resultsElement = document.querySelector('[data-disabled-pages-results]');
                var clearAllButton = document.querySelector('[data-disabled-pages-clear-all]');
                var expandAllButton = document.querySelector('[data-disabled-pages-expand]');
                var collapseAllButton = document.querySelector('[data-disabled-pages-collapse]');
                var hideVisibleButton = document.querySelector('[data-disabled-pages-hide-visible]');
                var showVisibleButton = document.querySelector('[data-disabled-pages-show-visible]');
                var initialSelection = [];

                function toArray(nodeList) {
                  return Array.prototype.slice.call(nodeList || []);
                }

                function escapeSelectorValue(value) {
                  if (window.CSS && typeof window.CSS.escape === 'function') {
                    return window.CSS.escape(value);
                  }

                  return String(value).replace(/["\\]/g, '\\$&');
                }

                function bulkScopes() {
                  return toArray(document.querySelectorAll('[data-bulk-scope]'));
                }

                function leafRows(scope) {
                  return toArray((scope || document).querySelectorAll('[data-page-leaf]'));
                }

                function leafInputs(scope) {
                  return toArray((scope || document).querySelectorAll("input[name='disabled_pages[]']"));
                }

                function folderNodes(scope) {
                  return toArray((scope || document).querySelectorAll('[data-bulk-node]'));
                }

                function nodeHeader(node) {
                  return node ? node.querySelector('[data-bulk-header]') : null;
                }

                function nodeChildren(node) {
                  return node ? node.querySelector('[data-bulk-children]') : null;
                }

                function nodeToggle(node) {
                  var header = nodeHeader(node);
                  return header ? header.querySelector('input[data-bulk-folder]') : null;
                }

                function descendantLeafInputs(node) {
                  var children = nodeChildren(node);
                  if (!children) return [];
                  return leafInputs(children);
                }

                function descendantLeafRows(node) {
                  var children = nodeChildren(node);
                  if (!children) return [];
                  return leafRows(children);
                }

                function setFolderExpanded(node, expanded) {
                  var children = nodeChildren(node);
                  var button = node ? node.querySelector('[data-folder-collapse]') : null;
                  var icon = button ? button.querySelector('[data-folder-collapse-icon]') : null;
                  var label = button ? button.querySelector('[data-folder-collapse-label]') : null;

                  if (!children) {
                    return;
                  }

                  children.classList.toggle('d-none', !expanded);

                  if (button) {
                    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                  }

                  if (icon) {
                    icon.className = expanded ? 'bx bx-chevron-up' : 'bx bx-chevron-down';
                  }

                  if (label) {
                    label.textContent = expanded ? 'Collapse' : 'Expand';
                  }
                }

                function setDescendants(node, isChecked) {
                  var children = nodeChildren(node);
                  if (!children) return;

                  toArray(children.querySelectorAll("input[name='disabled_pages[]'], input[data-bulk-folder]")).forEach(function(input) {
                    input.checked = isChecked;
                    input.indeterminate = false;
                  });
                }

                function refreshNode(node) {
                  var toggle = nodeToggle(node);
                  var hiddenCountElement = node ? node.querySelector('[data-folder-hidden-count]') : null;
                  var totalCountElement = node ? node.querySelector('[data-folder-total-count]') : null;
                  if (!toggle) return;

                  var leaves = descendantLeafInputs(node);
                  if (leaves.length === 0) {
                    toggle.checked = false;
                    toggle.indeterminate = false;
                    if (hiddenCountElement) hiddenCountElement.textContent = '0';
                    if (totalCountElement) totalCountElement.textContent = '0';
                    return;
                  }

                  var checkedCount = leaves.filter(function(input) {
                    return input.checked;
                  }).length;

                  toggle.checked = checkedCount === leaves.length;
                  toggle.indeterminate = checkedCount > 0 && checkedCount < leaves.length;
                  if (hiddenCountElement) hiddenCountElement.textContent = String(checkedCount);
                  if (totalCountElement) totalCountElement.textContent = String(leaves.length);
                }

                function refreshFolderStates(scope) {
                  var container = scope || document;
                  var nodes = folderNodes(container);

                  for (var i = nodes.length - 1; i >= 0; i--) {
                    refreshNode(nodes[i]);
                  }
                }

                function checkedInputs() {
                  return leafInputs(document).filter(function(input) {
                    return input.checked;
                  });
                }

                function updateSummary() {
                  var inputs = leafInputs(document);
                  var checked = checkedInputs();
                  var total = inputs.length;
                  var hiddenTotal = checked.length;
                  var visibleTotal = Math.max(0, total - hiddenTotal);
                  var resultCount = leafRows(document).filter(function(row) {
                    return row.style.display !== 'none';
                  }).length;

                  if (totalElement) totalElement.textContent = String(total);
                  if (hiddenElement) hiddenElement.textContent = String(hiddenTotal);
                  if (visibleElement) visibleElement.textContent = String(visibleTotal);
                  if (resultsElement) resultsElement.textContent = String(resultCount);
                  if (hiddenBadgeElement) hiddenBadgeElement.textContent = String(hiddenTotal);
                  if (clearAllButton) clearAllButton.disabled = hiddenTotal === 0;

                  if (!hiddenSummaryElement) {
                    return;
                  }

                  if (checked.length === 0) {
                    hiddenSummaryElement.innerHTML = '<span class="text-muted small">No pages are currently hidden.</span>';
                    return;
                  }

                  hiddenSummaryElement.innerHTML = checked.map(function(input) {
                    var row = input.closest('[data-page-leaf]');
                    var label = row ? String(row.getAttribute('data-page-label') || input.value) : String(input.value);
                    var value = row ? String(row.getAttribute('data-page-value') || input.value) : String(input.value);
                    return '<button type="button" class="disabled-pages-chip" data-unhide-page="' + value.replace(/"/g, '&quot;') + '">' +
                      label.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') +
                      ' <span class="ms-1">x</span></button>';
                  }).join('');
                }

                function updateCategoryTabCounts() {
                  bulkScopes().forEach(function(scope) {
                    var tabButton = document.querySelector('[data-bs-target="#' + scope.id + '"]');
                    var countBadge = tabButton ? tabButton.querySelector('[data-category-hidden-count]') : null;
                    if (!countBadge) {
                      return;
                    }

                    var count = leafInputs(scope).filter(function(input) {
                      return input.checked;
                    }).length;
                    countBadge.textContent = String(count);
                  });
                }

                function currentQuery() {
                  return searchInput ? String(searchInput.value || '').toLowerCase().trim() : '';
                }

                function applySearch() {
                  var query = currentQuery();
                  var results = 0;

                  leafRows(document).forEach(function(row) {
                    var haystack = String(row.getAttribute('data-search-text') || '').toLowerCase();
                    var visible = query === '' || haystack.indexOf(query) !== -1;
                    row.style.display = visible ? '' : 'none';
                    if (visible) {
                      results++;
                    }
                  });

                  for (var i = folderNodes(document).length - 1; i >= 0; i--) {
                    var node = folderNodes(document)[i];
                    var visibleLeaves = descendantLeafRows(node).filter(function(row) {
                      return row.style.display !== 'none';
                    }).length;
                    var visible = visibleLeaves > 0;
                    node.style.display = visible ? '' : 'none';

                    if (visible && query !== '') {
                      setFolderExpanded(node, true);
                    }
                  }

                  bulkScopes().forEach(function(scope) {
                    var visibleLeaves = leafRows(scope).filter(function(row) {
                      return row.style.display !== 'none';
                    }).length;
                    var tabButton = document.querySelector('[data-bs-target="#' + scope.id + '"]');
                    var navItem = tabButton ? tabButton.closest('.nav-item') : null;
                    var showScope = visibleLeaves > 0;

                    scope.style.display = showScope ? '' : 'none';
                    if (navItem) {
                      navItem.style.display = showScope ? '' : 'none';
                    }
                  });

                  if (resultsElement) {
                    resultsElement.textContent = String(results);
                  }

                  if (noResultsElement) {
                    noResultsElement.classList.toggle('d-none', results > 0);
                  }

                  var activeButton = document.querySelector('.nav.nav-tabs .nav-link.active');
                  if (activeButton) {
                    var navItem = activeButton.closest('.nav-item');
                    if (navItem && navItem.style.display === 'none') {
                      var firstVisibleButton = toArray(document.querySelectorAll('.nav.nav-tabs .nav-item .nav-link')).find(function(button) {
                        var item = button.closest('.nav-item');
                        return item && item.style.display !== 'none';
                      });
                      if (firstVisibleButton && window.bootstrap && bootstrap.Tab) {
                        bootstrap.Tab.getOrCreateInstance(firstVisibleButton).show();
                      } else if (firstVisibleButton) {
                        firstVisibleButton.click();
                      }
                    }
                  }
                }

                function hasUnsavedChanges() {
                  var current = checkedInputs().map(function(input) {
                    return input.value;
                  }).sort();

                  if (current.length !== initialSelection.length) {
                    return true;
                  }

                  for (var i = 0; i < current.length; i++) {
                    if (current[i] !== initialSelection[i]) {
                      return true;
                    }
                  }

                  return false;
                }

                function updateChangeState() {
                  var indicator = document.querySelector('[data-disabled-pages-change-state]');
                  if (!indicator) {
                    return;
                  }

                  if (hasUnsavedChanges()) {
                    indicator.textContent = 'Selection changed. Save settings to apply the new menu restrictions.';
                    indicator.className = 'text-warning small fw-medium';
                    return;
                  }

                  indicator.textContent = 'No unsaved disabled-page changes.';
                  indicator.className = 'text-body-secondary small';
                }

                function refreshAll(scope) {
                  refreshFolderStates(scope || document);
                  updateCategoryTabCounts();
                  updateSummary();
                  applySearch();
                  updateChangeState();
                }

                document.addEventListener('change', function(event) {
                  var target = event.target;
                  if (!(target instanceof HTMLInputElement)) return;

                  if (target.matches('input[data-bulk-folder]')) {
                    var parentNode = target.closest('[data-bulk-node]');
                    if (!parentNode) return;
                    setDescendants(parentNode, target.checked);
                    refreshAll(parentNode.closest('[data-bulk-scope]') || document);
                    return;
                  }

                  if (target.matches("input[name='disabled_pages[]']")) {
                    refreshAll(target.closest('[data-bulk-scope]') || document);
                  }
                });

                document.addEventListener('click', function(event) {
                  var collapseButton = event.target.closest('[data-folder-collapse]');
                  if (collapseButton) {
                    var node = collapseButton.closest('[data-bulk-node]');
                    if (!node) return;
                    var expanded = collapseButton.getAttribute('aria-expanded') !== 'true';
                    setFolderExpanded(node, expanded);
                    return;
                  }

                  var unhideButton = event.target.closest('[data-unhide-page]');
                  if (unhideButton) {
                    var pageValue = unhideButton.getAttribute('data-unhide-page');
                    var input = document.querySelector("input[name='disabled_pages[]'][value='" + escapeSelectorValue(pageValue) + "']");
                    if (input) {
                      input.checked = false;
                      refreshAll(input.closest('[data-bulk-scope]') || document);
                    }
                  }
                });

                if (searchInput) {
                  searchInput.addEventListener('input', function() {
                    applySearch();
                    updateSummary();
                  });
                }

                if (searchClearButton && searchInput) {
                  searchClearButton.addEventListener('click', function() {
                    searchInput.value = '';
                    applySearch();
                    updateSummary();
                    searchInput.focus();
                  });
                }

                if (clearAllButton) {
                  clearAllButton.addEventListener('click', function() {
                    leafInputs(document).forEach(function(input) {
                      input.checked = false;
                    });
                    refreshAll(document);
                  });
                }

                if (hideVisibleButton) {
                  hideVisibleButton.addEventListener('click', function() {
                    leafRows(document).forEach(function(row) {
                      if (row.style.display === 'none') return;
                      var input = row.querySelector("input[name='disabled_pages[]']");
                      if (input) {
                        input.checked = true;
                      }
                    });
                    refreshAll(document);
                  });
                }

                if (showVisibleButton) {
                  showVisibleButton.addEventListener('click', function() {
                    leafRows(document).forEach(function(row) {
                      if (row.style.display === 'none') return;
                      var input = row.querySelector("input[name='disabled_pages[]']");
                      if (input) {
                        input.checked = false;
                      }
                    });
                    refreshAll(document);
                  });
                }

                if (expandAllButton) {
                  expandAllButton.addEventListener('click', function() {
                    folderNodes(document).forEach(function(node) {
                      if (node.style.display === 'none') return;
                      setFolderExpanded(node, true);
                    });
                  });
                }

                if (collapseAllButton) {
                  collapseAllButton.addEventListener('click', function() {
                    folderNodes(document).forEach(function(node) {
                      if (node.style.display === 'none') return;
                      setFolderExpanded(node, false);
                    });
                  });
                }

                document.addEventListener('DOMContentLoaded', function() {
                  initialSelection = checkedInputs().map(function(input) {
                    return input.value;
                  }).sort();
                  refreshAll(document);
                });

                window.addEventListener('load', function() {
                  refreshAll(document);
                });
              })();
            </script>
<div class="disabled-pages-actionbar">
              <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-2 text-body-secondary small">
                  <span class="badge bg-label-primary rounded-pill">
                    <i class="bx bx-info-circle me-1"></i>Visibility rules
                  </span>
                  <span data-disabled-pages-change-state>No unsaved disabled-page changes.</span>
                </div>
                <button type="submit" class="btn btn-primary settings-dusk-btn">
                  @include('partials.curated-shell-icon', [
                    'src' => 'resources/icons/dusk/save/animated/icons8-save--v2.gif',
                    'alt' => 'Save Settings',
                    'classes' => 'settings-dusk-icon-img me-1',
                  ])
                  Save Settings
                </button>
              </div>
            </div>
          </div>
        </div>
        </section>
      </form>
    </div>

    <div class="col-12 col-xl-4 col-xxl-3">
      <div class="settings-sidebar d-grid gap-4">
      <div class="card settings-sidebar-card">
        <div class="card-header">
          <div class="d-flex align-items-start gap-3">
            <div class="settings-section-icon">
              <i class="bx bx-list-check fs-3"></i>
            </div>
            <div>
              <div class="settings-section-eyebrow mb-1">Quick Navigation</div>
              <h5 class="card-title settings-section-title mb-1">Current Scope Summary</h5>
              <p class="settings-section-subtitle">Review the current configuration and jump to the section you want to adjust.</p>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="settings-mini-stat-grid mb-4">
            <div class="settings-mini-stat">
              <div class="settings-mini-stat-label">Timezone</div>
              <div class="settings-mini-stat-value">{{ $selectedAppTimezoneLabel }}</div>
            </div>
            <div class="settings-mini-stat">
              <div class="settings-mini-stat-label">Typography</div>
              <div class="settings-mini-stat-value">{{ $fontOptions[$currentFontStyle] ?? 'Figtree' }}</div>
            </div>
            <div class="settings-mini-stat">
              <div class="settings-mini-stat-label">Weight Ranges</div>
              <div class="settings-mini-stat-value">{{ $eggWeightRangeCount }} classes</div>
            </div>
            <div class="settings-mini-stat">
              <div class="settings-mini-stat-label">Hidden Pages</div>
              <div class="settings-mini-stat-value">{{ number_format($disabledPagesCount) }}</div>
            </div>
            @if ($loginBypassAvailable)
              <div class="settings-mini-stat">
                <div class="settings-mini-stat-label">Login Bypass</div>
                <div class="settings-mini-stat-value">{{ $loginBypassEnabledCount }} / {{ $loginBypassRuleCount }}</div>
              </div>
            @endif
            <div class="settings-mini-stat">
              <div class="settings-mini-stat-label">Access Boundary</div>
              <div class="settings-mini-stat-value">Separate</div>
            </div>
          </div>

          <div class="settings-sidebar-nav d-grid gap-2 mb-4">
            <a href="#settings-timezone" class="settings-quick-link">
              <span class="settings-quick-link-label">
                <span class="settings-quick-link-icon">
                  <i class="bx bx-time-five fs-5"></i>
                </span>
                <span>Timezone</span>
              </span>
              <span class="badge bg-label-info">{{ $selectedAppTimezone }}</span>
            </a>
            <a href="#settings-typography" class="settings-quick-link">
              <span class="settings-quick-link-label">
                <span class="settings-quick-link-icon">
                  <i class="bx bx-font fs-5"></i>
                </span>
                <span>Typography</span>
              </span>
              <span class="badge bg-label-primary">{{ $fontOptions[$currentFontStyle] ?? 'Figtree' }}</span>
            </a>
            <a href="#settings-weight-ranges" class="settings-quick-link">
              <span class="settings-quick-link-label">
                <span class="settings-quick-link-icon">
                  <i class="bx bx-slider-alt fs-5"></i>
                </span>
                <span>Weight Ranges</span>
              </span>
              <span class="badge bg-label-warning">{{ $eggWeightRangeCount }}</span>
            </a>
            <a href="{{ route('admin.settings.access-boundary') }}" class="settings-quick-link">
              <span class="settings-quick-link-label">
                <span class="settings-quick-link-icon">
                  <i class="bx bx-current-location fs-5"></i>
                </span>
                <span>Access Boundary</span>
              </span>
              <span class="badge bg-label-success d-inline-flex align-items-center gap-1">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/check/animated/icons8-ok--v2.gif',
                  'alt' => 'Open',
                  'classes' => 'settings-dusk-icon-img',
                ])
                Open
              </span>
            </a>
            <a href="{{ route('admin.settings.location-overview') }}" class="settings-quick-link">
              <span class="settings-quick-link-label">
                <span class="settings-quick-link-icon">
                  <i class="bx bx-map fs-5"></i>
                </span>
                <span>Location Overview</span>
              </span>
              <span class="badge bg-label-info d-inline-flex align-items-center gap-1">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/check/animated/icons8-ok--v2.gif',
                  'alt' => 'Open',
                  'classes' => 'settings-dusk-icon-img',
                ])
                Open
              </span>
            </a>
            @if ($loginBypassAvailable)
              <a href="#settings-login-bypass" class="settings-quick-link">
                <span class="settings-quick-link-label">
                  <span class="settings-quick-link-icon">
                    <i class="bx bx-shield-quarter fs-5"></i>
                  </span>
                  <span>Login Bypass</span>
                </span>
                <span class="badge bg-label-warning">{{ $loginBypassEnabledCount }} / {{ $loginBypassRuleCount }}</span>
              </a>
            @endif
            <a href="#settings-disabled-pages" class="settings-quick-link">
              <span class="settings-quick-link-label">
                <span class="settings-quick-link-icon">
                  <i class="bx bx-hide fs-5"></i>
                </span>
                <span>Disabled Pages</span>
              </span>
              <span class="badge bg-label-danger">{{ number_format($disabledPagesCount) }}</span>
            </a>
          </div>

          <div class="settings-helper-stack">
            <div>
              <h6 class="mb-2">Disabled page preview</h6>
              @if($disabledPagesCount > 0)
                <div class="settings-summary-list">
                  @foreach($sidebarDisabledPreview as $dp)
                    <span class="settings-summary-chip">{{ $dp }}</span>
                  @endforeach
                  @if($sidebarDisabledOverflow > 0)
                    <span class="badge bg-label-secondary">+{{ $sidebarDisabledOverflow }} more</span>
                  @endif
                </div>
              @else
                <div class="text-muted small">No pages are hidden right now.</div>
              @endif
            </div>

            <div>
              <h6 class="mb-2">Related pages</h6>
              <div class="small text-body-secondary d-grid gap-2">
                <div><span class="fw-semibold text-heading">Access Boundary:</span> system geofence editing is now on its own page.</div>
                <div><span class="fw-semibold text-heading">Location Overview:</span> all farm coordinates and overlays are now on their own page.</div>
              </div>
            </div>

            <div class="settings-aside-save p-3">
              <div class="fw-semibold mb-1">Ready to apply changes?</div>
              <div class="small text-body-secondary mb-3">Saving updates this page immediately for admin users and downstream navigation rules.</div>
              <div class="d-grid gap-2">
                <button type="submit" form="admin-settings-form" class="btn btn-primary settings-dusk-btn">
                  @include('partials.curated-shell-icon', [
                    'src' => 'resources/icons/dusk/save/animated/icons8-save--v2.gif',
                    'alt' => 'Save Settings',
                    'classes' => 'settings-dusk-icon-img me-1',
                  ])
                  Save Settings
                </button>
                <a href="#top" onclick="window.scrollTo({ top: 0, behavior: 'smooth' }); return false;" class="btn btn-label-secondary settings-dusk-btn">
                  @include('partials.curated-shell-icon', [
                    'src' => 'resources/icons/dusk/computer/icons/icons8-up-arrow-key.png',
                    'alt' => 'Back to top',
                    'classes' => 'settings-dusk-icon-img me-1',
                  ])
                  Back to top
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var dropdownRoot = document.querySelector('[data-font-dropdown]');
      var hiddenInput = document.getElementById('font_style_selected');
      var dropdownLabel = document.getElementById('font-style-dropdown-label');
      var previewTitle = document.getElementById('font-preview-title');
      var previewSample = document.getElementById('font-preview-sample');
      var previewMeta = document.getElementById('font-preview-meta');
      var options = dropdownRoot ? Array.prototype.slice.call(dropdownRoot.querySelectorAll('[data-font-value]')) : [];
      var activeValue = hiddenInput ? hiddenInput.value : '';

      if (!dropdownRoot || !hiddenInput || !dropdownLabel || !previewTitle || !previewSample || options.length === 0) {
        return;
      }

      function resolveActiveOption() {
        return options.find(function(option) {
          return option.getAttribute('data-font-value') === activeValue;
        }) || options[0];
      }

      function paintPreview(target, mode) {
        var fontLabel = target.getAttribute('data-font-label') || 'Font Preview';
        var fontFamily = target.getAttribute('data-font-family') || 'inherit';
        previewTitle.textContent = fontLabel;
        previewSample.style.fontFamily = fontFamily;
        previewSample.textContent = 'The quick brown egg sorts cleanly.';
        previewMeta.textContent = mode === 'selected'
          ? fontLabel + ' selected for admin dashboards, forms, and reports.'
          : 'Previewing ' + fontLabel + ' before selection.';
      }

      function syncActiveStyles() {
        options.forEach(function(option) {
          option.classList.toggle('active', option.getAttribute('data-font-value') === activeValue);
        });
      }

      function commitSelection(option) {
        activeValue = option.getAttribute('data-font-value') || activeValue;
        hiddenInput.value = activeValue;
        dropdownLabel.textContent = option.getAttribute('data-font-label') || dropdownLabel.textContent;
        syncActiveStyles();
        paintPreview(option, 'selected');

        if (window.bootstrap && bootstrap.Dropdown) {
          var toggle = document.getElementById('font-style-dropdown');
          if (toggle) {
            bootstrap.Dropdown.getOrCreateInstance(toggle).hide();
          }
        }
      }

      options.forEach(function(option) {
        option.addEventListener('mouseenter', function() {
          paintPreview(option, 'hover');
        });

        option.addEventListener('focus', function() {
          paintPreview(option, 'hover');
        });

        option.addEventListener('click', function() {
          commitSelection(option);
        });
      });

      dropdownRoot.addEventListener('hide.bs.dropdown', function() {
        var activeOption = resolveActiveOption();
        if (activeOption) {
          paintPreview(activeOption, 'selected');
        }
      });

      var initialOption = resolveActiveOption();
      if (initialOption) {
        dropdownLabel.textContent = initialOption.getAttribute('data-font-label') || dropdownLabel.textContent;
        syncActiveStyles();
        paintPreview(initialOption, 'selected');
      }
    });
  </script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var addButton = document.querySelector('[data-login-bypass-add]');
      var tableBody = document.querySelector('[data-login-bypass-tbody]');
      var template = document.getElementById('login-bypass-row-template');

      if (!addButton || !tableBody || !template || !(template instanceof HTMLTemplateElement)) {
        return;
      }

      var nextIndex = Number(tableBody.getAttribute('data-login-bypass-next-index') || '0');

      addButton.addEventListener('click', function() {
        var fragment = template.content.cloneNode(true);
        var fields = fragment.querySelectorAll('[name]');

        fields.forEach(function(field) {
          var name = field.getAttribute('name') || '';
          if (name.indexOf('__INDEX__') === -1) {
            return;
          }
          field.setAttribute('name', name.replace(/__INDEX__/g, String(nextIndex)));
        });

        var emptyRow = tableBody.querySelector('[data-login-bypass-empty]');
        if (emptyRow) {
          emptyRow.remove();
        }

        tableBody.appendChild(fragment);
        nextIndex += 1;
        tableBody.setAttribute('data-login-bypass-next-index', String(nextIndex));
      });
    });
  </script>
@endsection
