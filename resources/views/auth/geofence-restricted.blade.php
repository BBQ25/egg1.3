@php
    $appBasePath = trim((string) config('app.base_path', ''), '/');
    $appBaseUrlPath = $appBasePath === '' ? '' : '/' . $appBasePath;
    $sneatBase = $appBaseUrlPath . '/sneat';
    $sneatAssetsBase = $sneatBase . '/assets';
    $sneatFontsBase = $sneatBase . '/fonts';
    $brandLogoUrl = $sneatAssetsBase . '/img/logo.png?v=20260220';

    $payload = [
        'map' => $geofenceMap ?? [],
        'attempted' => [
            'latitude' => $attemptedLatitude ?? null,
            'longitude' => $attemptedLongitude ?? null,
        ],
    ];
@endphp

<!doctype html>
<html
  lang="en"
  class="layout-wide customizer-hide"
  dir="ltr"
  data-skin="default"
  data-assets-path="{{ $sneatAssetsBase }}/"
  data-template="vertical-menu-template"
  data-bs-theme="light">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />

    <title>Geofence Access Restricted</title>

    <link rel="icon" type="image/png" href="{{ $brandLogoUrl }}" />
    <link rel="shortcut icon" href="{{ $brandLogoUrl }}" />

    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/fonts/iconify-icons.css" />
    <link rel="stylesheet" href="{{ $appBaseUrlPath }}/vendor/fontawesome/css/all.min.css" />
    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/css/core.css" />
    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/css/demo.css" />
    @include('partials.font-head')
    @include('partials.pwa-head')
    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/css/brand.css" />
    @include('partials.responsive-shell-styles')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
      .geofence-page {
        min-height: 100vh;
        background: linear-gradient(165deg, #f4f7ff 0%, #edf2ff 50%, #ffffff 100%);
      }

      .geofence-card {
        max-width: 1000px;
        margin: 3rem auto;
      }

      #restricted-geofence-map {
        width: 100%;
        min-height: 360px;
        border-radius: 0.75rem;
        border: 1px solid rgba(67, 89, 113, 0.12);
      }

      .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.35rem 0.75rem;
        border-radius: 999px;
        font-size: 0.85rem;
        font-weight: 600;
        background: #ffe8e8;
        color: #d52f2f;
      }
    </style>
  </head>
  <body>
    <div class="geofence-page p-4">
      <div class="card geofence-card shadow-sm">
        <div class="card-body p-4 p-md-5">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div>
              <div class="status-pill mb-2">
                <i class="bx bx-map-pin"></i>
                Geofence Restricted
              </div>
              <h3 class="mb-2">System Access Unavailable Outside Geofence</h3>
              <p class="text-body-secondary mb-0">
                The system is not accessible outside the configured geofence perimeter.
              </p>
            </div>
            <a href="{{ route('login') }}" class="btn btn-primary">Back to Login</a>
          </div>

          <div class="row g-4 mb-4">
            <div class="col-12 col-md-6">
              <div class="border rounded p-3 h-100">
                <div class="text-body-secondary small mb-1">Configured Geofence</div>
                @if (($geofenceMap['configured'] ?? false) && !empty($geofenceMap['geometry']))
                  <div class="fw-semibold">
                    Shape: {{ ucfirst(strtolower((string) ($geofenceMap['geometry']['shape_type'] ?? 'Unknown'))) }}
                  </div>
                @else
                  <div class="fw-semibold text-warning">No geofence geometry configured.</div>
                @endif
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="border rounded p-3 h-100">
                <div class="text-body-secondary small mb-1">Detected Access Location</div>
                @if (isset($attemptedLatitude, $attemptedLongitude))
                  <div class="fw-semibold">{{ $attemptedLatitude }}, {{ $attemptedLongitude }}</div>
                @else
                  <div class="fw-semibold text-body-secondary">Location unavailable</div>
                @endif
              </div>
            </div>
          </div>

          <div id="restricted-geofence-map" role="img" aria-label="Configured geofence map"></div>
        </div>
      </div>
    </div>

    <script id="geofence_restricted_payload" type="application/json">{!! json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
      (function() {
        const mapEl = document.getElementById('restricted-geofence-map');
        const payloadEl = document.getElementById('geofence_restricted_payload');

        if (!mapEl || !payloadEl || typeof L === 'undefined') {
          return;
        }

        let payload = {};
        try {
          payload = JSON.parse(payloadEl.textContent || '{}');
        } catch (error) {
          payload = {};
        }

        const mapData = payload.map || {};
        const geometry = mapData.geometry || null;
        const mapCenter = mapData.map_center || mapData.default_center || {
          latitude: 10.354727,
          longitude: 124.965980
        };

        const map = L.map(mapEl, {
          zoomControl: true
        }).setView([Number(mapCenter.latitude), Number(mapCenter.longitude)], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        const geofenceStyle = {
          color: '#ff4d4f',
          weight: 2,
          fillColor: '#ff4d4f',
          fillOpacity: 0.18
        };

        let geofenceLayer = null;
        if (geometry && geometry.shape_type === 'CIRCLE') {
          geofenceLayer = L.circle(
            [Number(geometry.center_latitude), Number(geometry.center_longitude)],
            {
              ...geofenceStyle,
              radius: Number(geometry.radius_meters || 0)
            }
          ).addTo(map);
        } else if (geometry && (geometry.shape_type === 'RECTANGLE' || geometry.shape_type === 'SQUARE') && geometry.bounds) {
          geofenceLayer = L.rectangle(
            [
              [Number(geometry.bounds.south), Number(geometry.bounds.west)],
              [Number(geometry.bounds.north), Number(geometry.bounds.east)]
            ],
            geofenceStyle
          ).addTo(map);
        } else if (geometry && geometry.shape_type === 'POLYGON' && Array.isArray(geometry.vertices)) {
          geofenceLayer = L.polygon(
            geometry.vertices.map(function(point) {
              return [Number(point[0]), Number(point[1])];
            }),
            geofenceStyle
          ).addTo(map);
        }

        const attempted = payload.attempted || {};
        const attemptedLat = attempted.latitude;
        const attemptedLng = attempted.longitude;

        if (!Number.isNaN(Number(attemptedLat)) && !Number.isNaN(Number(attemptedLng)) && attemptedLat !== null && attemptedLng !== null) {
          L.marker([Number(attemptedLat), Number(attemptedLng)], {
            title: 'Detected location'
          }).addTo(map).bindPopup('Detected access location').openPopup();
        }

        if (geofenceLayer) {
          map.fitBounds(geofenceLayer.getBounds(), { padding: [20, 20] });
        }
      })();
    </script>
  </body>
</html>
