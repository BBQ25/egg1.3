@php
  $appBasePath = trim((string) config('app.base_path', ''), '/');
  $appBaseUrlPath = $appBasePath === '' ? '' : '/' . $appBasePath;
  $sneatBase = $appBaseUrlPath . '/sneat';
  $sneatAssetsBase = $sneatBase . '/assets';
  $sneatFontsBase = $sneatBase . '/fonts';
  $brandLogoUrl = $sneatAssetsBase . '/img/logo.png?v=20260220';
  $resolveInlineMimeType = static function (string $absolutePath): string {
      if (function_exists('mime_content_type')) {
          $detectedMimeType = @mime_content_type($absolutePath);

          if (is_string($detectedMimeType) && $detectedMimeType !== '') {
              return $detectedMimeType;
          }
      }

      return match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
          'gif' => 'image/gif',
          'jpg', 'jpeg' => 'image/jpeg',
          'svg' => 'image/svg+xml',
          'webp' => 'image/webp',
          default => 'image/png',
      };
  };
  $duskIconResource = static function (string $relativePath) use ($resolveInlineMimeType): string {
      $absolutePath = resource_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));

      if (!is_file($absolutePath) || !is_readable($absolutePath)) {
          return '';
      }

      $mimeType = $resolveInlineMimeType($absolutePath);

      return 'data:' . $mimeType . ';base64,' . base64_encode(file_get_contents($absolutePath));
  };
  $duskUiIcons = [
      'add' => $duskIconResource('icons/dusk/add/icons/icons8-plus--v2.png'),
      'clear' => $duskIconResource('icons/dusk/delete/icons/icons8-cancel--v2.png'),
      'check' => $duskIconResource('icons/dusk/check/animated/icons8-ok--v2.gif'),
      'edit' => $duskIconResource('icons/dusk/edit/icons/icons8-edit-user-male.png'),
      'guide' => $duskIconResource('icons/dusk/location/animated/icons8-compass--v2.gif'),
      'key' => $duskIconResource('icons/dusk/key/icons/icons8-key-security.png'),
      'location' => $duskIconResource('icons/dusk/address/icons/icons8-place-marker--v3.png'),
      'logout' => $duskIconResource('icons/dusk/thumbs-up/icons/icons8-logout-rounded-up.png'),
      'power' => $duskIconResource('icons/dusk/computer/icons/icons8-power-off-button.png'),
      'refresh' => $duskIconResource('icons/dusk/circle/animated/icons8-refresh--v2.gif'),
      'save' => $duskIconResource('icons/dusk/save/animated/icons8-save--v2.gif'),
      'search' => $duskIconResource('icons/dusk/find/icons/icons8-search--v2.png'),
      'settings' => $duskIconResource('icons/dusk/gear/icons/icons8-settings.png'),
      'shape' => $duskIconResource('icons/dusk/editing/icons/icons8-starburst-shape.png'),
      'up' => $duskIconResource('icons/dusk/computer/icons/icons8-up-arrow-key.png'),
  ];
@endphp

<!doctype html>
<html lang="en" class="layout-navbar-fixed layout-menu-fixed layout-compact" dir="ltr" data-skin="default"
  data-assets-path="{{ $sneatAssetsBase }}/" data-template="vertical-menu-template" data-bs-theme="light">

<head>
  <meta charset="utf-8" />
  <meta name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <base href="{{ $appBaseUrlPath === '' ? '/' : $appBaseUrlPath . '/' }}" />

  <title>@yield('title', 'APEWSD Admin')</title>

  <link rel="icon" type="image/png" href="{{ $brandLogoUrl }}" />
  <link rel="shortcut icon" href="{{ $brandLogoUrl }}" />

  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/fonts/iconify-icons.css" />
  <link rel="stylesheet" href="{{ $appBaseUrlPath }}/vendor/fontawesome/css/all.min.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/libs/pickr/pickr-themes.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/css/core.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/css/demo.css" />
  @include('partials.font-head')
  @include('partials.pwa-head')
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/css/brand.css" />
  @stack('styles')
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  @include('partials.responsive-shell-styles')
  <style>
    .app-shell-icon {
      display: inline-block;
      width: 1.25rem;
      height: 1.25rem;
      object-fit: contain;
      object-position: center;
      vertical-align: middle;
      flex-shrink: 0;
    }

    .app-shell-icon--admin-menu {
      width: 1.3rem;
      height: 1.3rem;
      margin: 0;
      position: relative;
      z-index: 1;
      filter: drop-shadow(0 4px 10px rgba(105, 108, 255, 0.18));
      transition: transform 180ms ease, filter 180ms ease;
      animation: appShellIconFloat 3.6s ease-in-out infinite;
    }

    .app-shell-sidebar-dusk {
      width: 2.15rem;
      height: 2.15rem;
      margin-right: 0.75rem;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      position: relative;
      flex-shrink: 0;
      background: #fff;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9), 0 8px 18px rgba(103, 114, 229, 0.12);
      overflow: visible;
      transition: transform 180ms ease, box-shadow 180ms ease, background 180ms ease;
    }

    .app-shell-sidebar-dusk::before,
    .app-shell-sidebar-dusk::after {
      content: "";
      position: absolute;
      inset: -0.16rem;
      border-radius: inherit;
      pointer-events: none;
    }

    .app-shell-sidebar-dusk::before {
      inset: 0;
      border: 1px solid rgba(160, 172, 199, 0.2);
      background: transparent;
      opacity: 1;
      animation: appShellDuskPulse 3.2s ease-in-out infinite;
    }

    .app-shell-sidebar-dusk::after {
      inset: -0.35rem;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.5), transparent 68%);
      opacity: 0.42;
      animation: appShellDuskHalo 3.2s ease-in-out infinite;
    }

    .menu-link:hover .app-shell-sidebar-dusk,
    .menu-item.active .app-shell-sidebar-dusk {
      transform: translateY(-1px) scale(1.03);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.95), 0 12px 26px rgba(103, 114, 229, 0.18);
      background: #fff;
    }

    .menu-link:hover .app-shell-icon--admin-menu,
    .menu-item.active .app-shell-icon--admin-menu {
      transform: scale(1.08);
      filter: drop-shadow(0 6px 14px rgba(105, 108, 255, 0.28));
    }

    .app-shell-icon--admin-toggle {
      width: 1rem;
      height: 1rem;
      margin: 0;
    }

    .app-shell-toggle-bars {
      width: 1rem;
      height: 0.875rem;
      display: inline-flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .app-shell-toggle-bars span,
    .app-shell-chevron-left::before,
    .app-shell-chevron-left::after {
      display: block;
      background: currentColor;
      border-radius: 999px;
      content: "";
    }

    .app-shell-toggle-bars span {
      height: 2px;
      width: 100%;
    }

    .app-shell-chevron-left {
      width: 0.875rem;
      height: 0.875rem;
      position: relative;
      display: inline-block;
    }

    .app-shell-chevron-left::before,
    .app-shell-chevron-left::after {
      width: 0.6rem;
      height: 2px;
      position: absolute;
      left: 0.1rem;
      top: 0.22rem;
      transform-origin: left center;
    }

    .app-shell-chevron-left::before {
      transform: rotate(-45deg);
    }

    .app-shell-chevron-left::after {
      top: 0.58rem;
      transform: rotate(45deg);
    }

    .app-dusk-inline {
      width: 1.9rem;
      height: 1.9rem;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      position: relative;
      flex-shrink: 0;
      background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.97), rgba(227, 234, 255, 0.93) 54%, rgba(194, 207, 255, 0.84) 100%);
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.72), 0 8px 18px rgba(103, 114, 229, 0.14);
      overflow: visible;
      transition: transform 180ms ease, box-shadow 180ms ease;
      animation: appShellIconFloat 3.4s ease-in-out infinite;
    }

    .app-dusk-inline::before,
    .app-dusk-inline::after {
      content: "";
      position: absolute;
      inset: -0.14rem;
      border-radius: inherit;
      pointer-events: none;
    }

    .app-dusk-inline::before {
      background: linear-gradient(135deg, rgba(105, 108, 255, 0.34), rgba(105, 108, 255, 0.02) 58%, rgba(113, 221, 55, 0.2));
      animation: appShellDuskPulse 3s ease-in-out infinite;
    }

    .app-dusk-inline::after {
      inset: -0.32rem;
      background: radial-gradient(circle, rgba(105, 108, 255, 0.15), transparent 70%);
      animation: appShellDuskHalo 3s ease-in-out infinite;
    }

    .app-dusk-inline img {
      width: 1rem;
      height: 1rem;
      object-fit: contain;
      position: relative;
      z-index: 1;
      filter: drop-shadow(0 4px 10px rgba(105, 108, 255, 0.16));
    }

    .btn .app-dusk-inline,
    .nav-link .app-dusk-inline,
    .settings-quick-link .app-dusk-inline,
    .input-group-text .app-dusk-inline,
    .badge .app-dusk-inline,
    .avatar .app-dusk-inline {
      margin-right: 0.45rem;
    }

    .btn.btn-sm .app-dusk-inline,
    .badge .app-dusk-inline,
    .input-group-text .app-dusk-inline {
      width: 1.55rem;
      height: 1.55rem;
    }

    .btn.btn-sm .app-dusk-inline img,
    .badge .app-dusk-inline img,
    .input-group-text .app-dusk-inline img {
      width: 0.9rem;
      height: 0.9rem;
    }

    .avatar > .app-dusk-inline,
    .settings-overview-icon > .app-dusk-inline,
    .settings-overview-stat-icon > .app-dusk-inline,
    .settings-section-icon > .app-dusk-inline,
    .settings-quick-link-icon > .app-dusk-inline,
    .farm-map-stat-icon > .app-dusk-inline {
      margin-right: 0;
    }

    .avatar > .app-dusk-inline {
      width: 2rem;
      height: 2rem;
    }

    .avatar > .app-dusk-inline img {
      width: 1.05rem;
      height: 1.05rem;
    }

    .app-dusk-inline + .app-shell-icon {
      margin-left: 0.45rem;
    }

    @keyframes appShellIconFloat {
      0%,
      100% {
        transform: translateY(0);
      }

      50% {
        transform: translateY(-1px);
      }
    }

    @keyframes appShellDuskPulse {
      0%,
      100% {
        opacity: 0.72;
        transform: scale(0.96);
      }

      50% {
        opacity: 1;
        transform: scale(1.03);
      }
    }

    @keyframes appShellDuskHalo {
      0%,
      100% {
        opacity: 0.38;
        transform: scale(0.96);
      }

      50% {
        opacity: 0.72;
        transform: scale(1.08);
      }
    }
  </style>

  <script src="{{ $sneatAssetsBase }}/vendor/js/helpers.js"></script>
  <script src="{{ $sneatAssetsBase }}/js/config.js"></script>
</head>

<body>
  <div class="layout-wrapper layout-content-navbar">
    <div class="layout-container">
      @include('partials.sidebar')

      <div class="layout-page">
        <nav
          class="layout-navbar container-xxl navbar-detached navbar navbar-expand-xl align-items-center bg-navbar-theme"
          id="layout-navbar">
          <div class="navbar-nav-right d-flex align-items-center w-100">
            <div class="d-xl-none">
              <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large" aria-label="Toggle sidebar">
                <span class="app-shell-toggle-bars" aria-hidden="true">
                  <span></span>
                  <span></span>
                  <span></span>
                </span>
              </a>
            </div>
            <div class="d-flex align-items-center gap-3 ms-auto">
              <span class="text-body-secondary">{{ auth()->user()?->full_name }}
                ({{ auth()->user()?->role?->label() }})</span>
              <form action="{{ route('logout') }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-sm btn-primary">Logout</button>
              </form>
            </div>
          </div>
        </nav>

        <div class="content-wrapper">
          <div class="container-xxl flex-grow-1 container-p-y">
            @yield('content')
          </div>
        </div>
      </div>
    </div>
    <div class="layout-overlay layout-menu-toggle"></div>
    <div class="drag-target"></div>
  </div>

  <script src="{{ $sneatAssetsBase }}/vendor/libs/jquery/jquery.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/popper/popper.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/js/bootstrap.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/@algolia/autocomplete-js.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/pickr/pickr.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/hammer/hammer.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/i18n/i18n.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/js/menu.js"></script>
  <script src="{{ $sneatAssetsBase }}/js/main.js"></script>
  <script>
    (function() {
      var duskIcons = @json($duskUiIcons);

      function createDuskIcon(key) {
        var src = duskIcons[key] || '';
        if (!src) {
          return null;
        }

        var wrapper = document.createElement('span');
        wrapper.className = 'app-dusk-inline';
        wrapper.setAttribute('aria-hidden', 'true');

        var image = document.createElement('img');
        image.src = src;
        image.alt = '';
        wrapper.appendChild(image);

        return wrapper;
      }

      function keyFromText(text) {
        var normalized = String(text || '').toLowerCase();

        if (/(logout|sign out|log out)/.test(normalized)) return 'logout';
        if (/(save|generate|download|submit)/.test(normalized)) return 'save';
        if (/(search|filter|find)/.test(normalized)) return 'search';
        if (/(add|create|new|register)/.test(normalized)) return 'add';
        if (/(edit|update|modify)/.test(normalized)) return 'edit';
        if (/(clear|cancel|close|delete|remove|deny|hide)/.test(normalized)) return 'clear';
        if (/(approve|reactivate|show|open|enable|active|ok|apply|next|focus)/.test(normalized)) return 'check';
        if (/(key|password|credential|api)/.test(normalized)) return 'key';
        if (/(map|location|boundary|geofence|farm|center)/.test(normalized)) return 'location';
        if (/(guide|help|info|tour|overview)/.test(normalized)) return 'guide';
        if (/(settings|configuration|configure)/.test(normalized)) return 'settings';
        if (/(refresh|rotate|reload|repeat)/.test(normalized)) return 'refresh';
        if (/(power|deactivate|inactive|disable)/.test(normalized)) return 'power';
        if (/(shape|polygon|square|rectangle|circle)/.test(normalized)) return 'shape';
        if (/(back to top|previous|back|up)/.test(normalized)) return 'up';

        return '';
      }

      function keyFromBoxIcon(className) {
        var normalized = String(className || '');

        if (/bx-(save|download|rocket)/.test(normalized)) return 'save';
        if (/bx-(search|world)/.test(normalized)) return 'search';
        if (/bx-plus/.test(normalized)) return 'add';
        if (/bx-edit/.test(normalized)) return 'edit';
        if (/bx-(trash|eraser|hide)/.test(normalized)) return 'clear';
        if (/bx-(check|show|check-circle|check-shield)/.test(normalized)) return 'check';
        if (/bx-key/.test(normalized)) return 'key';
        if (/bx-(map|map-alt|map-pin|current-location)/.test(normalized)) return 'location';
        if (/bx-(compass|info|help-circle)/.test(normalized)) return 'guide';
        if (/bx-cog/.test(normalized)) return 'settings';
        if (/bx-refresh/.test(normalized)) return 'refresh';
        if (/bx-power/.test(normalized)) return 'power';
        if (/bx-up-arrow-alt/.test(normalized)) return 'up';

        return '';
      }

      function shouldSkip(element) {
        return !element
          || element.querySelector('.app-dusk-inline')
          || element.closest('.app-shell-sidebar-dusk')
          || element.closest('.menu-inner')
          || element.closest('.app-dusk-optout')
          || element.querySelector('img.app-shell-icon');
      }

      function prependDuskIcon(element, key) {
        if (!element || !key || shouldSkip(element)) {
          return;
        }

        var icon = createDuskIcon(key);
        if (!icon) {
          return;
        }

        var firstBoxIcon = element.querySelector('i.bx, i.icon-base.bx');
        if (firstBoxIcon) {
          firstBoxIcon.style.display = 'none';
          firstBoxIcon.setAttribute('aria-hidden', 'true');
        }

        element.insertBefore(icon, element.firstChild);
      }

      function enhanceTargets() {
        document.querySelectorAll('.btn, .settings-quick-link, .input-group-text, .badge, .avatar, .settings-overview-icon, .settings-overview-stat-icon, .settings-section-icon, .settings-quick-link-icon, .farm-map-stat-icon, .farm-map-section-kicker, .nms-card-meta, .nms-latency-icon').forEach(function(element) {
          if (shouldSkip(element)) {
            return;
          }

          var key = '';
          var existingBoxIcon = element.querySelector('i.bx, i.icon-base.bx');
          if (existingBoxIcon) {
            key = keyFromBoxIcon(existingBoxIcon.className);
          }

          if (!key) {
            key = keyFromText(element.textContent || '');
          }

          prependDuskIcon(element, key);
        });
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhanceTargets);
      } else {
        enhanceTargets();
      }
    })();
  </script>
  <script>
    (function() {
      if (window.Helpers && typeof window.Helpers.toggleCollapsed === 'function') {
        return;
      }

      document.addEventListener('click', function(event) {
        var toggle = event.target.closest('.layout-menu-toggle');
        if (!toggle) {
          return;
        }

        event.preventDefault();
        document.documentElement.classList.toggle('layout-menu-collapsed');
      });
    })();
  </script>

  {!! \App\Support\MenuVisibility::inlineScript() !!}
  {!! \App\Support\RolePageAccess::inlineScript(auth()->user()) !!}
  @stack('scripts')
</body>

</html>
