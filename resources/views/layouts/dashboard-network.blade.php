@php
  $appBasePath = trim((string) config('app.base_path', ''), '/');
  $appBaseUrlPath = $appBasePath === '' ? '' : '/' . $appBasePath;
  $sneatBase = $appBaseUrlPath . '/sneat';
  $sneatAssetsBase = $sneatBase . '/assets';
  $sneatFontsBase = $sneatBase . '/fonts';
  $brandLogoUrl = $sneatAssetsBase . '/img/logo.png?v=20260220';
@endphp

<!doctype html>
<html lang="en" data-bs-theme="light">

<head>
  <meta charset="utf-8" />
  <meta name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <base href="{{ $appBaseUrlPath === '' ? '/' : $appBaseUrlPath . '/' }}" />

  <title>@yield('title', 'PoultryPulse Dashboard')</title>

  <link rel="icon" type="image/png" href="{{ $brandLogoUrl }}" />
  <link rel="shortcut icon" href="{{ $brandLogoUrl }}" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/fonts/iconify-icons.css" />
  <link rel="stylesheet" href="{{ $appBaseUrlPath }}/vendor/fontawesome/css/all.min.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/css/core.css" />
  @include('partials.font-head')
  @include('partials.pwa-head')
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/css/brand.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/libs/apex-charts/apex-charts.css" />
  @include('partials.responsive-shell-styles')
  @vite(['resources/css/dashboard-network.css', 'resources/js/dashboard-network.js'])
</head>

<body class="nms-body-root">
  <div class="nms-shell" id="dashboardApp" data-dashboard-data-url="{{ route('dashboard.data') }}"
    data-dashboard-view-url="{{ route('dashboard') }}">
    <header class="nms-header">
      <div class="nms-header__brand">
        <div class="nms-brand-icon">
          @include('partials.curated-shell-icon', [
            'src' => 'resources/icons/dusk/internet/animated/icons8-wifi--v2.gif',
            'alt' => 'Live network status',
            'classes' => 'app-shell-icon--brand',
          ])
        </div>
        <h1>PoultryPulse</h1>
      </div>

      <div class="nms-header__center">
        <button type="button" id="dashboardContextToggle" class="nms-context-pill" aria-expanded="false"
          aria-controls="dashboardContextPanel">
          @include('partials.curated-shell-icon', [
            'src' => 'resources/icons/dusk/animals/icons/icons8-chicken.png',
            'alt' => 'Poultry farm context',
            'classes' => 'app-shell-icon--context',
          ])
          <span id="dashboardContextLabel">Office UDM Portland</span>
          <span class="nms-pill-chevron" aria-hidden="true"></span>
        </button>

        <div id="dashboardContextPanel" class="nms-context-panel" hidden>
          <label for="dashboardFarmSwitcher" class="nms-context-label">Farm</label>
          <select id="dashboardFarmSwitcher" class="form-select form-select-sm" data-context-field="farm"
            aria-label="Farm context">
            <option value="">All Farms</option>
            @foreach (($dashboardPayload['context']['switcher']['farms'] ?? []) as $farmOption)
              <option value="{{ $farmOption['id'] }}"
                @selected(($dashboardPayload['context']['selected']['farm_id'] ?? null) === $farmOption['id'])>
                {{ $farmOption['name'] }}
              </option>
            @endforeach
          </select>

          <label for="dashboardDeviceSwitcher" class="nms-context-label">Device</label>
          <select id="dashboardDeviceSwitcher" class="form-select form-select-sm" data-context-field="device"
            aria-label="Device context">
            <option value="">All Devices</option>
            @foreach (($dashboardPayload['context']['switcher']['devices'] ?? []) as $deviceOption)
              <option value="{{ $deviceOption['id'] }}"
                @selected(($dashboardPayload['context']['selected']['device_id'] ?? null) === $deviceOption['id'])
                data-farm-id="{{ $deviceOption['farm_id'] ?? '' }}">
                {{ $deviceOption['name'] }}
              </option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="nms-header__actions">
        <button type="button" id="dashboardAppsButton" class="nms-icon-btn" title="Apps" aria-label="Apps">
          @include('partials.curated-shell-icon', [
            'src' => 'assets/icons/curated/ui-flat/devices.png',
            'alt' => 'Apps',
            'classes' => 'app-shell-icon--button',
          ])
        </button>

        <button type="button" id="dashboardUserButton" class="nms-avatar-btn" title="User menu" aria-label="User menu"
          aria-expanded="false" aria-controls="dashboardUserMenu">
          <span>{{ strtoupper(substr((string) auth()->user()?->full_name, 0, 1)) }}</span>
        </button>

        <div id="dashboardUserMenu" class="nms-user-menu" hidden>
          <div class="nms-user-menu__name">{{ auth()->user()?->full_name }}</div>
          <div class="nms-user-menu__role">{{ auth()->user()?->role?->label() }}</div>
          <form action="{{ route('logout') }}" method="POST" class="mt-2">
            @csrf
            <button type="submit" class="btn btn-sm btn-primary w-100">Logout</button>
          </form>
        </div>

        <button type="button" id="dashboardThemeToggle" class="nms-icon-btn" title="Toggle theme"
          aria-label="Toggle theme">
          @include('partials.curated-shell-icon', [
            'src' => 'assets/icons/curated/ui-flat/settings.png',
            'alt' => 'Theme settings',
            'classes' => 'app-shell-icon--button',
          ])
        </button>
      </div>
    </header>

    <div class="nms-body">
      <nav class="nms-sidebar" aria-label="Dashboard navigation">
        @php $dividerPrinted = false; @endphp
        @foreach (($dashboardShellNav ?? []) as $navItem)
          @if (($navItem['key'] ?? '') === 'notifications' && !$dividerPrinted)
            <div class="nms-sidebar-divider" aria-hidden="true"></div>
            @php $dividerPrinted = true; @endphp
          @endif

          @if (!$navItem['disabled'] && $navItem['url'])
            <a href="{{ $navItem['url'] }}"
              class="nms-sidebar-link @if (!empty($navItem['active'])) is-active @endif"
              title="{{ $navItem['label'] }}" aria-label="{{ $navItem['label'] }}">
              @include('partials.curated-shell-icon', [
                'src' => $navItem['icon_path'] ?? '',
                'alt' => $navItem['label'] ?? '',
                'classes' => 'app-shell-icon--sidebar',
              ])
            </a>
          @else
            <button type="button" class="nms-sidebar-link is-disabled" title="{{ $navItem['label'] }}"
              aria-label="{{ $navItem['label'] }}" disabled>
              @include('partials.curated-shell-icon', [
                'src' => $navItem['icon_path'] ?? '',
                'alt' => $navItem['label'] ?? '',
                'classes' => 'app-shell-icon--sidebar',
              ])
            </button>
          @endif
        @endforeach
      </nav>

      <main class="nms-main">
        @yield('content')
      </main>
    </div>
  </div>

  <script src="{{ $sneatAssetsBase }}/vendor/libs/apex-charts/apexcharts.js"></script>
  {!! \App\Support\MenuVisibility::inlineScript() !!}
  {!! \App\Support\RolePageAccess::inlineScript(auth()->user()) !!}
</body>

</html>
