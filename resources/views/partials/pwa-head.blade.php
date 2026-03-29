@php
  $pwaBasePath = trim((string) config('app.base_path', ''), '/');
  $pwaBaseUrlPath = $pwaBasePath === '' ? '' : '/' . $pwaBasePath;
  $pwaThemeColor = '#0f4c3a';
@endphp

<link rel="manifest" href="{{ $pwaBaseUrlPath }}/manifest.webmanifest" />
<meta name="theme-color" content="{{ $pwaThemeColor }}" />
<meta name="mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="default" />
<meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Egg1.3') }}" />
<link rel="apple-touch-icon" href="{{ $pwaBaseUrlPath }}/icons/icon-192.png" />
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
  @vite('resources/js/pwa-register.js')
@endif
