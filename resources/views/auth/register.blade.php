@php
    $appBasePath = trim((string) config('app.base_path', ''), '/');
    $appBaseUrlPath = $appBasePath === '' ? '' : '/' . $appBasePath;
    $sneatBase = $appBaseUrlPath . '/sneat';
    $sneatAssetsBase = $sneatBase . '/assets';
    $sneatFontsBase = $sneatBase . '/fonts';
    $brandLogoUrl = $sneatAssetsBase . '/img/logo.png?v=20260220';
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

    <title>Sumacot - Register</title>

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
    @include('partials.responsive-shell-styles')
    @include('partials.auth-cover-styles')

    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/css/pages/page-auth.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
      #farm-card {
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
      }

      #farm-card.farm-required {
        border: 1px solid rgba(255, 76, 81, 0.35);
        box-shadow: 0 0.4rem 1.2rem rgba(255, 76, 81, 0.08);
      }

      #farm-card.farm-ready {
        border-color: rgba(40, 199, 111, 0.55);
        box-shadow: 0 0.45rem 1.2rem rgba(40, 199, 111, 0.14);
      }

      .farm-card-header {
        gap: 0.75rem;
      }

      .farm-section-label {
        margin: 0;
        font-size: 0.82rem;
        font-weight: 600;
        color: #8592a3;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      #farm-location-preview {
        background: #f8f9fb;
        border: 1px dashed #d9dee3;
      }

      #farm-registration-map {
        width: 100%;
        height: 260px;
        border: 1px solid #d9dee3;
        border-radius: 0.75rem;
        background: #eef3f8;
        overflow: hidden;
      }

      #farm-registration-map.map-unavailable {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        color: #8592a3;
        font-size: 0.9rem;
      }

      @media (max-width: 767.98px) {
        #farm-registration-map {
          height: 220px;
        }
      }
    </style>

    <script src="{{ $sneatAssetsBase }}/vendor/js/helpers.js"></script>
    <script src="{{ $sneatAssetsBase }}/js/config.js"></script>
  </head>

  <body class="auth-app-shell">
    <div class="authentication-wrapper authentication-cover">
      <a href="{{ route('login') }}" class="app-brand auth-cover-brand gap-2">
        <span class="app-brand-logo demo">
          <img src="{{ $sneatAssetsBase }}/img/logo.png?v=20260226" alt="Site logo" class="app-brand-logo-img" />
        </span>
      </a>

      <div class="authentication-inner row m-0">
        <div class="d-none d-lg-flex col-lg-5 col-xl-5 p-5 auth-cover-visual">
          <div class="auth-cover-visual-stack">
            <div class="auth-cover-copy">
              <span class="badge bg-label-primary mb-3">Account Setup</span>
              <h2>Create a farm-linked account that works well on any device.</h2>
              <p>
                Register owners, staff, or customers with responsive forms that stay usable on phone, tablet, and desktop screens.
              </p>
            </div>

            <div class="auth-cover-illustration">
              <img
                src="{{ $sneatAssetsBase }}/img/illustrations/girl-with-laptop-light.png"
                alt="Register illustration"
                class="auth-cover-image img-fluid scaleX-n1-rtl"
                width="700"
                data-app-light-img="illustrations/girl-with-laptop-light.png"
                data-app-dark-img="illustrations/girl-with-laptop-dark.png" />
            </div>
          </div>
        </div>

        <div class="d-flex col-12 col-lg-7 col-xl-7 align-items-center authentication-bg p-sm-12 p-6 auth-cover-panel">
          <div class="auth-cover-form-shell auth-cover-form-shell--wide mx-auto mt-sm-8 mt-6">
            <h4 class="mb-1">Create an account</h4>
            <p class="mb-6">Registrations require admin approval before sign-in access is granted.</p>

            @if ($errors->any())
              <div class="alert alert-danger mb-6" role="alert">
                {{ $errors->first() }}
              </div>
            @endif

            <form id="registerForm" class="mb-6" action="{{ route('register.store') }}" method="POST">
              @csrf

              <div class="card mb-4">
                <div class="card-header">
                  <h5 class="card-title mb-0">User Profile</h5>
                </div>
                <div class="card-body">
                  <div class="row g-4">
                    <div class="col-12 col-md-4">
                      <label for="first_name" class="form-label">First Name</label>
                      <input type="text" class="form-control" id="first_name" name="first_name" autocomplete="given-name" maxlength="60" value="{{ old('first_name') }}" required />
                    </div>
                    <div class="col-12 col-md-4">
                      <label for="middle_name" class="form-label">Middle Name (Optional)</label>
                      <input type="text" class="form-control" id="middle_name" name="middle_name" autocomplete="additional-name" maxlength="60" value="{{ old('middle_name') }}" />
                    </div>
                    <div class="col-12 col-md-4">
                      <label for="last_name" class="form-label">Last Name</label>
                      <input type="text" class="form-control" id="last_name" name="last_name" autocomplete="family-name" maxlength="60" value="{{ old('last_name') }}" required />
                    </div>
                    <div class="col-12">
                      <label for="address" class="form-label">Address</label>
                      <input type="text" class="form-control" id="address" name="address" autocomplete="street-address" maxlength="255" value="{{ old('address') }}" required />
                    </div>
                    <div class="col-12 col-md-4">
                      <label for="username" class="form-label">Username</label>
                      <input type="text" class="form-control" id="username" name="username" autocomplete="username" maxlength="60" value="{{ old('username') }}" required />
                    </div>
                    <div class="col-12 col-md-4">
                      <label for="role" class="form-label">I am</label>
                      <select id="role" name="role" class="form-select" required>
                        <option value="">Select role</option>
                        @foreach ($roleOptions as $roleValue => $roleLabel)
                          <option value="{{ $roleValue }}" @selected(old('role') === $roleValue)>{{ $roleLabel }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="col-12 col-md-4">
                      <label for="password" class="form-label">Password</label>
                      <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" minlength="8" required />
                    </div>
                    <div class="col-12 col-md-4">
                      <label for="password_confirmation" class="form-label">Confirm Password</label>
                      <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="8" required />
                    </div>
                  </div>
                </div>
              </div>

              <div class="card mb-4" id="farm-card">
                <div class="card-header d-flex justify-content-between align-items-start flex-wrap farm-card-header">
                  <div>
                    <h5 class="card-title mb-1">Farm Registration</h5>
                    <p class="mb-0 text-body-secondary">Link the farm that this account will manage in production and monitoring.</p>
                  </div>
                  <div class="d-flex flex-column align-items-md-end gap-2">
                    <span id="farm-required-badge" class="badge bg-label-danger">Required for Poultry Owner/Staff</span>
                    <span id="farm-completeness-badge" class="badge bg-label-warning">Pending required details</span>
                  </div>
                </div>
                <div class="card-body">
                  <div class="rounded border p-3 mb-4">
                    <div class="d-flex align-items-center gap-2 mb-2">
                      <i class="bx bx-info-circle text-primary"></i>
                      <strong>Farm details checklist</strong>
                    </div>
                    <p class="mb-1">Provide complete farm identity, full address, and accurate coordinates.</p>
                    <p class="mb-0 text-body-secondary">Coordinates must be inside the configured general geofence.</p>
                  </div>
                  <div class="row g-4">
                    <div class="col-12">
                      <p class="farm-section-label">Farm Identity</p>
                    </div>
                    <div class="col-12 col-md-6">
                      <label for="farm_name" class="form-label">Farm Name</label>
                      <input type="text" class="form-control farm-input" id="farm_name" name="farm_name" maxlength="120" value="{{ old('farm_name') }}" />
                    </div>
                    <div class="col-12 col-md-6">
                      <label for="farm_location" class="form-label">Farm Location (Street/Purok)</label>
                      <input type="text" class="form-control farm-input" id="farm_location" name="farm_location" maxlength="160" value="{{ old('farm_location') }}" />
                    </div>
                    <div class="col-12">
                      <p class="farm-section-label">Address Details</p>
                    </div>
                    <div class="col-12 col-md-6">
                      <label for="farm_sitio" class="form-label">Sitio</label>
                      <input type="text" class="form-control farm-input" id="farm_sitio" name="farm_sitio" maxlength="120" value="{{ old('farm_sitio') }}" />
                    </div>
                    <div class="col-12 col-md-6">
                      <label for="farm_barangay" class="form-label">Barangay</label>
                      <input type="text" class="form-control farm-input" id="farm_barangay" name="farm_barangay" maxlength="120" value="{{ old('farm_barangay') }}" />
                    </div>
                    <div class="col-12 col-md-6">
                      <label for="farm_municipality" class="form-label">Municipality/City</label>
                      <input type="text" class="form-control farm-input" id="farm_municipality" name="farm_municipality" maxlength="120" value="{{ old('farm_municipality') }}" />
                    </div>
                    <div class="col-12 col-md-6">
                      <label for="farm_province" class="form-label">Province</label>
                      <input type="text" class="form-control farm-input" id="farm_province" name="farm_province" maxlength="120" value="{{ old('farm_province') }}" />
                    </div>
                    <div class="col-12">
                      <div id="farm-location-preview" class="rounded px-3 py-2 text-body-secondary small">
                        Location preview: Add sitio, barangay, municipality/city, and province.
                      </div>
                    </div>
                    <div class="col-12">
                      <p class="farm-section-label">Coordinates</p>
                    </div>
                    <div class="col-12 col-md-4">
                      <label for="farm_latitude" class="form-label">Farm Latitude</label>
                      <input
                        type="number"
                        step="0.0000001"
                        min="-90"
                        max="90"
                        inputmode="decimal"
                        class="form-control farm-input farm-geo-input"
                        id="farm_latitude"
                        name="farm_latitude"
                        value="{{ old('farm_latitude') }}"
                        placeholder="e.g. 10.3547270" />
                    </div>
                    <div class="col-12 col-md-4">
                      <label for="farm_longitude" class="form-label">Farm Longitude</label>
                      <input
                        type="number"
                        step="0.0000001"
                        min="-180"
                        max="180"
                        inputmode="decimal"
                        class="form-control farm-input farm-geo-input"
                        id="farm_longitude"
                        name="farm_longitude"
                        value="{{ old('farm_longitude') }}"
                        placeholder="e.g. 124.9659800" />
                    </div>
                    <div class="col-12 col-md-4 d-flex align-items-end">
                      <div class="w-100 d-grid gap-2">
                        <button type="button" id="use-current-location" class="btn btn-outline-primary">
                          <i class="bx bx-current-location me-1"></i> Use Current Location
                        </button>
                        <button type="button" id="clear-farm-location" class="btn btn-outline-secondary">
                          <i class="bx bx-eraser me-1"></i> Clear Coordinates
                        </button>
                      </div>
                    </div>
                    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
                      <label for="farm-registration-map" class="form-label mb-0">Map Pinpoint</label>
                      <span id="farm-geo-status" class="badge bg-label-secondary">Coordinates not set</span>
                    </div>
                    <div class="col-12">
                      <div id="farm-registration-map" role="img" aria-label="Farm registration map picker"></div>
                    </div>
                    <div class="col-12">
                      <div id="farm-geolocation-feedback" class="form-text">
                        Tip: click on the map or drag the marker to set exact coordinates.
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <button class="btn btn-primary d-grid w-100 mb-4">Submit Registration</button>
            </form>

            <p class="text-center mb-0">
              <span>Already have an account?</span>
              <a href="{{ route('login') }}">Sign in</a>
            </p>
          </div>
        </div>
      </div>
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
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
      (function() {
        const roleSelect = document.getElementById('role');
        const farmCard = document.getElementById('farm-card');
        const requiredBadge = document.getElementById('farm-required-badge');
        const completenessBadge = document.getElementById('farm-completeness-badge');
        const farmInputs = Array.from(document.querySelectorAll('.farm-input'));
        const locationButton = document.getElementById('use-current-location');
        const clearLocationButton = document.getElementById('clear-farm-location');
        const latitudeInput = document.getElementById('farm_latitude');
        const longitudeInput = document.getElementById('farm_longitude');
        const locationPreview = document.getElementById('farm-location-preview');
        const geoStatusBadge = document.getElementById('farm-geo-status');
        const geolocationFeedback = document.getElementById('farm-geolocation-feedback');
        const mapElement = document.getElementById('farm-registration-map');
        const requiredFarmFieldIds = [
          'farm_name',
          'farm_location',
          'farm_sitio',
          'farm_barangay',
          'farm_municipality',
          'farm_province',
          'farm_latitude',
          'farm_longitude'
        ];
        const defaultCenter = {
          latitude: 10.3547270,
          longitude: 124.9659800
        };

        let map = null;
        let marker = null;

        function roleRequiresFarm(roleValue) {
          return roleValue === 'OWNER' || roleValue === 'WORKER';
        }

        function trimValue(value) {
          return String(value || '').trim();
        }

        function setGeoFeedback(message, toneClass) {
          if (!geolocationFeedback) {
            return;
          }

          geolocationFeedback.textContent = message;
          geolocationFeedback.className = 'form-text';
          if (toneClass) {
            geolocationFeedback.classList.add(toneClass);
          }
        }

        function normalizeCoordinate(value) {
          const parsed = Number(value);
          return Number.isFinite(parsed) ? parsed : NaN;
        }

        function hasValue(fieldId) {
          const field = document.getElementById(fieldId);
          if (!field) {
            return false;
          }

          return trimValue(field.value) !== '';
        }

        function getCoordinateState() {
          const latitude = normalizeCoordinate(latitudeInput ? latitudeInput.value : NaN);
          const longitude = normalizeCoordinate(longitudeInput ? longitudeInput.value : NaN);
          const hasLatitude = Number.isFinite(latitude);
          const hasLongitude = Number.isFinite(longitude);
          const complete = hasLatitude && hasLongitude;
          const valid = complete && latitude >= -90 && latitude <= 90 && longitude >= -180 && longitude <= 180;

          return {
            latitude: latitude,
            longitude: longitude,
            hasLatitude: hasLatitude,
            hasLongitude: hasLongitude,
            complete: complete,
            valid: valid
          };
        }

        function formatCoordinate(value) {
          return Number(value).toFixed(7);
        }

        function updateMapMarker(lat, lng) {
          if (!map || !marker || !Number.isFinite(lat) || !Number.isFinite(lng)) {
            return;
          }

          marker.setLatLng([lat, lng]);
        }

        function setCoordinates(latitude, longitude, sourceLabel) {
          if (!latitudeInput || !longitudeInput || !Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            return;
          }

          latitudeInput.value = formatCoordinate(latitude);
          longitudeInput.value = formatCoordinate(longitude);
          updateMapMarker(latitude, longitude);

          if (map) {
            map.panTo([latitude, longitude], { animate: true });
          }

          updateGeoStatus();
          updateFarmCompletion();

          if (sourceLabel === 'map') {
            setGeoFeedback('Coordinates updated from map selection.', 'text-success');
          } else if (sourceLabel === 'device') {
            setGeoFeedback('Coordinates captured from your current device location.', 'text-success');
          }
        }

        function updateLocationPreview() {
          if (!locationPreview) {
            return;
          }

          const parts = [
            trimValue(document.getElementById('farm_location') ? document.getElementById('farm_location').value : ''),
            trimValue(document.getElementById('farm_sitio') ? document.getElementById('farm_sitio').value : ''),
            trimValue(document.getElementById('farm_barangay') ? document.getElementById('farm_barangay').value : ''),
            trimValue(document.getElementById('farm_municipality') ? document.getElementById('farm_municipality').value : ''),
            trimValue(document.getElementById('farm_province') ? document.getElementById('farm_province').value : '')
          ].filter(function(part) {
            return part !== '';
          });

          if (parts.length === 0) {
            locationPreview.textContent = 'Location preview: Add sitio, barangay, municipality/city, and province.';
            locationPreview.classList.add('text-body-secondary');
            return;
          }

          locationPreview.textContent = 'Location preview: ' + parts.join(', ');
          locationPreview.classList.remove('text-body-secondary');
        }

        function updateGeoStatus() {
          const state = getCoordinateState();

          if (latitudeInput) {
            latitudeInput.classList.toggle('is-invalid', trimValue(latitudeInput.value) !== '' && (!state.hasLatitude || state.latitude < -90 || state.latitude > 90));
          }

          if (longitudeInput) {
            longitudeInput.classList.toggle('is-invalid', trimValue(longitudeInput.value) !== '' && (!state.hasLongitude || state.longitude < -180 || state.longitude > 180));
          }

          if (!geoStatusBadge) {
            return;
          }

          if (!state.hasLatitude && !state.hasLongitude) {
            geoStatusBadge.className = 'badge bg-label-secondary';
            geoStatusBadge.textContent = 'Coordinates not set';
            return;
          }

          if (!state.complete || !state.valid) {
            geoStatusBadge.className = 'badge bg-label-danger';
            geoStatusBadge.textContent = 'Invalid coordinates';
            return;
          }

          geoStatusBadge.className = 'badge bg-label-success';
          geoStatusBadge.textContent = 'Coordinates ready';
        }

        function updateFarmCompletion() {
          if (!completenessBadge) {
            return;
          }

          const requiresFarm = roleRequiresFarm(roleSelect ? roleSelect.value : '');
          const coordinateState = getCoordinateState();

          if (!requiresFarm) {
            completenessBadge.className = 'badge bg-label-secondary';
            completenessBadge.textContent = 'Optional for Customer';
            if (farmCard) {
              farmCard.classList.remove('farm-ready');
            }
            return;
          }

          const missingFields = requiredFarmFieldIds.filter(function(fieldId) {
            return !hasValue(fieldId);
          });

          if (missingFields.length === 0 && coordinateState.valid) {
            completenessBadge.className = 'badge bg-label-success';
            completenessBadge.textContent = 'Ready to submit';
            if (farmCard) {
              farmCard.classList.add('farm-ready');
            }
            return;
          }

          if (missingFields.length === 0 && coordinateState.complete && !coordinateState.valid) {
            if (farmCard) {
              farmCard.classList.remove('farm-ready');
            }
            completenessBadge.className = 'badge bg-label-danger';
            completenessBadge.textContent = 'Fix coordinate values';
            return;
          }

          if (farmCard) {
            farmCard.classList.remove('farm-ready');
          }

          completenessBadge.className = 'badge bg-label-warning';
          completenessBadge.textContent = missingFields.length + ' required field(s) missing';
        }

        function syncFarmRequirements() {
          const requiresFarm = roleRequiresFarm(roleSelect ? roleSelect.value : '');

          farmInputs.forEach(function(input) {
            input.required = requiresFarm;
          });

          if (farmCard) {
            farmCard.classList.toggle('farm-required', requiresFarm);
          }

          if (requiredBadge) {
            requiredBadge.className = requiresFarm ? 'badge bg-danger' : 'badge bg-label-secondary';
            requiredBadge.textContent = requiresFarm
              ? 'Required for Poultry Owner/Staff'
              : 'Optional for Customer';
          }

          updateFarmCompletion();
        }

        function initFarmMap() {
          if (!mapElement) {
            return;
          }

          if (typeof L === 'undefined') {
            mapElement.classList.add('map-unavailable');
            mapElement.textContent = 'Map preview unavailable. You can still enter latitude and longitude manually.';
            return;
          }

          const coordinateState = getCoordinateState();
          const hasCoordinates = coordinateState.complete && coordinateState.valid;
          const initialLatitude = hasCoordinates ? coordinateState.latitude : defaultCenter.latitude;
          const initialLongitude = hasCoordinates ? coordinateState.longitude : defaultCenter.longitude;
          const initialZoom = hasCoordinates ? 15 : 11;

          map = L.map(mapElement, {
            zoomControl: true,
            scrollWheelZoom: false
          }).setView([initialLatitude, initialLongitude], initialZoom);

          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
          }).addTo(map);

          marker = L.marker([initialLatitude, initialLongitude], { draggable: true }).addTo(map);
          marker.bindTooltip('Drag marker or click map to set farm coordinates', {
            direction: 'top',
            offset: [0, -8]
          });

          marker.on('dragend', function() {
            const markerPosition = marker.getLatLng();
            setCoordinates(markerPosition.lat, markerPosition.lng, 'map');
          });

          map.on('click', function(event) {
            setCoordinates(event.latlng.lat, event.latlng.lng, 'map');
          });

          window.setTimeout(function() {
            map.invalidateSize();
          }, 50);
        }

        function bindFarmInputListeners() {
          farmInputs.forEach(function(input) {
            input.addEventListener('input', function() {
              const coordinateState = getCoordinateState();
              if (coordinateState.complete && coordinateState.valid) {
                updateMapMarker(coordinateState.latitude, coordinateState.longitude);
              }
              updateLocationPreview();
              updateGeoStatus();
              updateFarmCompletion();
            });
            input.addEventListener('change', function() {
              const coordinateState = getCoordinateState();
              if (coordinateState.complete && coordinateState.valid) {
                updateMapMarker(coordinateState.latitude, coordinateState.longitude);
              }
              updateLocationPreview();
              updateGeoStatus();
              updateFarmCompletion();
            });
          });
        }

        function clearCoordinates() {
          if (latitudeInput) {
            latitudeInput.value = '';
          }

          if (longitudeInput) {
            longitudeInput.value = '';
          }

          if (map && marker) {
            marker.setLatLng([defaultCenter.latitude, defaultCenter.longitude]);
            map.setView([defaultCenter.latitude, defaultCenter.longitude], 11, { animate: true });
          }

          updateGeoStatus();
          updateFarmCompletion();
          setGeoFeedback('Coordinates cleared. Add new values manually or use map/device location.', 'text-body-secondary');
        }

        if (roleSelect) {
          roleSelect.addEventListener('change', syncFarmRequirements);
        }

        bindFarmInputListeners();
        updateLocationPreview();
        updateGeoStatus();
        syncFarmRequirements();
        initFarmMap();

        if (locationButton && latitudeInput && longitudeInput) {
          locationButton.addEventListener('click', function() {
            if (!navigator.geolocation) {
              setGeoFeedback('Geolocation is not supported by this browser.', 'text-danger');
              return;
            }

            const originalContent = locationButton.innerHTML;
            locationButton.disabled = true;
            locationButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Detecting...';
            setGeoFeedback('Detecting your location. Please allow browser permission if prompted.', 'text-body-secondary');

            navigator.geolocation.getCurrentPosition(
              function(position) {
                setCoordinates(position.coords.latitude, position.coords.longitude, 'device');
                locationButton.disabled = false;
                locationButton.innerHTML = originalContent;
              },
              function(error) {
                setGeoFeedback('Unable to get location: ' + (error.message || 'permission denied'), 'text-danger');
                locationButton.disabled = false;
                locationButton.innerHTML = originalContent;
              },
              {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
              }
            );
          });
        }

        if (clearLocationButton) {
          clearLocationButton.addEventListener('click', clearCoordinates);
        }
      })();
    </script>
  </body>
</html>
