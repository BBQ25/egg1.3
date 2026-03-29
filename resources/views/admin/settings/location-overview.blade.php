@extends('layouts.admin')

@section('title', 'APEWSD - Location Overview')

@section('content')
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

  <style>
    #admin-farms-map {
      width: 100%;
      min-height: 480px;
      border-radius: 0.95rem;
      border: 1px solid rgba(67, 89, 113, 0.16);
    }

    .location-stat-card {
      border: 1px solid rgba(67, 89, 113, 0.12);
      border-radius: 1rem;
      background: #fff;
      padding: 1rem;
      height: 100%;
    }

    .location-stat-label {
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #8592a3;
      margin-bottom: 0.35rem;
    }

    .location-stat-value {
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
  </style>

  @php
    $farmMapCount = count($farmLocationsMapPayload['farms'] ?? []);
    $geofenceEnabledSummary = (bool) ($geofenceSettings['enabled'] ?? false) && (bool) ($geofenceSettings['configured'] ?? false);
    $farmLocationsMapPayloadJson = json_encode($farmLocationsMapPayload ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  @endphp

  <div class="row g-4">
    <div class="col-12">
      <div class="card">
        <div class="card-body d-flex flex-column flex-lg-row align-items-start justify-content-between gap-3">
          <div>
            <span class="badge bg-label-info rounded-pill mb-2">Settings Subpage</span>
            <h4 class="mb-1">Location Overview</h4>
            <p class="mb-0 text-body-secondary">Review all farm coordinates separately from general settings and geofence editing.</p>
          </div>
          <div class="d-flex flex-wrap gap-2">
            @include('admin.partials.map-tour', [
              'tourId' => 'location-overview-tour',
              'tourTitle' => 'Location Overview Guide',
              'tourIntro' => 'This page is a read-only map summary of farms, farm fences, and the active general geofence.',
              'tourSteps' => [
                [
                  'title' => 'Summary cards',
                  'body' => 'Read the summary cards first to see how many farms are registered and whether the geofence overlay is active.',
                  'selector' => '#location-overview-stats',
                ],
                [
                  'title' => 'Quick actions',
                  'body' => 'Use these navigation buttons to jump to geofence editing or the dedicated farm management page.',
                  'selector' => '#location-overview-actions',
                ],
                [
                  'title' => 'Map canvas',
                  'body' => 'Blue markers represent farm coordinates. The blue overlay shows the general geofence, while green overlays indicate farm-specific fences.',
                  'selector' => '#admin-farms-map',
                ],
                [
                  'title' => 'Update tip',
                  'body' => 'If a marker is missing or misplaced, update that owner or farm record from the management flows rather than editing this read-only page.',
                  'selector' => '#location-overview-tip',
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
            <a href="{{ route('admin.settings.access-boundary') }}" class="btn btn-outline-primary settings-dusk-btn">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/map/icons/icons8-entering-geo-fence.png',
                'alt' => 'Access Boundary',
                'classes' => 'settings-dusk-icon me-1',
              ])Access Boundary
            </a>
            <a href="{{ route('admin.maps.farms') }}" class="btn btn-outline-primary settings-dusk-btn">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/address/icons/icons8-map.png',
                'alt' => 'Farm & Map',
                'classes' => 'settings-dusk-icon me-1',
              ])Farm & Map
            </a>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card">
        <div class="card-header">
          <div class="d-flex align-items-start gap-3">
            <span class="avatar bg-label-info"><i class="bx bx-map-alt"></i></span>
            <div class="flex-grow-1">
              <h5 class="card-title mb-1">All Farm Locations</h5>
              <p class="mb-0 text-body-secondary">Review registered farm coordinates and compare them against the active system geofence.</p>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3 mb-4" id="location-overview-stats">
            <div class="col-6 col-xl-3">
              <div class="location-stat-card">
                <div class="location-stat-label">Registered Farms</div>
                <div class="location-stat-value">{{ number_format($farmMapCount) }}</div>
              </div>
            </div>
            <div class="col-6 col-xl-3">
              <div class="location-stat-card">
                <div class="location-stat-label">Overlay</div>
                <div class="location-stat-value">{{ $geofenceEnabledSummary ? 'System geofence visible' : 'Farm markers only' }}</div>
              </div>
            </div>
            <div class="col-6 col-xl-3">
              <div class="location-stat-card">
                <div class="location-stat-label">Geofence</div>
                <div class="location-stat-value">
                  @if ($geofenceEnabledSummary)
                    <span class="settings-dusk-status">
                      @include('partials.curated-shell-icon', [
                        'src' => 'resources/icons/dusk/check/animated/icons8-ok--v2.gif',
                        'alt' => 'Enabled',
                        'classes' => 'settings-dusk-icon',
                      ])Enabled
                    </span>
                  @else
                    Inactive
                  @endif
                </div>
              </div>
            </div>
            <div class="col-6 col-xl-3">
              <div class="location-stat-card">
                <div class="location-stat-label">Page Focus</div>
                <div class="location-stat-value">Read-only overview</div>
              </div>
            </div>
          </div>

          <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3 mb-3" id="location-overview-actions">
            <div class="form-text m-0">Marker data comes from each owner's saved farm coordinates. Maps can be displayed in Standard, Satellite, and Terrain views.</div>
            <a href="{{ route('admin.maps.farms') }}" class="btn btn-outline-primary settings-dusk-btn">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/address/icons/icons8-map-marker.png',
                'alt' => 'Open map-only view',
                'classes' => 'settings-dusk-icon me-1',
              ])Open map-only view
            </a>
          </div>

          <div id="admin-farms-map" aria-label="All farm locations map"></div>
          <script id="admin_farms_map_payload" type="application/json">{!! $farmLocationsMapPayloadJson !!}</script>
          <div class="small text-body-secondary mt-3" id="location-overview-tip">Tip: update an owner's farm latitude and longitude from the user management flow when a marker is missing or misplaced.</div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    (function() {
      var mapElement = document.getElementById('admin-farms-map');
      var payloadElement = document.getElementById('admin_farms_map_payload');

      if (!mapElement || !payloadElement || typeof L === 'undefined') {
        return;
      }

      var payload = {};
      try { payload = JSON.parse(payloadElement.textContent || '{}'); } catch (error) { payload = {}; }
      function toNumber(value, fallback) { var parsed = Number(value); return Number.isFinite(parsed) ? parsed : fallback; }
      function escapeHtml(value) {
        return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
      }
      function normalizeShape(shape) {
        var normalized = String(shape || '').toUpperCase();
        return ['CIRCLE', 'RECTANGLE', 'SQUARE', 'POLYGON'].indexOf(normalized) === -1 ? null : normalized;
      }
      function shapeLabel(shape) {
        var normalized = normalizeShape(shape);
        if (normalized === 'RECTANGLE') return 'Rectangle';
        if (normalized === 'SQUARE') return 'Square';
        if (normalized === 'POLYGON') return 'Polygon';
        if (normalized === 'CIRCLE') return 'Circle';
        return 'Not configured';
      }
      function buildLayerFromGeometry(geometry, style) {
        if (!geometry || typeof geometry !== 'object') return null;
        var shapeType = normalizeShape(geometry.shape_type);
        if (!shapeType) return null;
        var layer = null;
        if (shapeType === 'CIRCLE') {
          var centerLatitude = toNumber(geometry.center_latitude, NaN);
          var centerLongitude = toNumber(geometry.center_longitude, NaN);
          var radiusMeters = toNumber(geometry.radius_meters, NaN);
          if (Number.isFinite(centerLatitude) && Number.isFinite(centerLongitude) && Number.isFinite(radiusMeters) && radiusMeters > 0) {
            layer = L.circle([centerLatitude, centerLongitude], { radius: radiusMeters });
          }
        } else if (shapeType === 'RECTANGLE' || shapeType === 'SQUARE') {
          var boundsData = geometry.bounds || geometry;
          var north = toNumber(boundsData.north, NaN), south = toNumber(boundsData.south, NaN), east = toNumber(boundsData.east, NaN), west = toNumber(boundsData.west, NaN);
          if (Number.isFinite(north) && Number.isFinite(south) && Number.isFinite(east) && Number.isFinite(west) && north > south && east > west) {
            layer = L.rectangle([[south, west], [north, east]]);
          }
        } else if (shapeType === 'POLYGON') {
          var vertices = Array.isArray(geometry.vertices) ? geometry.vertices : [];
          var points = vertices.map(function(point) {
            if (!Array.isArray(point) || point.length < 2) return null;
            var latitude = toNumber(point[0], NaN), longitude = toNumber(point[1], NaN);
            return Number.isFinite(latitude) && Number.isFinite(longitude) ? [latitude, longitude] : null;
          }).filter(function(point) { return point !== null; });
          if (points.length >= 3) layer = L.polygon(points);
        }
        if (layer && style && typeof layer.setStyle === 'function') layer.setStyle(style);
        return layer;
      }
      function createBaseLayers() {
        return {
          Standard: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }),
          Satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Tiles &copy; Esri' }),
          Terrain: L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', { maxZoom: 17, attribution: 'Map data: &copy; OpenStreetMap contributors, SRTM | Map style: &copy; OpenTopoMap' })
        };
      }
      function fenceSummary(farm) {
        var fence = farm && farm.fence && typeof farm.fence === 'object' ? farm.fence : null;
        if (!fence || !fence.enabled) return 'Fence: Disabled';
        var shape = fence.shape_type || (fence.geometry && fence.geometry.shape_type);
        if (!fence.geometry || typeof fence.geometry !== 'object') return 'Fence: Enabled (incomplete)';
        return 'Fence: ' + shapeLabel(shape);
      }
      function popupHtmlForFarm(farm, latitude, longitude) {
        var popupParts = [];
        popupParts.push('<strong>' + escapeHtml(String(farm.farm_name || 'Farm')) + '</strong>');
        if (farm.owner_name || farm.owner_username) popupParts.push('Owner: ' + escapeHtml(String(farm.owner_name || farm.owner_username)));
        var locationLine = [farm.location, farm.sitio, farm.barangay, farm.municipality, farm.province].filter(function(part) { return part !== null && String(part).trim() !== ''; }).join(', ');
        if (locationLine !== '') popupParts.push(escapeHtml(locationLine));
        popupParts.push(Number.isFinite(latitude) && Number.isFinite(longitude) ? 'Coordinates: ' + latitude.toFixed(7) + ', ' + longitude.toFixed(7) : 'Coordinates: Not set');
        popupParts.push(escapeHtml(fenceSummary(farm)));
        return popupParts.join('<br />');
      }

      var defaultCenter = payload.default_center || { latitude: 10.354727, longitude: 124.965980 };
      var farms = Array.isArray(payload.farms) ? payload.farms : [];
      var geofence = payload.geofence && typeof payload.geofence === 'object' ? payload.geofence : null;
      var geofenceGeometries = geofence && Array.isArray(geofence.geometries) ? geofence.geometries.filter(function(geometry) { return geometry && typeof geometry === 'object'; }) : [];
      if (geofenceGeometries.length === 0 && geofence && geofence.geometry && typeof geofence.geometry === 'object') geofenceGeometries = [geofence.geometry];

      var baseLayers = createBaseLayers();
      var map = L.map(mapElement, { zoomControl: true }).setView([toNumber(defaultCenter.latitude, 10.354727), toNumber(defaultCenter.longitude, 124.965980)], 12);
      baseLayers.Standard.addTo(map);
      L.control.layers(baseLayers, null, { collapsed: false }).addTo(map);
      var bounds = L.latLngBounds([]);

      geofenceGeometries.forEach(function(geofenceGeometry) {
        var geofenceLayer = buildLayerFromGeometry(geofenceGeometry, { color: '#696cff', dashArray: '6 4', weight: 2, fillColor: '#696cff', fillOpacity: 0.1 });
        if (geofenceLayer) {
          geofenceLayer.addTo(map);
          if (typeof geofenceLayer.getBounds === 'function') bounds.extend(geofenceLayer.getBounds());
        }
      });

      farms.forEach(function(farm) {
        var fence = farm.fence && typeof farm.fence === 'object' ? farm.fence : null;
        var fenceGeometry = fence && fence.geometry && typeof fence.geometry === 'object' ? fence.geometry : null;
        if (fence && fence.enabled && fenceGeometry) {
          var fenceLayer = buildLayerFromGeometry(fenceGeometry, { color: '#28c76f', weight: 2, fillColor: '#28c76f', fillOpacity: 0.12 });
          if (fenceLayer) {
            fenceLayer.addTo(map);
            if (typeof fenceLayer.getBounds === 'function') bounds.extend(fenceLayer.getBounds());
            fenceLayer.bindPopup(popupHtmlForFarm(farm, NaN, NaN));
          }
        }

        var latitude = toNumber(farm.latitude, NaN), longitude = toNumber(farm.longitude, NaN);
        if (Number.isFinite(latitude) && Number.isFinite(longitude)) {
          bounds.extend([latitude, longitude]);
          L.marker([latitude, longitude]).addTo(map).bindPopup(popupHtmlForFarm(farm, latitude, longitude));
        }
      });

      if (bounds.isValid()) map.fitBounds(bounds, { padding: [25, 25] });
      setTimeout(function() { map.invalidateSize(); }, 100);
    })();
  </script>
@endsection
