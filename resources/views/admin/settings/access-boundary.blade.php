@extends('layouts.admin')

@section('title', 'APEWSD - Access Boundary')

@section('content')
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css" />

  <style>
    #admin-geofence-map {
      width: 100%;
      min-height: 420px;
      border-radius: 0.95rem;
      border: 1px solid rgba(67, 89, 113, 0.16);
    }

    .boundary-stat-card {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1rem;
      background: #fff;
      padding: 1rem;
      height: 100%;
    }

    .boundary-stat-label {
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #8592a3;
      margin-bottom: 0.35rem;
    }

    .boundary-stat-value {
      font-size: 1.15rem;
      font-weight: 700;
      color: #233446;
    }

    .settings-dusk-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .settings-dusk-icon {
      width: 1rem;
      height: 1rem;
      display: inline-block;
      flex-shrink: 0;
    }

    .settings-dusk-status {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
    }

    .settings-dusk-avatar {
      width: 1.45rem;
      height: 1.45rem;
      display: inline-block;
      flex-shrink: 0;
    }
  </style>

  @php
    $summaryGeometries = is_array($geofenceSettings['geometries'] ?? null) ? $geofenceSettings['geometries'] : [];
    $summaryGeometry = $summaryGeometries[0] ?? ($geofenceSettings['geometry'] ?? null);
    $summaryZoneCount = count($summaryGeometries);
    $summaryShape = strtoupper((string) ($summaryGeometry['shape_type'] ?? ''));
    $geofenceEnabledValue = old('geofence_enabled', $geofenceSettings['enabled'] ?? false) ? true : false;
    $geofenceShapeTypeValue = old('geofence_shape_type', $geofenceSettings['shape_type'] ?? 'CIRCLE');
    $geofenceGeometryValue = old('geofence_geometry', json_encode(['zones' => $geofenceSettings['geometries'] ?? []]));
    $geofenceMapPayloadJson = json_encode($geofenceMapPayload ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  @endphp

  <div class="row g-4">
    <div class="col-12">
      <div class="card">
        <div class="card-body d-flex flex-column flex-lg-row align-items-start justify-content-between gap-3">
          <div>
            <span class="badge bg-label-success rounded-pill mb-2">Settings Subpage</span>
            <h4 class="mb-1">Access Boundary</h4>
            <p class="mb-0 text-body-secondary">Manage the system geofence separately from typography and navigation settings.</p>
          </div>
          <div class="d-flex flex-wrap gap-2">
            @include('admin.partials.map-tour', [
              'tourId' => 'access-boundary-tour',
              'tourTitle' => 'Access Boundary Guide',
              'tourIntro' => 'This page controls the general geofence used to allow or block non-admin access.',
              'tourSteps' => [
                [
                  'title' => 'Summary cards',
                  'body' => 'Start with the summary cards to confirm whether restriction is enabled, how many zones are saved, and which primary shape is active.',
                  'selector' => '#access-boundary-stats',
                ],
                [
                  'title' => 'Restriction toggle',
                  'body' => 'Use the geofence restriction switch to turn the general boundary check on or off for non-admin users.',
                  'selector' => '#geofence_enabled',
                ],
                [
                  'title' => 'Drawing controls',
                  'body' => 'Pick a default drawing mode, then use the map tools to draw one or more allowed zones. The clear button removes all unsaved shapes on the canvas.',
                  'selector' => '#access-boundary-controls',
                ],
                [
                  'title' => 'Map canvas',
                  'body' => 'The map is where you draw or edit the live boundary zones. Circle, rectangle, and polygon plots represent the permitted area for access.',
                  'selector' => '#admin-geofence-map',
                ],
                [
                  'title' => 'Save boundary',
                  'body' => 'Changes are only stored after you save. Drawn zones are not enforced until the boundary is saved successfully.',
                  'selector' => '#access-boundary-save-btn',
                ],
              ],
            ])
            <a href="{{ route('admin.settings.edit') }}" class="btn btn-label-secondary settings-dusk-btn">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/gear/icons/icons8-settings.png',
                'alt' => 'General Settings',
                'classes' => 'settings-dusk-icon me-1',
              ])General Settings
            </a>
            <a href="{{ route('admin.settings.location-overview') }}" class="btn btn-outline-primary settings-dusk-btn">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/address/icons/icons8-place-marker--v3.png',
                'alt' => 'Location Overview',
                'classes' => 'settings-dusk-icon me-1',
              ])Location Overview
            </a>
          </div>
        </div>
      </div>
    </div>

    @if (session('status'))
      <div class="col-12">
        <div class="alert alert-success" role="alert">{{ session('status') }}</div>
      </div>
    @endif

    @if ($errors->any())
      <div class="col-12">
        <div class="alert alert-danger" role="alert">
          <div class="fw-semibold mb-1">Please review the boundary settings.</div>
          <div class="small">{{ $errors->first() }}</div>
        </div>
      </div>
    @endif

    <div class="col-12">
      <div class="card mb-4">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-lg-6">
              <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-2">What the General Geofence means</div>
                <div class="small text-body-secondary">
                  The general geofence is the system-wide operating boundary for non-admin access. It defines the larger area where users are expected to sign in and work.
                  It is not the same thing as a specific farm plot.
                </div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-2">What Premises means</div>
                <div class="small text-body-secondary">
                  Premises are smaller zones under the wider boundary. Farm premises describe the expected plot of a farm.
                  User premises describe where a specific account may access the system. Use premises for precise local areas; use the general geofence for the broad allowed region.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <form method="POST" action="{{ route('admin.settings.access-boundary.update') }}" id="access-boundary-form">
        @csrf
        @method('PUT')

        <div class="card">
          <div class="card-header">
            <div class="d-flex align-items-start gap-3">
              <span class="avatar bg-label-primary">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/map/icons/icons8-entering-geo-fence.png',
                  'alt' => 'System geofence',
                  'classes' => 'settings-dusk-avatar',
                ])
              </span>
              <div class="flex-grow-1">
                <h5 class="card-title mb-1">System Geofence</h5>
                <p class="mb-0 text-body-secondary">Restrict access by drawing one or more permitted boundary zones.</p>
              </div>
              <button type="submit" class="btn btn-primary settings-dusk-btn" id="access-boundary-save-btn">
                @include('partials.curated-shell-icon', [
                  'src' => 'resources/icons/dusk/save/animated/icons8-save--v2.gif',
                  'alt' => 'Save Boundary',
                  'classes' => 'settings-dusk-icon me-1',
                ])Save Boundary
              </button>
            </div>
          </div>
          <div class="card-body">
            <div class="row g-3 mb-4" id="access-boundary-stats">
              <div class="col-6 col-xl-3">
                <div class="boundary-stat-card">
                  <div class="boundary-stat-label">Restriction</div>
                  <div class="boundary-stat-value">
                    @if ($geofenceSettings['enabled'] ?? false)
                      <span class="settings-dusk-status">
                        @include('partials.curated-shell-icon', [
                          'src' => 'resources/icons/dusk/check/animated/icons8-ok--v2.gif',
                          'alt' => 'Enabled',
                          'classes' => 'settings-dusk-icon',
                        ])Enabled
                      </span>
                    @else
                      Disabled
                    @endif
                  </div>
                </div>
              </div>
              <div class="col-6 col-xl-3">
                <div class="boundary-stat-card">
                  <div class="boundary-stat-label">Configured Zones</div>
                  <div class="boundary-stat-value">
                    <span class="settings-dusk-status">
                      @include('partials.curated-shell-icon', [
                        'src' => 'resources/icons/dusk/app/icons/icons8-numbers.png',
                        'alt' => 'Configured zones',
                        'classes' => 'settings-dusk-icon',
                      ]){{ $summaryZoneCount }}
                    </span>
                  </div>
                </div>
              </div>
              <div class="col-6 col-xl-3">
                <div class="boundary-stat-card">
                  <div class="boundary-stat-label">Primary Shape</div>
                  <div class="boundary-stat-value">
                    @if ($summaryShape !== '')
                      <span class="settings-dusk-status">
                        @include('partials.curated-shell-icon', [
                          'src' => 'resources/icons/dusk/editing/icons/icons8-starburst-shape.png',
                          'alt' => 'Primary shape',
                          'classes' => 'settings-dusk-icon',
                        ]){{ ucfirst(strtolower($summaryShape)) }}
                      </span>
                    @else
                      Not configured
                    @endif
                  </div>
                </div>
              </div>
              <div class="col-6 col-xl-3">
                <div class="boundary-stat-card">
                  <div class="boundary-stat-label">Default Center</div>
                  <div class="boundary-stat-value">
                    <span class="settings-dusk-status">
                      @include('partials.curated-shell-icon', [
                        'src' => 'resources/icons/dusk/location/icons/icons8-center-direction.png',
                        'alt' => 'Default center',
                        'classes' => 'settings-dusk-icon',
                      ])10.354727, 124.965980
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <div class="form-check form-switch mb-4">
              <input class="form-check-input" type="checkbox" role="switch" id="geofence_enabled" name="geofence_enabled" value="1" @checked($geofenceEnabledValue) />
              <label class="form-check-label fw-semibold" for="geofence_enabled">Enable geofence restriction</label>
              <div class="form-text">When enabled, non-admin access is evaluated against the saved boundary zones.</div>
            </div>

            <div class="row g-3 mb-4" id="access-boundary-controls">
              <div class="col-md-6">
                <label class="form-label fw-semibold" for="geofence_shape_selector">Default drawing mode</label>
                <select id="geofence_shape_selector" class="form-select">
                  @foreach ($geofenceShapeOptions as $shapeValue => $shapeLabel)
                    <option value="{{ $shapeValue }}" @selected($geofenceShapeTypeValue === $shapeValue)>{{ $shapeLabel }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-md-6 d-flex align-items-end">
                <button type="button" id="geofence-clear-btn" class="btn btn-outline-danger w-100 settings-dusk-btn">
                  @include('partials.curated-shell-icon', [
                    'src' => 'resources/icons/dusk/delete/icons/icons8-cancel--v2.png',
                    'alt' => 'Clear all drawn zones',
                    'classes' => 'settings-dusk-icon me-1',
                  ])Clear all drawn zones
                </button>
              </div>
            </div>

            <input type="hidden" id="geofence_shape_type" name="geofence_shape_type" value="{{ $geofenceShapeTypeValue }}" />
            <input type="hidden" id="geofence_geometry" name="geofence_geometry" value="{{ $geofenceGeometryValue }}" />

            <div class="alert alert-label-info mb-4" role="alert">
              <div class="fw-semibold mb-1">Drawing guide</div>
              <ul class="mb-0 small ps-3">
                <li>Use the circle, rectangle, or polygon tools to define allowed zones.</li>
                <li>Square mode normalizes rectangles into equal-sided boundaries.</li>
                <li>Boundary edits are not live until you save them.</li>
              </ul>
            </div>

            <div id="admin-geofence-map" aria-label="Geofence drawing map"></div>
            <script id="admin_geofence_map_payload" type="application/json">{!! $geofenceMapPayloadJson !!}</script>
            @error('geofence_geometry')
              <div class="text-danger small mt-2">{{ $message }}</div>
            @enderror
            <div id="geofence_client_error" class="text-danger small mt-2 d-none"></div>
          </div>
        </div>
      </form>
    </div>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
  <script>
    (function() {
      var mapElement = document.getElementById('admin-geofence-map');
      var payloadElement = document.getElementById('admin_geofence_map_payload');
      var shapeSelector = document.getElementById('geofence_shape_selector');
      var shapeInput = document.getElementById('geofence_shape_type');
      var geometryInput = document.getElementById('geofence_geometry');
      var clearButton = document.getElementById('geofence-clear-btn');
      var enabledInput = document.getElementById('geofence_enabled');
      var form = document.getElementById('access-boundary-form');
      var clientErrorElement = document.getElementById('geofence_client_error');

      if (!mapElement || !payloadElement || !shapeSelector || !shapeInput || !geometryInput || typeof L === 'undefined') {
        return;
      }

      function hasOwn(object, key) { return Object.prototype.hasOwnProperty.call(object || {}, key); }
      function toNumber(value, fallback) { var parsed = Number(value); return Number.isFinite(parsed) ? parsed : fallback; }
      function roundCoordinate(value) { return Number(Number(value).toFixed(7)); }
      function parseJson(value) { try { return JSON.parse(value || 'null'); } catch (error) { return null; } }
      function setClientError(message) {
        if (!clientErrorElement) return;
        clientErrorElement.textContent = message || '';
        clientErrorElement.classList.toggle('d-none', !message);
      }
      function normalizeShape(shape) {
        var normalized = String(shape || '').toUpperCase();
        return ['CIRCLE', 'RECTANGLE', 'SQUARE', 'POLYGON'].indexOf(normalized) === -1 ? 'CIRCLE' : normalized;
      }
      function layerIsCircle(layer) { return layer instanceof L.Circle; }
      function layerIsRectangle(layer) { return layer instanceof L.Rectangle; }
      function layerIsPolygon(layer) { return layer instanceof L.Polygon && !layerIsRectangle(layer); }
      function normalizeBoundsObject(bounds) {
        return {
          north: roundCoordinate(bounds.getNorth()),
          south: roundCoordinate(bounds.getSouth()),
          east: roundCoordinate(bounds.getEast()),
          west: roundCoordinate(bounds.getWest())
        };
      }
      function squareBounds(bounds) {
        var north = bounds.north, south = bounds.south, east = bounds.east, west = bounds.west;
        var latSpan = north - south, lngSpan = east - west;
        if (latSpan === lngSpan) return bounds;
        if (latSpan > lngSpan) {
          var lngPad = (latSpan - lngSpan) / 2;
          east += lngPad;
          west -= lngPad;
        } else {
          var latPad = (lngSpan - latSpan) / 2;
          north += latPad;
          south -= latPad;
        }
        return { north: roundCoordinate(north), south: roundCoordinate(south), east: roundCoordinate(east), west: roundCoordinate(west) };
      }
      function inferShapeFromGeometry(geometry) {
        if (!geometry || typeof geometry !== 'object') return null;
        if (hasOwn(geometry, 'center_latitude') && hasOwn(geometry, 'center_longitude') && hasOwn(geometry, 'radius_meters')) return 'CIRCLE';
        var bounds = geometry.bounds || geometry;
        if (bounds && typeof bounds === 'object' && hasOwn(bounds, 'north') && hasOwn(bounds, 'south') && hasOwn(bounds, 'east') && hasOwn(bounds, 'west')) return 'RECTANGLE';
        if (Array.isArray(geometry.vertices)) return 'POLYGON';
        return null;
      }
      function inferShapeFromLayer(layer) {
        if (layerIsCircle(layer)) return 'CIRCLE';
        if (layerIsPolygon(layer)) return 'POLYGON';
        if (layerIsRectangle(layer)) return 'RECTANGLE';
        return null;
      }
      function layerMatchesShape(shape, layer) {
        if (!layer) return false;
        if (shape === 'CIRCLE') return layerIsCircle(layer);
        if (shape === 'POLYGON') return layerIsPolygon(layer);
        if (shape === 'RECTANGLE' || shape === 'SQUARE') return layerIsRectangle(layer);
        return false;
      }
      function drawOptions(shape) {
        return {
          polyline: false, marker: false, circlemarker: false,
          polygon: shape === 'POLYGON' ? { allowIntersection: false, showArea: true, shapeOptions: { color: '#696cff', weight: 2, fillColor: '#696cff', fillOpacity: 0.14 } } : false,
          rectangle: shape === 'RECTANGLE' || shape === 'SQUARE' ? { shapeOptions: { color: '#696cff', weight: 2, fillColor: '#696cff', fillOpacity: 0.14 } } : false,
          circle: shape === 'CIRCLE' ? { shapeOptions: { color: '#696cff', weight: 2, fillColor: '#696cff', fillOpacity: 0.14 } } : false
        };
      }
      function updateShapeInputs(shape) { var normalized = normalizeShape(shape || shapeSelector.value); shapeSelector.value = normalized; shapeInput.value = normalized; }

      var payload = parseJson(payloadElement ? payloadElement.textContent : '') || {};
      var defaultCenter = payload.default_center || { latitude: 10.354727, longitude: 124.965980 };
      var mapCenter = payload.map_center || defaultCenter;
      var map = L.map(mapElement, { zoomControl: true }).setView([toNumber(mapCenter.latitude, 10.354727), toNumber(mapCenter.longitude, 124.965980)], 14);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
      var drawnItems = new L.FeatureGroup(); map.addLayer(drawnItems);
      var drawControl = null;
      var zoneStyle = { color: '#696cff', weight: 2, fillColor: '#696cff', fillOpacity: 0.14 };
      function applyZoneStyle(layer) { if (layer && typeof layer.setStyle === 'function') layer.setStyle(zoneStyle); }
      function normalizeLayerGeometry(layer, shape) {
        if (shape === 'CIRCLE') {
          if (!layerIsCircle(layer)) return null;
          var center = layer.getLatLng();
          return { center_latitude: roundCoordinate(center.lat), center_longitude: roundCoordinate(center.lng), radius_meters: Math.max(25, Math.round(layer.getRadius())) };
        }
        if (shape === 'RECTANGLE' || shape === 'SQUARE') {
          if (!layerIsRectangle(layer)) return null;
          var boundsObject = normalizeBoundsObject(layer.getBounds());
          if (shape === 'SQUARE') {
            boundsObject = squareBounds(boundsObject);
            layer.setBounds([[boundsObject.south, boundsObject.west], [boundsObject.north, boundsObject.east]]);
          }
          return { bounds: boundsObject };
        }
        if (shape === 'POLYGON') {
          if (!layerIsPolygon(layer)) return null;
          var rings = layer.getLatLngs();
          var outerRing = Array.isArray(rings) && Array.isArray(rings[0]) ? rings[0] : [];
          var vertices = outerRing.map(function(latLng) { return [roundCoordinate(latLng.lat), roundCoordinate(latLng.lng)]; });
          return vertices.length < 3 ? null : { vertices: vertices };
        }
        return null;
      }
      function serializeLayer(layer) {
        if (!layer) return null;
        var shape = normalizeShape(layer._geofenceShapeType || inferShapeFromLayer(layer) || shapeInput.value);
        var geometry = normalizeLayerGeometry(layer, shape);
        if (!geometry) return null;
        layer._geofenceShapeType = shape;
        applyZoneStyle(layer);
        return { shape_type: shape, geometry: geometry };
      }
      function clearAllLayers() { drawnItems.clearLayers(); syncGeometryInput(); }
      function syncGeometryInput() {
        updateShapeInputs();
        var zones = [];
        drawnItems.eachLayer(function(layer) {
          var serialized = serializeLayer(layer);
          if (serialized) zones.push(serialized);
        });
        geometryInput.value = zones.length > 0 ? JSON.stringify({ zones: zones }) : '';
      }
      function buildLayerFromGeometry(shape, geometry) {
        if (!geometry || typeof geometry !== 'object') return null;
        if (shape === 'CIRCLE') {
          var latitude = toNumber(geometry.center_latitude, NaN);
          var longitude = toNumber(geometry.center_longitude, NaN);
          var radius = toNumber(geometry.radius_meters, NaN);
          if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || !Number.isFinite(radius) || radius <= 0) return null;
          return L.circle([latitude, longitude], { radius: radius });
        }
        if (shape === 'RECTANGLE' || shape === 'SQUARE') {
          var bounds = geometry.bounds || geometry;
          var north = toNumber(bounds.north, NaN), south = toNumber(bounds.south, NaN), east = toNumber(bounds.east, NaN), west = toNumber(bounds.west, NaN);
          if (!Number.isFinite(north) || !Number.isFinite(south) || !Number.isFinite(east) || !Number.isFinite(west) || north <= south || east <= west) return null;
          bounds = shape === 'SQUARE' ? squareBounds({ north: north, south: south, east: east, west: west }) : { north: north, south: south, east: east, west: west };
          return L.rectangle([[bounds.south, bounds.west], [bounds.north, bounds.east]]);
        }
        if (shape === 'POLYGON') {
          var vertices = Array.isArray(geometry.vertices) ? geometry.vertices : [];
          if (vertices.length < 3) return null;
          var points = vertices.map(function(vertex) {
            if (!Array.isArray(vertex) || vertex.length < 2) return null;
            var lat = toNumber(vertex[0], NaN), lng = toNumber(vertex[1], NaN);
            return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : null;
          }).filter(function(point) { return point !== null; });
          return points.length >= 3 ? L.polygon(points) : null;
        }
        return null;
      }
      function parseZonesFromPayload(decoded, fallbackShape) {
        if (!decoded) return [];
        var entries = [];
        if (Array.isArray(decoded)) entries = decoded;
        else if (typeof decoded === 'object' && Array.isArray(decoded.zones)) entries = decoded.zones;
        else if (typeof decoded === 'object') entries = [decoded];
        return entries.map(function(entry) {
          if (!entry || typeof entry !== 'object') return null;
          var geometry = entry.geometry && typeof entry.geometry === 'object' ? entry.geometry : entry;
          var inferredShape = inferShapeFromGeometry(geometry);
          var shape = normalizeShape(String(entry.shape_type || '').toUpperCase() || inferredShape || fallbackShape);
          var layer = buildLayerFromGeometry(shape, geometry);
          return layer ? { shape_type: shape, layer: layer } : null;
        }).filter(function(zone) { return zone !== null; });
      }
      function fitToDrawnZones() {
        var bounds = L.latLngBounds([]);
        drawnItems.eachLayer(function(layer) {
          if (typeof layer.getBounds !== 'function') return;
          var layerBounds = layer.getBounds();
          if (layerBounds && layerBounds.isValid && layerBounds.isValid()) bounds.extend(layerBounds);
        });
        if (bounds.isValid()) map.fitBounds(bounds, { padding: [20, 20] });
      }
      function refreshDrawControl() {
        updateShapeInputs();
        if (drawControl) map.removeControl(drawControl);
        drawControl = new L.Control.Draw({ draw: drawOptions(normalizeShape(shapeInput.value)), edit: { featureGroup: drawnItems, edit: true, remove: true } });
        map.addControl(drawControl);
      }
      map.on(L.Draw.Event.CREATED, function(event) {
        setClientError('');
        updateShapeInputs();
        if (!layerMatchesShape(shapeInput.value, event.layer)) {
          setClientError('Selected shape and drawn perimeter do not match. Please draw the selected shape.');
          return;
        }
        event.layer._geofenceShapeType = normalizeShape(shapeInput.value);
        applyZoneStyle(event.layer);
        drawnItems.addLayer(event.layer);
        syncGeometryInput();
      });
      map.on(L.Draw.Event.EDITED, function() { syncGeometryInput(); });
      map.on(L.Draw.Event.DELETED, function() { syncGeometryInput(); });
      shapeSelector.addEventListener('change', function() { setClientError(''); updateShapeInputs(shapeSelector.value); refreshDrawControl(); });
      if (clearButton) clearButton.addEventListener('click', function() { setClientError(''); clearAllLayers(); });
      form.addEventListener('submit', function(event) {
        updateShapeInputs();
        syncGeometryInput();
        if (!enabledInput || !enabledInput.checked) { setClientError(''); return; }
        if (!geometryInput.value) {
          event.preventDefault();
          setClientError('Please draw and save a geofence perimeter before updating settings.');
          mapElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });

      var savedShape = normalizeShape(shapeInput.value || shapeSelector.value);
      var initialZones = parseZonesFromPayload(parseJson(geometryInput.value), savedShape);
      if (initialZones.length === 0) initialZones = parseZonesFromPayload(payload.geometries || [], savedShape);
      if (initialZones.length === 0 && payload.geometry && typeof payload.geometry === 'object') initialZones = parseZonesFromPayload(payload.geometry, savedShape);
      if (initialZones.length > 0) savedShape = normalizeShape(initialZones[0].shape_type);
      updateShapeInputs(savedShape || 'CIRCLE');
      refreshDrawControl();
      initialZones.forEach(function(zone) {
        zone.layer._geofenceShapeType = normalizeShape(zone.shape_type);
        applyZoneStyle(zone.layer);
        drawnItems.addLayer(zone.layer);
      });
      syncGeometryInput();
      if (initialZones.length > 0) fitToDrawnZones();
      setTimeout(function() { map.invalidateSize(); }, 100);
    })();
  </script>
@endsection
