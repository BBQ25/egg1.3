@extends('layouts.admin')

@section('title', 'APEWSD - Edit User')

@section('content')
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css" />

  <style>
    #user-premises-map {
      width: 100%;
      min-height: 320px;
      border-radius: 0.75rem;
      border: 1px solid rgba(67, 89, 113, 0.16);
    }

    #user-premises-map.map-outside-geofence {
      border-color: rgba(255, 62, 29, 0.9);
      box-shadow: 0 0 0 1px rgba(255, 62, 29, 0.35);
    }

    #farm-fence-map {
      width: 100%;
      min-height: 320px;
      border-radius: 0.75rem;
      border: 1px solid rgba(67, 89, 113, 0.16);
    }
  </style>

  <div class="row">
    <div class="col-12">
      <h4 class="mb-1">Edit User</h4>
      <p class="mb-6">Update account details, role, password, and location access settings for <strong>{{ $user->username }}</strong>.</p>
    </div>
  </div>

  @if (session('status'))
    <div class="alert alert-success" role="alert">
      {{ session('status') }}
    </div>
  @endif

  @if ($errors->any())
    <div class="alert alert-danger" role="alert">
      {{ $errors->first() }}
    </div>
  @endif

  @php
    $userPremisesEnabledValue = old('user_premises_enabled', $userPremisesSettings['enabled'] ?? false) ? true : false;
    $userPremisesShapeTypeValue = old('user_premises_shape_type', $userPremisesSettings['shape_type'] ?? 'CIRCLE');
    $userPremisesGeometryValue = old('user_premises_geometry', json_encode($userPremisesSettings['geometry'] ?? null));
    $userPremisesMapPayloadJson = json_encode($userPremisesMapPayload ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $farmFencesMapPayloadJson = json_encode($farmFencesMapPayload ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $encodeFarmFenceGeometry = static function ($zone): string {
        if (!$zone || !$zone->is_active) {
            return '';
        }

        $shapeType = strtoupper((string) $zone->shape_type);
        if ($shapeType === 'CIRCLE') {
            if ($zone->center_latitude === null || $zone->center_longitude === null || $zone->radius_meters === null) {
                return '';
            }

            return (string) json_encode([
                'center_latitude' => (float) $zone->center_latitude,
                'center_longitude' => (float) $zone->center_longitude,
                'radius_meters' => (int) $zone->radius_meters,
            ]);
        }

        if ($shapeType === 'RECTANGLE' || $shapeType === 'SQUARE') {
            if ($zone->bounds_north === null || $zone->bounds_south === null || $zone->bounds_east === null || $zone->bounds_west === null) {
                return '';
            }

            return (string) json_encode([
                'bounds' => [
                    'north' => (float) $zone->bounds_north,
                    'south' => (float) $zone->bounds_south,
                    'east' => (float) $zone->bounds_east,
                    'west' => (float) $zone->bounds_west,
                ],
            ]);
        }

        if ($shapeType === 'POLYGON') {
            $decoded = json_decode((string) $zone->vertices_json, true);
            if (!is_array($decoded) || count($decoded) < 3) {
                return '';
            }

            return (string) json_encode([
                'vertices' => $decoded,
            ]);
        }

        return '';
    };
  @endphp

  <div class="row g-6">
    <div class="col-12 col-xl-8">
      <div class="card">
        <div class="card-header">
          <h5 class="card-title mb-0">Account Information</h5>
        </div>
        <div class="card-body">
          <form action="{{ route('admin.users.update', $user) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="row g-4">
              <div class="col-12">
                <label class="form-label" for="full_name">Full Name</label>
                <input
                  type="text"
                  id="full_name"
                  name="full_name"
                  class="form-control"
                  value="{{ old('full_name', $user->full_name) }}"
                  maxlength="120"
                  required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="username">Username</label>
                <input
                  type="text"
                  id="username"
                  name="username"
                  class="form-control"
                  value="{{ old('username', $user->username) }}"
                  maxlength="60"
                  required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="role">Role</label>
                <select id="role" name="role" class="form-select" required>
                  @foreach ($roleOptions as $roleValue => $roleLabel)
                    <option value="{{ $roleValue }}" @selected(old('role', $user->role?->value) === $roleValue)>{{ $roleLabel }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="password">New Password</label>
                <input type="password" id="password" name="password" class="form-control" minlength="8" />
                <div class="form-text">Leave blank to keep current password.</div>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="password_confirmation">Confirm New Password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" minlength="8" />
              </div>

              <div class="col-12">
                <hr class="my-1" />
              </div>

              <div class="col-12">
                <h6 class="mb-2">User Access Premises</h6>
                @if ($user->isAdmin())
                  <div class="alert alert-label-info mb-0">
                    Admin accounts bypass geofence restrictions. User-specific premises are not applied to admins.
                  </div>
                @else
                  <p class="text-muted text-sm mb-3">
                    Draw the specific premises where this user is allowed to access the system. This zone must be inside the general perimeter.
                  </p>

                  <div class="form-check form-switch mb-3">
                    <input
                      class="form-check-input"
                      type="checkbox"
                      role="switch"
                      id="user_premises_enabled"
                      name="user_premises_enabled"
                      value="1"
                      @checked($userPremisesEnabledValue) />
                    <label class="form-check-label" for="user_premises_enabled">Enable user-specific premises</label>
                  </div>

                  <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                      <label class="form-label" for="user_premises_shape_selector">Shape Type</label>
                      <select id="user_premises_shape_selector" class="form-select">
                        @foreach ($geofenceShapeOptions as $shapeValue => $shapeLabel)
                          <option value="{{ $shapeValue }}" @selected($userPremisesShapeTypeValue === $shapeValue)>{{ $shapeLabel }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="col-12 col-md-6 d-flex align-items-end">
                      <button type="button" id="user-premises-clear-btn" class="btn btn-outline-danger w-100">
                        Clear Drawn Premises
                      </button>
                    </div>
                  </div>

                  <input type="hidden" id="user_premises_shape_type" name="user_premises_shape_type" value="{{ $userPremisesShapeTypeValue }}" />
                  <input type="hidden" id="user_premises_geometry" name="user_premises_geometry" value="{{ $userPremisesGeometryValue }}" />

                  <div class="alert alert-label-info mb-3" role="alert">
                    <div class="fw-semibold mb-1">Drawing Guide</div>
                    <div class="small">
                      Use one shape only. Circle tool for circle, rectangle tool for rectangle/square, and polygon tool for polygon.
                      If shape is set to <strong>Square</strong>, the drawn rectangle is normalized to square proportions.
                    </div>
                  </div>

                  <div id="user-premises-map" aria-label="User premises drawing map"></div>
                  <script id="user_premises_map_payload" type="application/json">{!! $userPremisesMapPayloadJson !!}</script>
                  @error('user_premises_geometry')
                    <div class="text-danger small mt-2">{{ $message }}</div>
                  @enderror
                  <div id="user_premises_client_error" class="text-danger small mt-2 d-none"></div>
                @endif
              </div>

              @if ($user->isOwner())
                <div class="col-12">
                  <hr class="my-1" />
                </div>
                <div class="col-12">
                  <h6 class="mb-2">Owner Farm Location Information</h6>
                  <p class="text-muted text-sm mb-3">
                    Set latitude and longitude for each owned farm. Farm geolocations are only allowed inside the configured general geofence.
                  </p>

                  @if ($ownerFarms->isNotEmpty())
                    <div class="border rounded p-3 mb-3">
                      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div class="fw-semibold">Farm Fence Maker</div>
                        <div class="small text-body-secondary">One farm at a time. Draw only inside general geofence.</div>
                      </div>

                      @if (!($farmFencesMapPayload['general_geofence_configured'] ?? false))
                        <div class="alert alert-warning py-2 px-3 mb-3" role="alert">
                          Configure the general geofence first before saving farm fences.
                          <a href="{{ route('admin.settings.edit') }}" class="alert-link">Open Admin Settings</a>
                        </div>
                      @endif

                      <div class="row g-3 mb-3">
                        <div class="col-12 col-md-4">
                          <label class="form-label" for="farm_fence_selector">Select Farm</label>
                          <select id="farm_fence_selector" class="form-select">
                            @foreach ($ownerFarms as $farm)
                              <option value="{{ $farm->id }}">{{ $farm->farm_name }}</option>
                            @endforeach
                          </select>
                        </div>
                        <div class="col-12 col-md-3 d-flex align-items-end">
                          <div class="form-check form-switch mb-1">
                            <input class="form-check-input" type="checkbox" role="switch" id="farm_fence_enabled_toggle" />
                            <label class="form-check-label" for="farm_fence_enabled_toggle">Enable fence</label>
                          </div>
                        </div>
                        <div class="col-12 col-md-3">
                          <label class="form-label" for="farm_fence_shape_selector">Fence Shape</label>
                          <select id="farm_fence_shape_selector" class="form-select">
                            @foreach ($geofenceShapeOptions as $shapeValue => $shapeLabel)
                              <option value="{{ $shapeValue }}">{{ $shapeLabel }}</option>
                            @endforeach
                          </select>
                        </div>
                        <div class="col-12 col-md-2 d-flex align-items-end">
                          <button type="button" id="farm-fence-clear-btn" class="btn btn-outline-danger w-100">Clear</button>
                        </div>
                      </div>

                      <div id="farm_fence_hover_info" class="small text-body-secondary mb-2">Hover a marker or fence to view farm details.</div>
                      <div id="farm-fence-map" aria-label="Farm fence maker map"></div>
                      <script id="farm_fences_map_payload" type="application/json">{!! $farmFencesMapPayloadJson !!}</script>
                      <div id="farm_fence_client_error" class="text-danger small mt-2 d-none"></div>
                    </div>
                  @endif

                  @forelse ($ownerFarms as $farm)
                    @php
                      $farmFenceEnabledOld = old('farm_updates.' . $loop->index . '.fence_enabled');
                      $farmFenceEnabledValue = $farmFenceEnabledOld === null
                          ? ($farm->premisesZone && $farm->premisesZone->is_active ? '1' : '0')
                          : (string) $farmFenceEnabledOld;
                      $farmFenceShapeValue = old('farm_updates.' . $loop->index . '.fence_shape_type', $farm->premisesZone?->shape_type ?? 'CIRCLE');
                      $farmFenceGeometryValue = old('farm_updates.' . $loop->index . '.fence_geometry', $encodeFarmFenceGeometry($farm->premisesZone));
                    @endphp
                    <div class="border rounded p-3 mb-3">
                      <div class="fw-semibold mb-1">{{ $farm->farm_name }}</div>
                      <div class="text-body-secondary small mb-3">
                        {{ $farm->barangay ?: '-' }}, {{ $farm->municipality ?: '-' }}, {{ $farm->province ?: '-' }}
                      </div>

                      <input type="hidden" name="farm_updates[{{ $loop->index }}][id]" value="{{ $farm->id }}" />
                      <input
                        type="hidden"
                        id="farm_{{ $farm->id }}_fence_enabled"
                        name="farm_updates[{{ $loop->index }}][fence_enabled]"
                        value="{{ $farmFenceEnabledValue }}" />
                      <input
                        type="hidden"
                        id="farm_{{ $farm->id }}_fence_shape_type"
                        name="farm_updates[{{ $loop->index }}][fence_shape_type]"
                        value="{{ strtoupper((string) $farmFenceShapeValue) }}" />
                      <input
                        type="hidden"
                        id="farm_{{ $farm->id }}_fence_geometry"
                        name="farm_updates[{{ $loop->index }}][fence_geometry]"
                        value="{{ $farmFenceGeometryValue }}" />

                      <div class="row g-3">
                        <div class="col-12 col-md-6">
                          <label class="form-label" for="farm_{{ $farm->id }}_latitude">Latitude</label>
                          <input
                            type="number"
                            step="0.0000001"
                            min="-90"
                            max="90"
                            id="farm_{{ $farm->id }}_latitude"
                            name="farm_updates[{{ $loop->index }}][latitude]"
                            class="form-control"
                            value="{{ old('farm_updates.' . $loop->index . '.latitude', $farm->latitude) }}" />
                        </div>
                        <div class="col-12 col-md-6">
                          <label class="form-label" for="farm_{{ $farm->id }}_longitude">Longitude</label>
                          <input
                            type="number"
                            step="0.0000001"
                            min="-180"
                            max="180"
                            id="farm_{{ $farm->id }}_longitude"
                            name="farm_updates[{{ $loop->index }}][longitude]"
                            class="form-control"
                            value="{{ old('farm_updates.' . $loop->index . '.longitude', $farm->longitude) }}" />
                        </div>
                      </div>
                      <div id="farm_{{ $farm->id }}_location_client_error" class="text-danger small mt-2 d-none"></div>
                      @error('farm_updates.' . $loop->index . '.fence_shape_type')
                        <div class="text-danger small mt-2">{{ $message }}</div>
                      @enderror
                      @error('farm_updates.' . $loop->index . '.fence_geometry')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                      @enderror
                    </div>
                  @empty
                    <div class="text-body-secondary">This owner has no farm records yet.</div>
                  @endforelse
                </div>
              @endif

              <div class="col-12">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Back to List</a>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-4">
      <div class="card">
        <div class="card-header">
          <h5 class="card-title mb-0">Account Status</h5>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <div class="small text-body-secondary mb-1">Registration</div>
            @if ($user->isPendingApproval())
              <span class="badge bg-label-warning">Pending</span>
            @elseif ($user->isApproved())
              <span class="badge bg-label-success">Approved</span>
            @else
              <span class="badge bg-label-danger">Denied</span>
            @endif
          </div>

          @if ($user->isPendingApproval())
            <div class="d-flex flex-wrap gap-2 mb-3">
              <form method="POST" action="{{ route('admin.users.approve', $user) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn btn-outline-success btn-sm">Approve Registration</button>
              </form>
            </div>

            <form method="POST" action="{{ route('admin.users.deny', $user) }}" class="mb-3">
              @csrf
              @method('PATCH')
              <label class="form-label" for="denial_reason">Deny Reason</label>
              <textarea id="denial_reason" name="denial_reason" class="form-control" rows="2" maxlength="500" required></textarea>
              <button type="submit" class="btn btn-outline-danger btn-sm mt-2">Deny Registration</button>
            </form>
          @elseif ($user->isDenied() && $user->denial_reason)
            <p class="text-body-secondary mb-3">Reason: {{ $user->denial_reason }}</p>
          @endif

          <div class="mb-3">
            @if ($user->is_active)
              <span class="badge bg-label-success">Active</span>
            @else
              <span class="badge bg-label-danger">Deactivated</span>
            @endif
          </div>

          @if (! $user->is_active && $user->deactivated_at)
            <p class="text-body-secondary mb-3">Deactivated at: {{ \App\Support\AppTimezone::formatDateTime($user->deactivated_at, 'Y-m-d H:i') }}</p>
          @endif

          @if ($user->is_active)
            <form method="POST" action="{{ route('admin.users.deactivate', $user) }}">
              @csrf
              @method('PATCH')
              <button type="submit" class="btn btn-outline-danger" @disabled($user->id === auth()->id())>Deactivate User</button>
            </form>
            @if ($user->id === auth()->id())
              <div class="form-text">You cannot deactivate your own account.</div>
            @endif
          @else
            <form method="POST" action="{{ route('admin.users.reactivate', $user) }}">
              @csrf
              @method('PATCH')
              <button type="submit" class="btn btn-outline-success">Reactivate User</button>
            </form>
          @endif
        </div>
      </div>
    </div>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
  <script>
    (function() {
      var mapElement = document.getElementById('user-premises-map');
      var payloadElement = document.getElementById('user_premises_map_payload');
      var shapeSelector = document.getElementById('user_premises_shape_selector');
      var shapeInput = document.getElementById('user_premises_shape_type');
      var geometryInput = document.getElementById('user_premises_geometry');
      var enabledInput = document.getElementById('user_premises_enabled');
      var clearButton = document.getElementById('user-premises-clear-btn');
      var clientError = document.getElementById('user_premises_client_error');
      var form = mapElement ? mapElement.closest('form') : null;

      if (!mapElement || !shapeSelector || !shapeInput || !geometryInput || !form || typeof L === 'undefined') {
        return;
      }

      function parseJson(raw) {
        if (typeof raw !== 'string' || raw.trim() === '') {
          return null;
        }

        try {
          return JSON.parse(raw);
        } catch (error) {
          return null;
        }
      }

      function toNumber(value, fallback) {
        var parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : fallback;
      }

      function distanceMeters(latitudeA, longitudeA, latitudeB, longitudeB) {
        var earthRadiusMeters = 6371000.0;
        var latARad = latitudeA * Math.PI / 180.0;
        var latBRad = latitudeB * Math.PI / 180.0;
        var deltaLat = (latitudeB - latitudeA) * Math.PI / 180.0;
        var deltaLng = (longitudeB - longitudeA) * Math.PI / 180.0;
        var sinLat = Math.sin(deltaLat / 2.0);
        var sinLng = Math.sin(deltaLng / 2.0);
        var a = (sinLat * sinLat) + Math.cos(latARad) * Math.cos(latBRad) * (sinLng * sinLng);
        var c = 2.0 * Math.atan2(Math.sqrt(a), Math.sqrt(1.0 - a));
        return earthRadiusMeters * c;
      }

      function pointOnSegment(latitude, longitude, startLatitude, startLongitude, endLatitude, endLongitude) {
        var epsilon = 1.0e-10;

        if (
          latitude < Math.min(startLatitude, endLatitude) - epsilon ||
          latitude > Math.max(startLatitude, endLatitude) + epsilon ||
          longitude < Math.min(startLongitude, endLongitude) - epsilon ||
          longitude > Math.max(startLongitude, endLongitude) + epsilon
        ) {
          return false;
        }

        var crossProduct = ((latitude - startLatitude) * (endLongitude - startLongitude))
          - ((longitude - startLongitude) * (endLatitude - startLatitude));

        return Math.abs(crossProduct) <= epsilon;
      }

      function pointInPolygon(latitude, longitude, vertices) {
        if (!Array.isArray(vertices) || vertices.length < 3) {
          return false;
        }

        var inside = false;
        for (var i = 0, j = vertices.length - 1; i < vertices.length; j = i++) {
          var pointI = vertices[i];
          var pointJ = vertices[j];
          if (!Array.isArray(pointI) || !Array.isArray(pointJ) || pointI.length < 2 || pointJ.length < 2) {
            continue;
          }

          var latI = Number(pointI[0]);
          var lngI = Number(pointI[1]);
          var latJ = Number(pointJ[0]);
          var lngJ = Number(pointJ[1]);
          if (!Number.isFinite(latI) || !Number.isFinite(lngI) || !Number.isFinite(latJ) || !Number.isFinite(lngJ)) {
            continue;
          }

          if (pointOnSegment(latitude, longitude, latI, lngI, latJ, lngJ)) {
            return true;
          }

          var latIntersects = ((latI > latitude) !== (latJ > latitude));
          if (!latIntersects) {
            continue;
          }

          var slope = latJ - latI;
          if (Math.abs(slope) < 1.0e-12) {
            continue;
          }

          var intersectLng = ((lngJ - lngI) * (latitude - latI) / slope) + lngI;
          if (longitude < intersectLng) {
            inside = !inside;
          }
        }

        return inside;
      }

      function containsInGeometry(geometry, latitude, longitude) {
        if (!geometry || typeof geometry !== 'object') {
          return false;
        }

        var shapeType = normalizeShape(geometry.shape_type || 'CIRCLE');
        if (shapeType === 'CIRCLE') {
          var centerLatitude = toNumber(geometry.center_latitude, NaN);
          var centerLongitude = toNumber(geometry.center_longitude, NaN);
          var radiusMeters = toNumber(geometry.radius_meters, NaN);
          if (!Number.isFinite(centerLatitude) || !Number.isFinite(centerLongitude) || !Number.isFinite(radiusMeters)) {
            return false;
          }

          return distanceMeters(latitude, longitude, centerLatitude, centerLongitude) <= radiusMeters;
        }

        if (shapeType === 'RECTANGLE' || shapeType === 'SQUARE') {
          var bounds = geometry.bounds || geometry;
          var north = toNumber(bounds.north, NaN);
          var south = toNumber(bounds.south, NaN);
          var east = toNumber(bounds.east, NaN);
          var west = toNumber(bounds.west, NaN);
          if (!Number.isFinite(north) || !Number.isFinite(south) || !Number.isFinite(east) || !Number.isFinite(west)) {
            return false;
          }

          return latitude <= north && latitude >= south && longitude <= east && longitude >= west;
        }

        if (shapeType === 'POLYGON') {
          return pointInPolygon(latitude, longitude, Array.isArray(geometry.vertices) ? geometry.vertices : []);
        }

        return false;
      }

      function samplePointsForGeometry(geometry) {
        if (!geometry || typeof geometry !== 'object') {
          return [];
        }

        var shapeType = normalizeShape(geometry.shape_type || 'CIRCLE');
        if (shapeType === 'CIRCLE') {
          var centerLatitude = toNumber(geometry.center_latitude, NaN);
          var centerLongitude = toNumber(geometry.center_longitude, NaN);
          var radiusMeters = toNumber(geometry.radius_meters, NaN);
          if (!Number.isFinite(centerLatitude) || !Number.isFinite(centerLongitude) || !Number.isFinite(radiusMeters) || radiusMeters <= 0) {
            return [];
          }

          var latDelta = radiusMeters / 111320.0;
          var cosLatitude = Math.cos(centerLatitude * Math.PI / 180.0);
          var lngDelta = cosLatitude === 0 ? 0 : radiusMeters / (111320.0 * Math.max(0.000001, Math.abs(cosLatitude)));
          return [
            [centerLatitude, centerLongitude],
            [centerLatitude + latDelta, centerLongitude],
            [centerLatitude - latDelta, centerLongitude],
            [centerLatitude, centerLongitude + lngDelta],
            [centerLatitude, centerLongitude - lngDelta]
          ];
        }

        if (shapeType === 'RECTANGLE' || shapeType === 'SQUARE') {
          var bounds = geometry.bounds || geometry;
          var north = toNumber(bounds.north, NaN);
          var south = toNumber(bounds.south, NaN);
          var east = toNumber(bounds.east, NaN);
          var west = toNumber(bounds.west, NaN);
          if (!Number.isFinite(north) || !Number.isFinite(south) || !Number.isFinite(east) || !Number.isFinite(west)) {
            return [];
          }

          return [
            [north, east],
            [north, west],
            [south, east],
            [south, west],
            [(north + south) / 2.0, (east + west) / 2.0]
          ];
        }

        if (shapeType === 'POLYGON') {
          var vertices = Array.isArray(geometry.vertices) ? geometry.vertices : [];
          if (vertices.length < 3) {
            return [];
          }

          var sample = [];
          var latSum = 0;
          var lngSum = 0;
          var count = 0;

          vertices.forEach(function(point) {
            if (!Array.isArray(point) || point.length < 2) {
              return;
            }

            var lat = toNumber(point[0], NaN);
            var lng = toNumber(point[1], NaN);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
              return;
            }

            sample.push([lat, lng]);
            latSum += lat;
            lngSum += lng;
            count += 1;
          });

          if (count > 0) {
            sample.push([latSum / count, lngSum / count]);
          }

          return sample;
        }

        return [];
      }

      function isInsideGeneralPerimeter(geometry, geofenceGeometries) {
        if (!generalGeofenceConfigured || !Array.isArray(geofenceGeometries) || geofenceGeometries.length === 0) {
          return false;
        }

        var samplePoints = samplePointsForGeometry(geometry);
        if (samplePoints.length === 0) {
          return false;
        }

        for (var i = 0; i < samplePoints.length; i += 1) {
          var sample = samplePoints[i];
          var insideAnyGeometry = geofenceGeometries.some(function(geofenceGeometry) {
            return containsInGeometry(geofenceGeometry, Number(sample[0]), Number(sample[1]));
          });
          if (!insideAnyGeometry) {
            return false;
          }
        }

        return true;
      }

      function roundCoordinate(value) {
        return Math.round(Number(value) * 10000000) / 10000000;
      }

      function setClientError(message) {
        if (!clientError) {
          return;
        }

        if (!message) {
          clientError.textContent = '';
          clientError.classList.add('d-none');
          return;
        }

        clientError.textContent = message;
        clientError.classList.remove('d-none');
      }

      function normalizeBoundsObject(bounds) {
        return {
          north: roundCoordinate(bounds.getNorth()),
          south: roundCoordinate(bounds.getSouth()),
          east: roundCoordinate(bounds.getEast()),
          west: roundCoordinate(bounds.getWest())
        };
      }

      function squareBounds(boundsObject) {
        var centerLatitude = (boundsObject.north + boundsObject.south) / 2;
        var centerLongitude = (boundsObject.east + boundsObject.west) / 2;
        var span = Math.max(
          boundsObject.north - boundsObject.south,
          boundsObject.east - boundsObject.west
        );

        return {
          north: roundCoordinate(centerLatitude + (span / 2)),
          south: roundCoordinate(centerLatitude - (span / 2)),
          east: roundCoordinate(centerLongitude + (span / 2)),
          west: roundCoordinate(centerLongitude - (span / 2))
        };
      }

      function layerIsCircle(layer) {
        return layer instanceof L.Circle;
      }

      function layerIsRectangle(layer) {
        return layer instanceof L.Rectangle;
      }

      function layerIsPolygon(layer) {
        return layer instanceof L.Polygon && !(layer instanceof L.Rectangle);
      }

      function allowedShapes() {
        return ['CIRCLE', 'RECTANGLE', 'SQUARE', 'POLYGON'];
      }

      function normalizeShape(shape) {
        var normalized = String(shape || '').toUpperCase();
        return allowedShapes().indexOf(normalized) === -1 ? 'CIRCLE' : normalized;
      }

      function layerMatchesShape(shape, layer) {
        if (!layer) {
          return false;
        }

        if (shape === 'CIRCLE') {
          return layerIsCircle(layer);
        }

        if (shape === 'POLYGON') {
          return layerIsPolygon(layer);
        }

        if (shape === 'RECTANGLE' || shape === 'SQUARE') {
          return layerIsRectangle(layer);
        }

        return false;
      }

      function drawOptions(shape) {
        return {
          polyline: false,
          marker: false,
          circlemarker: false,
          polygon: shape === 'POLYGON' ? {
            allowIntersection: false,
            showArea: true,
            shapeOptions: {
              color: '#696cff',
              weight: 2,
              fillColor: '#696cff',
              fillOpacity: 0.14
            }
          } : false,
          rectangle: shape === 'RECTANGLE' || shape === 'SQUARE' ? {
            shapeOptions: {
              color: '#696cff',
              weight: 2,
              fillColor: '#696cff',
              fillOpacity: 0.14
            }
          } : false,
          circle: shape === 'CIRCLE' ? {
            shapeOptions: {
              color: '#696cff',
              weight: 2,
              fillColor: '#696cff',
              fillOpacity: 0.14
            }
          } : false
        };
      }

      function updateShapeInputs(shape) {
        var normalized = normalizeShape(shape || shapeSelector.value);
        shapeSelector.value = normalized;
        shapeInput.value = normalized;
      }

      var payload = parseJson(payloadElement ? payloadElement.textContent : '') || {};
      var defaultCenter = payload.default_center || {
        latitude: 10.354727,
        longitude: 124.965980
      };
      var mapCenter = payload.map_center || defaultCenter;
      var geofencePayload = payload.geofence && typeof payload.geofence === 'object' ? payload.geofence : {};
      var geofenceGeometries = Array.isArray(geofencePayload.geometries)
        ? geofencePayload.geometries.filter(function(geometry) {
          return geometry && typeof geometry === 'object';
        })
        : [];
      if (geofenceGeometries.length === 0 && geofencePayload.geometry && typeof geofencePayload.geometry === 'object') {
        geofenceGeometries = [geofencePayload.geometry];
      }
      var generalGeofenceConfigured = Boolean(payload.general_geofence_configured) && geofenceGeometries.length > 0;

      var map = L.map(mapElement, {
        zoomControl: true
      }).setView([
        toNumber(mapCenter.latitude, 10.354727),
        toNumber(mapCenter.longitude, 124.965980)
      ], 14);

      function buildClipPathFromGeometry(geometry) {
        if (!geometry || typeof geometry !== 'object') {
          return null;
        }

        var shapeType = normalizeShape(geometry.shape_type || 'CIRCLE');
        if (shapeType === 'CIRCLE') {
          var centerLatitude = toNumber(geometry.center_latitude, NaN);
          var centerLongitude = toNumber(geometry.center_longitude, NaN);
          var radiusMeters = toNumber(geometry.radius_meters, NaN);
          if (!Number.isFinite(centerLatitude) || !Number.isFinite(centerLongitude) || !Number.isFinite(radiusMeters) || radiusMeters <= 0) {
            return null;
          }

          var centerPoint = map.latLngToContainerPoint([centerLatitude, centerLongitude]);
          var latDelta = radiusMeters / 111320.0;
          var cosLatitude = Math.cos(centerLatitude * Math.PI / 180.0);
          var lngDelta = cosLatitude === 0 ? 0 : radiusMeters / (111320.0 * Math.max(0.000001, Math.abs(cosLatitude)));
          var edgePoint = map.latLngToContainerPoint([centerLatitude, centerLongitude + lngDelta]);
          var radiusPx = Math.max(2, Math.abs(edgePoint.x - centerPoint.x));

          return 'circle(' + radiusPx.toFixed(2) + 'px at ' + centerPoint.x.toFixed(2) + 'px ' + centerPoint.y.toFixed(2) + 'px)';
        }

        var polygonPoints = [];
        if (shapeType === 'RECTANGLE' || shapeType === 'SQUARE') {
          var bounds = geometry.bounds || geometry;
          var north = toNumber(bounds.north, NaN);
          var south = toNumber(bounds.south, NaN);
          var east = toNumber(bounds.east, NaN);
          var west = toNumber(bounds.west, NaN);
          if (!Number.isFinite(north) || !Number.isFinite(south) || !Number.isFinite(east) || !Number.isFinite(west)) {
            return null;
          }

          polygonPoints = [
            map.latLngToContainerPoint([north, west]),
            map.latLngToContainerPoint([north, east]),
            map.latLngToContainerPoint([south, east]),
            map.latLngToContainerPoint([south, west])
          ];
        } else if (shapeType === 'POLYGON') {
          var vertices = Array.isArray(geometry.vertices) ? geometry.vertices : [];
          polygonPoints = vertices.map(function(point) {
            if (!Array.isArray(point) || point.length < 2) {
              return null;
            }

            var lat = toNumber(point[0], NaN);
            var lng = toNumber(point[1], NaN);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
              return null;
            }

            return map.latLngToContainerPoint([lat, lng]);
          }).filter(function(point) {
            return point !== null;
          });
        }

        if (polygonPoints.length < 3) {
          return null;
        }

        return 'polygon(' + polygonPoints.map(function(point) {
          return point.x.toFixed(2) + 'px ' + point.y.toFixed(2) + 'px';
        }).join(', ') + ')';
      }

      function initializeContextTiles() {
        var tileConfig = {
          maxZoom: 19,
          attribution: '&copy; OpenStreetMap contributors'
        };

        function circleRingFromGeometry(centerLatitude, centerLongitude, radiusMeters) {
          var segments = 64;
          var latStep = radiusMeters / 111320.0;
          var cosLatitude = Math.cos(centerLatitude * Math.PI / 180.0);
          var lngStep = cosLatitude === 0
            ? 0
            : radiusMeters / (111320.0 * Math.max(0.000001, Math.abs(cosLatitude)));
          var points = [];

          for (var index = 0; index < segments; index += 1) {
            var angle = (Math.PI * 2 * index) / segments;
            points.push([
              centerLatitude + (latStep * Math.sin(angle)),
              centerLongitude + (lngStep * Math.cos(angle))
            ]);
          }

          return points;
        }

        function ringFromGeometry(geometry) {
          if (!geometry || typeof geometry !== 'object') {
            return null;
          }

          var shapeType = normalizeShape(geometry.shape_type || 'CIRCLE');
          if (shapeType === 'CIRCLE') {
            var centerLatitude = toNumber(geometry.center_latitude, NaN);
            var centerLongitude = toNumber(geometry.center_longitude, NaN);
            var radiusMeters = toNumber(geometry.radius_meters, NaN);
            if (!Number.isFinite(centerLatitude) || !Number.isFinite(centerLongitude) || !Number.isFinite(radiusMeters) || radiusMeters <= 0) {
              return null;
            }

            return circleRingFromGeometry(centerLatitude, centerLongitude, radiusMeters);
          }

          if (shapeType === 'RECTANGLE' || shapeType === 'SQUARE') {
            var bounds = geometry.bounds || geometry;
            var north = toNumber(bounds.north, NaN);
            var south = toNumber(bounds.south, NaN);
            var east = toNumber(bounds.east, NaN);
            var west = toNumber(bounds.west, NaN);
            if (!Number.isFinite(north) || !Number.isFinite(south) || !Number.isFinite(east) || !Number.isFinite(west)) {
              return null;
            }

            return [
              [north, west],
              [north, east],
              [south, east],
              [south, west]
            ];
          }

          if (shapeType === 'POLYGON') {
            var vertices = Array.isArray(geometry.vertices) ? geometry.vertices : [];
            var points = vertices.map(function(point) {
              if (!Array.isArray(point) || point.length < 2) {
                return null;
              }

              var lat = toNumber(point[0], NaN);
              var lng = toNumber(point[1], NaN);
              if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                return null;
              }

              return [lat, lng];
            }).filter(function(point) {
              return point !== null;
            });

            return points.length >= 3 ? points : null;
          }

          return null;
        }

        function addOutsideDimMask() {
          var holes = geofenceGeometries.map(ringFromGeometry).filter(function(points) {
            return Array.isArray(points) && points.length >= 3;
          });
          if (holes.length === 0) {
            return null;
          }

          var maskPaneName = 'premises-outside-mask';
          var existingPane = map.getPane(maskPaneName);
          var maskPane = existingPane || map.createPane(maskPaneName);
          maskPane.style.zIndex = '215';
          maskPane.style.pointerEvents = 'none';

          var worldRing = [
            [85, -179.999],
            [85, 179.999],
            [-85, 179.999],
            [-85, -179.999]
          ];

          var maskLayer = L.polygon([worldRing].concat(holes), {
            pane: maskPaneName,
            stroke: false,
            fillColor: '#ffffff',
            fillOpacity: 0.5,
            interactive: false
          });

          maskLayer.addTo(map);
          return maskLayer;
        }

        if (!generalGeofenceConfigured || geofenceGeometries.length !== 1) {
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', tileConfig).addTo(map);
          if (generalGeofenceConfigured && geofenceGeometries.length > 1) {
            addOutsideDimMask();
          }
          return function() {};
        }

        var blurredPaneName = 'premises-blurred-tiles';
        var focusPaneName = 'premises-focus-tiles';
        var blurredPane = map.createPane(blurredPaneName);
        var focusPane = map.createPane(focusPaneName);

        blurredPane.style.zIndex = '200';
        focusPane.style.zIndex = '210';
        blurredPane.style.filter = 'blur(2px)';
        blurredPane.style.opacity = '0.5';
        blurredPane.style.pointerEvents = 'none';
        focusPane.style.pointerEvents = 'none';

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', Object.assign({}, tileConfig, { pane: blurredPaneName })).addTo(map);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', Object.assign({}, tileConfig, { pane: focusPaneName })).addTo(map);

        function applyFocusClipPath() {
          var geofenceGeometry = geofenceGeometries[0];
          var clipPath = buildClipPathFromGeometry(geofenceGeometry);
          if (!clipPath) {
            focusPane.style.clipPath = 'none';
            focusPane.style.webkitClipPath = 'none';
            return;
          }

          focusPane.style.clipPath = clipPath;
          focusPane.style.webkitClipPath = clipPath;
        }

        map.on('move zoom resize', applyFocusClipPath);
        setTimeout(applyFocusClipPath, 10);

        return applyFocusClipPath;
      }

      var refreshTileFocusClip = initializeContextTiles();

      var drawnItems = new L.FeatureGroup();
      map.addLayer(drawnItems);

      var activeLayer = null;
      var drawControl = null;
      var geofenceReferenceLayers = [];
      var liveOutOfBoundsMessage = 'User premises is beyond the general perimeter.';
      var missingGeneralFenceMessage = 'Configure the general geofence perimeter first before setting user premises.';

      function setActiveLayerValidityState(isValid) {
        if (mapElement) {
          mapElement.classList.toggle('map-outside-geofence', !isValid);
        }

        if (activeLayer && typeof activeLayer.setStyle === 'function') {
          activeLayer.setStyle({
            color: isValid ? '#696cff' : '#ff3e1d',
            weight: 2,
            fillColor: isValid ? '#696cff' : '#ff3e1d',
            fillOpacity: isValid ? 0.14 : 0.18
          });
        }
      }

      function activeGeometryFromInputs() {
        var decoded = parseJson(geometryInput.value);
        if (!decoded || typeof decoded !== 'object') {
          return null;
        }

        var geometry = Object.assign({}, decoded);
        geometry.shape_type = normalizeShape(shapeInput.value);
        return geometry;
      }

      function clearActiveLayer() {
        drawnItems.clearLayers();
        activeLayer = null;
        geometryInput.value = '';
        setActiveLayerValidityState(true);
      }

      function validateActiveLayerAgainstGeneralPerimeter() {
        if (!enabledInput || !enabledInput.checked || !activeLayer) {
          setActiveLayerValidityState(true);
          return true;
        }

        if (!generalGeofenceConfigured || geofenceGeometries.length === 0) {
          setActiveLayerValidityState(false);
          setClientError(missingGeneralFenceMessage);
          return false;
        }

        var activeGeometry = activeGeometryFromInputs();
        if (!activeGeometry) {
          setActiveLayerValidityState(false);
          setClientError('Please draw and save a user premises zone before saving changes.');
          return false;
        }

        var isInside = isInsideGeneralPerimeter(activeGeometry, geofenceGeometries);
        setActiveLayerValidityState(isInside);

        if (!isInside) {
          setClientError(liveOutOfBoundsMessage);
          return false;
        }

        if (clientError && (
          clientError.textContent === liveOutOfBoundsMessage
          || clientError.textContent === missingGeneralFenceMessage
        )) {
          setClientError('');
        }

        return true;
      }

      function syncGeometryInput() {
        updateShapeInputs();
        var shape = normalizeShape(shapeInput.value);

        if (!activeLayer) {
          geometryInput.value = '';
          return;
        }

        if (shape === 'CIRCLE') {
          if (!layerIsCircle(activeLayer)) {
            clearActiveLayer();
            return;
          }

          var center = activeLayer.getLatLng();
          geometryInput.value = JSON.stringify({
            center_latitude: roundCoordinate(center.lat),
            center_longitude: roundCoordinate(center.lng),
            radius_meters: Math.max(25, Math.round(activeLayer.getRadius()))
          });
          return;
        }

        if (shape === 'RECTANGLE' || shape === 'SQUARE') {
          if (!layerIsRectangle(activeLayer)) {
            clearActiveLayer();
            return;
          }

          var boundsObject = normalizeBoundsObject(activeLayer.getBounds());
          if (shape === 'SQUARE') {
            boundsObject = squareBounds(boundsObject);
            activeLayer.setBounds([
              [boundsObject.south, boundsObject.west],
              [boundsObject.north, boundsObject.east]
            ]);
          }

          geometryInput.value = JSON.stringify({
            bounds: boundsObject
          });
          return;
        }

        if (shape === 'POLYGON') {
          if (!layerIsPolygon(activeLayer)) {
            clearActiveLayer();
            return;
          }

          var rings = activeLayer.getLatLngs();
          var outerRing = Array.isArray(rings) && Array.isArray(rings[0]) ? rings[0] : [];
          var vertices = outerRing.map(function(latLng) {
            return [roundCoordinate(latLng.lat), roundCoordinate(latLng.lng)];
          });

          if (vertices.length < 3) {
            geometryInput.value = '';
            return;
          }

          geometryInput.value = JSON.stringify({
            vertices: vertices
          });
          return;
        }

        geometryInput.value = '';
      }

      function setActiveLayer(layer, fitToLayer) {
        drawnItems.clearLayers();
        activeLayer = layer;
        drawnItems.addLayer(layer);
        syncGeometryInput();
        validateActiveLayerAgainstGeneralPerimeter();

        if (fitToLayer !== false && typeof layer.getBounds === 'function') {
          var bounds = layer.getBounds();
          if (bounds && bounds.isValid && bounds.isValid()) {
            map.fitBounds(bounds, {
              padding: [20, 20]
            });
          }
        }
      }

      function buildLayerFromGeometry(shape, geometry) {
        if (!geometry || typeof geometry !== 'object') {
          return null;
        }

        if (shape === 'CIRCLE') {
          var latitude = toNumber(geometry.center_latitude, NaN);
          var longitude = toNumber(geometry.center_longitude, NaN);
          var radius = toNumber(geometry.radius_meters, NaN);

          if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || !Number.isFinite(radius) || radius <= 0) {
            return null;
          }

          return L.circle([latitude, longitude], {
            radius: radius
          });
        }

        if (shape === 'RECTANGLE' || shape === 'SQUARE') {
          var bounds = geometry.bounds || geometry;
          var north = toNumber(bounds.north, NaN);
          var south = toNumber(bounds.south, NaN);
          var east = toNumber(bounds.east, NaN);
          var west = toNumber(bounds.west, NaN);

          if (!Number.isFinite(north) || !Number.isFinite(south) || !Number.isFinite(east) || !Number.isFinite(west) || north <= south || east <= west) {
            return null;
          }

          if (shape === 'SQUARE') {
            bounds = squareBounds({
              north: north,
              south: south,
              east: east,
              west: west
            });
          } else {
            bounds = {
              north: north,
              south: south,
              east: east,
              west: west
            };
          }

          return L.rectangle([
            [bounds.south, bounds.west],
            [bounds.north, bounds.east]
          ]);
        }

        if (shape === 'POLYGON') {
          var vertices = Array.isArray(geometry.vertices) ? geometry.vertices : [];
          if (vertices.length < 3) {
            return null;
          }

          var points = vertices.map(function(vertex) {
            if (!Array.isArray(vertex) || vertex.length < 2) {
              return null;
            }

            var lat = toNumber(vertex[0], NaN);
            var lng = toNumber(vertex[1], NaN);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
              return null;
            }

            return [lat, lng];
          }).filter(function(point) {
            return point !== null;
          });

          return points.length >= 3 ? L.polygon(points) : null;
        }

        return null;
      }

      function updateControlState() {
        var premisesEnabled = !enabledInput || enabledInput.checked;
        var canDraw = generalGeofenceConfigured && premisesEnabled;

        shapeSelector.disabled = !canDraw;
        if (clearButton) {
          clearButton.disabled = !generalGeofenceConfigured;
        }

        return canDraw;
      }

      function renderGeneralGeofenceReference() {
        geofenceReferenceLayers.forEach(function(layer) {
          map.removeLayer(layer);
        });
        geofenceReferenceLayers = [];

        geofenceGeometries.forEach(function(geofenceGeometry) {
          var referenceShape = normalizeShape(geofenceGeometry.shape_type || 'CIRCLE');
          var referenceLayer = buildLayerFromGeometry(referenceShape, geofenceGeometry);
          if (!referenceLayer) {
            return;
          }

          if (typeof referenceLayer.setStyle === 'function') {
            referenceLayer.setStyle({
              color: '#696cff',
              dashArray: '6 4',
              weight: 2,
              fillColor: '#696cff',
              fillOpacity: 0.08
            });
          }

          referenceLayer.addTo(map);
          if (typeof referenceLayer.bringToBack === 'function') {
            referenceLayer.bringToBack();
          }
          geofenceReferenceLayers.push(referenceLayer);
        });
      }

      function refreshDrawControl() {
        updateShapeInputs();

        if (drawControl) {
          map.removeControl(drawControl);
        }

        if (!updateControlState()) {
          drawControl = null;
          return;
        }

        drawControl = new L.Control.Draw({
          draw: drawOptions(normalizeShape(shapeInput.value)),
          edit: {
            featureGroup: drawnItems,
            edit: true,
            remove: false
          }
        });

        map.addControl(drawControl);
      }

      map.on(L.Draw.Event.CREATED, function(event) {
        setClientError('');
        updateShapeInputs();

        if (!layerMatchesShape(shapeInput.value, event.layer)) {
          clearActiveLayer();
          setClientError('Selected shape and drawn perimeter do not match. Please draw the selected shape.');
          return;
        }

        setActiveLayer(event.layer);
        validateActiveLayerAgainstGeneralPerimeter();
      });

      map.on(L.Draw.Event.EDITED, function() {
        syncGeometryInput();
        validateActiveLayerAgainstGeneralPerimeter();
      });

      shapeSelector.addEventListener('change', function() {
        setClientError('');
        updateShapeInputs(shapeSelector.value);

        if (activeLayer && !layerMatchesShape(shapeInput.value, activeLayer)) {
          clearActiveLayer();
        }

        syncGeometryInput();
        refreshDrawControl();
        validateActiveLayerAgainstGeneralPerimeter();
      });

      if (enabledInput) {
        enabledInput.addEventListener('change', function() {
          setClientError('');
          refreshDrawControl();
          validateActiveLayerAgainstGeneralPerimeter();
        });
      }

      if (clearButton) {
        clearButton.addEventListener('click', function() {
          setClientError('');
          clearActiveLayer();
          validateActiveLayerAgainstGeneralPerimeter();
        });
      }

      form.addEventListener('submit', function(event) {
        updateShapeInputs();
        syncGeometryInput();

        if (!enabledInput || !enabledInput.checked) {
          setClientError('');
          setActiveLayerValidityState(true);
          return;
        }

        if (!generalGeofenceConfigured) {
          event.preventDefault();
          setClientError(missingGeneralFenceMessage);
          mapElement.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
          });
          return;
        }

        if (!validateActiveLayerAgainstGeneralPerimeter()) {
          event.preventDefault();
          mapElement.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
          });
          return;
        }

        if (!geometryInput.value) {
          event.preventDefault();
          setClientError('Please draw and save a user premises zone before saving changes.');
          mapElement.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
          });
        }
      });

      var savedShape = normalizeShape(shapeInput.value || shapeSelector.value);
      var savedGeometry = parseJson(geometryInput.value);
      var payloadGeometry = payload.geometry && typeof payload.geometry === 'object'
        ? payload.geometry
        : null;

      if ((!savedGeometry || typeof savedGeometry !== 'object') && payloadGeometry) {
        savedGeometry = payloadGeometry;
      }

      if (savedGeometry && typeof savedGeometry === 'object' && savedGeometry.shape_type) {
        savedShape = normalizeShape(savedGeometry.shape_type);
      } else if (payloadGeometry && payloadGeometry.shape_type && !shapeInput.value) {
        savedShape = normalizeShape(payloadGeometry.shape_type);
      }

      updateShapeInputs(savedShape);
      renderGeneralGeofenceReference();
      refreshDrawControl();

      var initialLayer = buildLayerFromGeometry(savedShape, savedGeometry);
      if (initialLayer) {
        setActiveLayer(initialLayer);
      } else {
        geometryInput.value = '';
        var geofenceBounds = L.latLngBounds([]);
        geofenceReferenceLayers.forEach(function(layer) {
          if (typeof layer.getBounds !== 'function') {
            return;
          }
          var layerBounds = layer.getBounds();
          if (layerBounds && layerBounds.isValid && layerBounds.isValid()) {
            geofenceBounds.extend(layerBounds);
          }
        });
        if (geofenceBounds.isValid()) {
          map.fitBounds(geofenceBounds, {
            padding: [20, 20]
          });
        }
      }

      if (enabledInput && enabledInput.checked && !generalGeofenceConfigured) {
        setClientError(missingGeneralFenceMessage);
      }

      validateActiveLayerAgainstGeneralPerimeter();

      setTimeout(function() {
        map.invalidateSize();
        refreshTileFocusClip();
      }, 100);
    })();
  </script>
  <script>
    (function() {
      var mapElement = document.getElementById('farm-fence-map');
      var payloadElement = document.getElementById('farm_fences_map_payload');
      var farmSelector = document.getElementById('farm_fence_selector');
      var enabledToggle = document.getElementById('farm_fence_enabled_toggle');
      var shapeSelector = document.getElementById('farm_fence_shape_selector');
      var clearButton = document.getElementById('farm-fence-clear-btn');
      var hoverInfo = document.getElementById('farm_fence_hover_info');
      var clientError = document.getElementById('farm_fence_client_error');
      var form = mapElement ? mapElement.closest('form') : null;

      if (
        !mapElement ||
        !payloadElement ||
        !farmSelector ||
        !enabledToggle ||
        !shapeSelector ||
        !clearButton ||
        !form ||
        typeof L === 'undefined'
      ) {
        return;
      }

      function parseJson(raw) {
        if (typeof raw !== 'string' || raw.trim() === '') {
          return null;
        }

        try {
          return JSON.parse(raw);
        } catch (error) {
          return null;
        }
      }

      function toNumber(value, fallback) {
        var parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : fallback;
      }

      function roundCoordinate(value) {
        return Math.round(Number(value) * 10000000) / 10000000;
      }

      function setClientError(message) {
        if (!clientError) {
          return;
        }

        if (!message) {
          clientError.textContent = '';
          clientError.classList.add('d-none');
          return;
        }

        clientError.textContent = message;
        clientError.classList.remove('d-none');
      }

      function setHoverInfo(message) {
        if (hoverInfo) {
          hoverInfo.textContent = message;
        }
      }

      function escapeHtml(value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function allowedShapes() {
        return ['CIRCLE', 'RECTANGLE', 'SQUARE', 'POLYGON'];
      }

      function normalizeShape(shape) {
        var normalized = String(shape || '').toUpperCase();
        return allowedShapes().indexOf(normalized) === -1 ? 'CIRCLE' : normalized;
      }

      function distanceMeters(latitudeA, longitudeA, latitudeB, longitudeB) {
        var earthRadiusMeters = 6371000.0;
        var latARad = latitudeA * Math.PI / 180.0;
        var latBRad = latitudeB * Math.PI / 180.0;
        var deltaLat = (latitudeB - latitudeA) * Math.PI / 180.0;
        var deltaLng = (longitudeB - longitudeA) * Math.PI / 180.0;
        var sinLat = Math.sin(deltaLat / 2.0);
        var sinLng = Math.sin(deltaLng / 2.0);
        var a = (sinLat * sinLat) + Math.cos(latARad) * Math.cos(latBRad) * (sinLng * sinLng);
        var c = 2.0 * Math.atan2(Math.sqrt(a), Math.sqrt(1.0 - a));
        return earthRadiusMeters * c;
      }

      function pointOnSegment(latitude, longitude, startLatitude, startLongitude, endLatitude, endLongitude) {
        var epsilon = 1.0e-10;

        if (
          latitude < Math.min(startLatitude, endLatitude) - epsilon ||
          latitude > Math.max(startLatitude, endLatitude) + epsilon ||
          longitude < Math.min(startLongitude, endLongitude) - epsilon ||
          longitude > Math.max(startLongitude, endLongitude) + epsilon
        ) {
          return false;
        }

        var crossProduct = ((latitude - startLatitude) * (endLongitude - startLongitude))
          - ((longitude - startLongitude) * (endLatitude - startLatitude));

        return Math.abs(crossProduct) <= epsilon;
      }

      function pointInPolygon(latitude, longitude, vertices) {
        if (!Array.isArray(vertices) || vertices.length < 3) {
          return false;
        }

        var inside = false;
        for (var i = 0, j = vertices.length - 1; i < vertices.length; j = i++) {
          var pointI = vertices[i];
          var pointJ = vertices[j];
          if (!Array.isArray(pointI) || !Array.isArray(pointJ) || pointI.length < 2 || pointJ.length < 2) {
            continue;
          }

          var latI = Number(pointI[0]);
          var lngI = Number(pointI[1]);
          var latJ = Number(pointJ[0]);
          var lngJ = Number(pointJ[1]);
          if (!Number.isFinite(latI) || !Number.isFinite(lngI) || !Number.isFinite(latJ) || !Number.isFinite(lngJ)) {
            continue;
          }

          if (pointOnSegment(latitude, longitude, latI, lngI, latJ, lngJ)) {
            return true;
          }

          var latIntersects = ((latI > latitude) !== (latJ > latitude));
          if (!latIntersects) {
            continue;
          }

          var slope = latJ - latI;
          if (Math.abs(slope) < 1.0e-12) {
            continue;
          }

          var intersectLng = ((lngJ - lngI) * (latitude - latI) / slope) + lngI;
          if (longitude < intersectLng) {
            inside = !inside;
          }
        }

        return inside;
      }

      function containsInGeometry(geometry, latitude, longitude) {
        if (!geometry || typeof geometry !== 'object') {
          return false;
        }

        var shapeType = normalizeShape(geometry.shape_type || 'CIRCLE');
        if (shapeType === 'CIRCLE') {
          var centerLatitude = toNumber(geometry.center_latitude, NaN);
          var centerLongitude = toNumber(geometry.center_longitude, NaN);
          var radiusMeters = toNumber(geometry.radius_meters, NaN);
          if (!Number.isFinite(centerLatitude) || !Number.isFinite(centerLongitude) || !Number.isFinite(radiusMeters)) {
            return false;
          }

          return distanceMeters(latitude, longitude, centerLatitude, centerLongitude) <= radiusMeters;
        }

        if (shapeType === 'RECTANGLE' || shapeType === 'SQUARE') {
          var bounds = geometry.bounds || geometry;
          var north = toNumber(bounds.north, NaN);
          var south = toNumber(bounds.south, NaN);
          var east = toNumber(bounds.east, NaN);
          var west = toNumber(bounds.west, NaN);
          if (!Number.isFinite(north) || !Number.isFinite(south) || !Number.isFinite(east) || !Number.isFinite(west)) {
            return false;
          }

          return latitude <= north && latitude >= south && longitude <= east && longitude >= west;
        }

        if (shapeType === 'POLYGON') {
          return pointInPolygon(latitude, longitude, Array.isArray(geometry.vertices) ? geometry.vertices : []);
        }

        return false;
      }

      function shapeLabel(shape) {
        var normalized = normalizeShape(shape);
        if (normalized === 'RECTANGLE') {
          return 'Rectangle';
        }
        if (normalized === 'SQUARE') {
          return 'Square';
        }
        if (normalized === 'POLYGON') {
          return 'Polygon';
        }
        return 'Circle';
      }

      function layerIsCircle(layer) {
        return layer instanceof L.Circle;
      }

      function layerIsRectangle(layer) {
        return layer instanceof L.Rectangle;
      }

      function layerIsPolygon(layer) {
        return layer instanceof L.Polygon && !(layer instanceof L.Rectangle);
      }

      function layerMatchesShape(shape, layer) {
        if (!layer) {
          return false;
        }

        if (shape === 'CIRCLE') {
          return layerIsCircle(layer);
        }

        if (shape === 'RECTANGLE' || shape === 'SQUARE') {
          return layerIsRectangle(layer);
        }

        if (shape === 'POLYGON') {
          return layerIsPolygon(layer);
        }

        return false;
      }

      function normalizeBoundsObject(bounds) {
        return {
          north: roundCoordinate(bounds.getNorth()),
          south: roundCoordinate(bounds.getSouth()),
          east: roundCoordinate(bounds.getEast()),
          west: roundCoordinate(bounds.getWest())
        };
      }

      function squareBounds(boundsObject) {
        var centerLatitude = (boundsObject.north + boundsObject.south) / 2;
        var centerLongitude = (boundsObject.east + boundsObject.west) / 2;
        var span = Math.max(
          boundsObject.north - boundsObject.south,
          boundsObject.east - boundsObject.west
        );

        return {
          north: roundCoordinate(centerLatitude + (span / 2)),
          south: roundCoordinate(centerLatitude - (span / 2)),
          east: roundCoordinate(centerLongitude + (span / 2)),
          west: roundCoordinate(centerLongitude - (span / 2))
        };
      }

      function drawOptions(shape) {
        return {
          polyline: false,
          marker: false,
          circlemarker: false,
          polygon: shape === 'POLYGON' ? {
            allowIntersection: false,
            showArea: true,
            shapeOptions: {
              color: '#ff9f43',
              weight: 2,
              fillColor: '#ff9f43',
              fillOpacity: 0.16
            }
          } : false,
          rectangle: shape === 'RECTANGLE' || shape === 'SQUARE' ? {
            shapeOptions: {
              color: '#ff9f43',
              weight: 2,
              fillColor: '#ff9f43',
              fillOpacity: 0.16
            }
          } : false,
          circle: shape === 'CIRCLE' ? {
            shapeOptions: {
              color: '#ff9f43',
              weight: 2,
              fillColor: '#ff9f43',
              fillOpacity: 0.16
            }
          } : false
        };
      }

      function buildLayerFromGeometry(shape, geometry, style) {
        if (!geometry || typeof geometry !== 'object') {
          return null;
        }

        var layer = null;
        var normalizedShape = normalizeShape(shape || geometry.shape_type);

        if (normalizedShape === 'CIRCLE') {
          var latitude = toNumber(geometry.center_latitude, NaN);
          var longitude = toNumber(geometry.center_longitude, NaN);
          var radius = toNumber(geometry.radius_meters, NaN);
          if (Number.isFinite(latitude) && Number.isFinite(longitude) && Number.isFinite(radius) && radius > 0) {
            layer = L.circle([latitude, longitude], {
              radius: radius
            });
          }
        } else if (normalizedShape === 'RECTANGLE' || normalizedShape === 'SQUARE') {
          var bounds = geometry.bounds || geometry;
          var north = toNumber(bounds.north, NaN);
          var south = toNumber(bounds.south, NaN);
          var east = toNumber(bounds.east, NaN);
          var west = toNumber(bounds.west, NaN);
          if (Number.isFinite(north) && Number.isFinite(south) && Number.isFinite(east) && Number.isFinite(west) && north > south && east > west) {
            var normalizedBounds = normalizedShape === 'SQUARE'
              ? squareBounds({
                north: north,
                south: south,
                east: east,
                west: west
              })
              : {
                north: north,
                south: south,
                east: east,
                west: west
              };
            layer = L.rectangle([
              [normalizedBounds.south, normalizedBounds.west],
              [normalizedBounds.north, normalizedBounds.east]
            ]);
          }
        } else if (normalizedShape === 'POLYGON') {
          var vertices = Array.isArray(geometry.vertices) ? geometry.vertices : [];
          var points = vertices.map(function(vertex) {
            if (!Array.isArray(vertex) || vertex.length < 2) {
              return null;
            }
            var lat = toNumber(vertex[0], NaN);
            var lng = toNumber(vertex[1], NaN);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
              return null;
            }
            return [lat, lng];
          }).filter(function(point) {
            return point !== null;
          });

          if (points.length >= 3) {
            layer = L.polygon(points);
          }
        }

        if (layer && style && typeof layer.setStyle === 'function') {
          layer.setStyle(style);
        }

        return layer;
      }

      var payload = parseJson(payloadElement.textContent || '') || {};
      var farms = Array.isArray(payload.farms) ? payload.farms : [];
      if (farms.length === 0) {
        return;
      }

      var farmLookup = {};
      farms.forEach(function(farm) {
        farmLookup[String(farm.id)] = farm;
      });

      var geofencePayload = payload.geofence && typeof payload.geofence === 'object' ? payload.geofence : {};
      var geofenceGeometries = Array.isArray(geofencePayload.geometries)
        ? geofencePayload.geometries.filter(function(geometry) {
          return geometry && typeof geometry === 'object';
        })
        : [];
      if (geofenceGeometries.length === 0 && geofencePayload.geometry && typeof geofencePayload.geometry === 'object') {
        geofenceGeometries = [geofencePayload.geometry];
      }
      var generalGeofenceConfigured = Boolean(payload.general_geofence_configured) && geofenceGeometries.length > 0;
      var defaultCenter = payload.default_center || {
        latitude: 10.354727,
        longitude: 124.965980
      };
      var mapCenter = payload.map_center || defaultCenter;

      var map = L.map(mapElement, {
        zoomControl: true
      }).setView([
        toNumber(mapCenter.latitude, 10.354727),
        toNumber(mapCenter.longitude, 124.965980)
      ], 14);

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
      }).addTo(map);

      var staticLayers = new L.FeatureGroup();
      var drawnItems = new L.FeatureGroup();
      map.addLayer(staticLayers);
      map.addLayer(drawnItems);

      var activeLayer = null;
      var drawControl = null;
      var activeFarmId = String(farmSelector.value || farms[0].id);

      if (!farmLookup[activeFarmId]) {
        activeFarmId = String(farms[0].id);
        farmSelector.value = activeFarmId;
      }

      function farmInputs(farmId) {
        return {
          enabled: document.getElementById('farm_' + farmId + '_fence_enabled'),
          shape: document.getElementById('farm_' + farmId + '_fence_shape_type'),
          geometry: document.getElementById('farm_' + farmId + '_fence_geometry'),
          latitude: document.getElementById('farm_' + farmId + '_latitude'),
          longitude: document.getElementById('farm_' + farmId + '_longitude'),
          locationError: document.getElementById('farm_' + farmId + '_location_client_error')
        };
      }

      function containsInAnyGeneralGeofence(latitude, longitude) {
        if (!generalGeofenceConfigured || geofenceGeometries.length === 0) {
          return false;
        }

        return geofenceGeometries.some(function(geofenceGeometry) {
          return containsInGeometry(geofenceGeometry, latitude, longitude);
        });
      }

      function setFarmLocationErrorState(farmId, message) {
        var inputs = farmInputs(farmId);
        var latitudeInput = inputs.latitude;
        var longitudeInput = inputs.longitude;
        var errorElement = inputs.locationError;
        var hasError = typeof message === 'string' && message.trim() !== '';

        if (latitudeInput) {
          latitudeInput.classList.toggle('is-invalid', hasError);
        }
        if (longitudeInput) {
          longitudeInput.classList.toggle('is-invalid', hasError);
        }

        if (errorElement) {
          if (hasError) {
            errorElement.textContent = message;
            errorElement.classList.remove('d-none');
          } else {
            errorElement.textContent = '';
            errorElement.classList.add('d-none');
          }
        }
      }

      function validateFarmLocationInputs(farmId) {
        var inputs = farmInputs(farmId);
        if (!inputs.latitude || !inputs.longitude) {
          return true;
        }

        var latitudeRaw = String(inputs.latitude.value || '').trim();
        var longitudeRaw = String(inputs.longitude.value || '').trim();

        if (latitudeRaw === '' && longitudeRaw === '') {
          setFarmLocationErrorState(farmId, '');
          return true;
        }

        if (latitudeRaw === '' || longitudeRaw === '') {
          setFarmLocationErrorState(farmId, 'Both latitude and longitude are required together.');
          return false;
        }

        var latitude = toNumber(latitudeRaw, NaN);
        var longitude = toNumber(longitudeRaw, NaN);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
          setFarmLocationErrorState(farmId, 'Enter valid numeric coordinates.');
          return false;
        }

        if (generalGeofenceConfigured && !containsInAnyGeneralGeofence(latitude, longitude)) {
          setFarmLocationErrorState(farmId, 'Farm geolocation is outside the general geofence.');
          return false;
        }

        setFarmLocationErrorState(farmId, '');
        return true;
      }

      function getFarmState(farmId) {
        var farm = farmLookup[String(farmId)];
        var inputs = farmInputs(farmId);
        var fallbackFence = farm && farm.fence && typeof farm.fence === 'object' ? farm.fence : {};
        var enabled = inputs.enabled
          ? String(inputs.enabled.value || '0') === '1'
          : Boolean(fallbackFence.enabled);
        var shape = inputs.shape
          ? normalizeShape(inputs.shape.value)
          : normalizeShape(fallbackFence.shape_type);
        var geometry = inputs.geometry ? parseJson(inputs.geometry.value) : null;
        if ((!geometry || typeof geometry !== 'object') && fallbackFence.geometry && typeof fallbackFence.geometry === 'object') {
          geometry = fallbackFence.geometry;
        }

        return {
          enabled: enabled,
          shape: shape,
          geometry: geometry
        };
      }

      function setActiveFarmHiddenFields() {
        var inputs = farmInputs(activeFarmId);
        if (!inputs.enabled || !inputs.shape || !inputs.geometry) {
          return;
        }

        var shape = normalizeShape(shapeSelector.value);
        inputs.enabled.value = enabledToggle.checked ? '1' : '0';
        inputs.shape.value = shape;

        if (!enabledToggle.checked || !activeLayer) {
          inputs.geometry.value = '';
          return;
        }

        if (shape === 'CIRCLE') {
          if (!layerIsCircle(activeLayer)) {
            inputs.geometry.value = '';
            return;
          }

          var center = activeLayer.getLatLng();
          inputs.geometry.value = JSON.stringify({
            center_latitude: roundCoordinate(center.lat),
            center_longitude: roundCoordinate(center.lng),
            radius_meters: Math.max(25, Math.round(activeLayer.getRadius()))
          });
          return;
        }

        if (shape === 'RECTANGLE' || shape === 'SQUARE') {
          if (!layerIsRectangle(activeLayer)) {
            inputs.geometry.value = '';
            return;
          }

          var boundsObject = normalizeBoundsObject(activeLayer.getBounds());
          if (shape === 'SQUARE') {
            boundsObject = squareBounds(boundsObject);
            activeLayer.setBounds([
              [boundsObject.south, boundsObject.west],
              [boundsObject.north, boundsObject.east]
            ]);
          }

          inputs.geometry.value = JSON.stringify({
            bounds: boundsObject
          });
          return;
        }

        if (shape === 'POLYGON') {
          if (!layerIsPolygon(activeLayer)) {
            inputs.geometry.value = '';
            return;
          }

          var rings = activeLayer.getLatLngs();
          var outerRing = Array.isArray(rings) && Array.isArray(rings[0]) ? rings[0] : [];
          var vertices = outerRing.map(function(latLng) {
            return [roundCoordinate(latLng.lat), roundCoordinate(latLng.lng)];
          });

          inputs.geometry.value = vertices.length >= 3
            ? JSON.stringify({ vertices: vertices })
            : '';
          return;
        }

        inputs.geometry.value = '';
      }

      function clearActiveLayer() {
        drawnItems.clearLayers();
        activeLayer = null;
        setActiveFarmHiddenFields();
      }

      function setActiveLayer(layer, fitToLayer) {
        drawnItems.clearLayers();
        activeLayer = layer;
        if (typeof layer.setStyle === 'function') {
          layer.setStyle({
            color: '#ff9f43',
            weight: 2,
            fillColor: '#ff9f43',
            fillOpacity: 0.16
          });
        }
        drawnItems.addLayer(layer);
        setActiveFarmHiddenFields();

        if (fitToLayer !== false && typeof layer.getBounds === 'function') {
          var bounds = layer.getBounds();
          if (bounds && bounds.isValid && bounds.isValid()) {
            map.fitBounds(bounds, {
              padding: [24, 24]
            });
          }
        }
      }

      function buildFarmInfoText(farmId) {
        var farm = farmLookup[String(farmId)];
        if (!farm) {
          return 'Hover a marker or fence to view farm details.';
        }

        var infoParts = [];
        infoParts.push('Farm: ' + String(farm.farm_name || 'Farm'));
        if (farm.owner_name || farm.owner_username) {
          infoParts.push('Owner: ' + String(farm.owner_name || farm.owner_username));
        }

        var locationLine = [farm.location, farm.sitio, farm.barangay, farm.municipality, farm.province]
          .filter(function(part) {
            return part !== null && String(part).trim() !== '';
          })
          .join(', ');
        if (locationLine !== '') {
          infoParts.push('Location: ' + locationLine);
        }

        var inputs = farmInputs(farmId);
        var latitude = inputs.latitude && String(inputs.latitude.value).trim() !== ''
          ? toNumber(inputs.latitude.value, NaN)
          : toNumber(farm.latitude, NaN);
        var longitude = inputs.longitude && String(inputs.longitude.value).trim() !== ''
          ? toNumber(inputs.longitude.value, NaN)
          : toNumber(farm.longitude, NaN);
        if (Number.isFinite(latitude) && Number.isFinite(longitude)) {
          infoParts.push('Coordinates: ' + latitude.toFixed(7) + ', ' + longitude.toFixed(7));
        } else {
          infoParts.push('Coordinates: Not set');
        }

        var state = getFarmState(farmId);
        if (!state.enabled) {
          infoParts.push('Fence: Disabled');
        } else if (!state.geometry) {
          infoParts.push('Fence: Enabled (draw and save geometry)');
        } else {
          infoParts.push('Fence: ' + shapeLabel(state.shape));
        }

        if (String(farmId) === String(activeFarmId)) {
          infoParts.push('Selected for editing');
        }

        return infoParts.join(' | ');
      }

      function refreshHoverInfo(farmId) {
        setHoverInfo(buildFarmInfoText(farmId || activeFarmId));
      }

      function popupHtmlForFarm(farmId, latitude, longitude) {
        var farm = farmLookup[String(farmId)];
        if (!farm) {
          return '';
        }

        var popupParts = [];
        popupParts.push('<strong>' + escapeHtml(String(farm.farm_name || 'Farm')) + '</strong>');
        if (farm.owner_name || farm.owner_username) {
          popupParts.push('Owner: ' + escapeHtml(String(farm.owner_name || farm.owner_username)));
        }

        var locationLine = [farm.location, farm.sitio, farm.barangay, farm.municipality, farm.province]
          .filter(function(part) {
            return part !== null && String(part).trim() !== '';
          })
          .join(', ');
        if (locationLine !== '') {
          popupParts.push(escapeHtml(locationLine));
        }

        if (Number.isFinite(latitude) && Number.isFinite(longitude)) {
          popupParts.push('Coordinates: ' + latitude.toFixed(7) + ', ' + longitude.toFixed(7));
        } else {
          popupParts.push('Coordinates: Not set');
        }

        var state = getFarmState(farmId);
        if (!state.enabled) {
          popupParts.push('Fence: Disabled');
        } else if (!state.geometry) {
          popupParts.push('Fence: Enabled (draw and save geometry)');
        } else {
          popupParts.push('Fence: ' + shapeLabel(state.shape));
        }

        return popupParts.join('<br />');
      }

      function updateControlState() {
        var editingBlocked = !generalGeofenceConfigured;
        enabledToggle.disabled = editingBlocked;
        shapeSelector.disabled = editingBlocked || !enabledToggle.checked;
        clearButton.disabled = editingBlocked;
      }

      function refreshDrawControl() {
        if (drawControl) {
          map.removeControl(drawControl);
          drawControl = null;
        }

        if (!generalGeofenceConfigured || !enabledToggle.checked) {
          return;
        }

        drawControl = new L.Control.Draw({
          draw: drawOptions(normalizeShape(shapeSelector.value)),
          edit: {
            featureGroup: drawnItems,
            edit: true,
            remove: false
          }
        });
        map.addControl(drawControl);
      }

      function refreshStaticLayers(shouldFit) {
        staticLayers.clearLayers();
        var bounds = L.latLngBounds([]);

        geofenceGeometries.forEach(function(geofenceGeometry) {
          var geofenceLayer = buildLayerFromGeometry(
            geofenceGeometry.shape_type || 'CIRCLE',
            geofenceGeometry,
            {
              color: '#696cff',
              dashArray: '6 4',
              weight: 2,
              fillColor: '#696cff',
              fillOpacity: 0.08
            }
          );

          if (!geofenceLayer) {
            return;
          }

          geofenceLayer.addTo(staticLayers);
          if (typeof geofenceLayer.getBounds === 'function') {
            var geofenceBounds = geofenceLayer.getBounds();
            if (geofenceBounds && geofenceBounds.isValid && geofenceBounds.isValid()) {
              bounds.extend(geofenceBounds);
            }
          }
        });

        farms.forEach(function(farm) {
          var farmId = String(farm.id);
          var state = getFarmState(farmId);
          var inputs = farmInputs(farmId);
          validateFarmLocationInputs(farmId);
          var latitude = inputs.latitude && String(inputs.latitude.value).trim() !== ''
            ? toNumber(inputs.latitude.value, NaN)
            : toNumber(farm.latitude, NaN);
          var longitude = inputs.longitude && String(inputs.longitude.value).trim() !== ''
            ? toNumber(inputs.longitude.value, NaN)
            : toNumber(farm.longitude, NaN);

          if (Number.isFinite(latitude) && Number.isFinite(longitude)) {
            var marker = L.marker([latitude, longitude]).addTo(staticLayers);
            marker.bindPopup(popupHtmlForFarm(farmId, latitude, longitude));
            marker.on('mouseover', function() {
              refreshHoverInfo(farmId);
            });
            marker.on('mouseout', function() {
              refreshHoverInfo(activeFarmId);
            });
            bounds.extend([latitude, longitude]);
          }

          if (!state.enabled || !state.geometry || farmId === String(activeFarmId)) {
            return;
          }

          var fenceLayer = buildLayerFromGeometry(state.shape, state.geometry, {
            color: '#28c76f',
            weight: 2,
            fillColor: '#28c76f',
            fillOpacity: 0.12
          });

          if (!fenceLayer) {
            return;
          }

          fenceLayer.bindPopup(popupHtmlForFarm(farmId, latitude, longitude));
          fenceLayer.on('mouseover', function() {
            refreshHoverInfo(farmId);
          });
          fenceLayer.on('mouseout', function() {
            refreshHoverInfo(activeFarmId);
          });
          fenceLayer.addTo(staticLayers);

          if (typeof fenceLayer.getBounds === 'function') {
            var fenceBounds = fenceLayer.getBounds();
            if (fenceBounds && fenceBounds.isValid && fenceBounds.isValid()) {
              bounds.extend(fenceBounds);
            }
          }
        });

        if (activeLayer && typeof activeLayer.getBounds === 'function') {
          var activeBounds = activeLayer.getBounds();
          if (activeBounds && activeBounds.isValid && activeBounds.isValid()) {
            bounds.extend(activeBounds);
          }
        }

        if (shouldFit && bounds.isValid()) {
          map.fitBounds(bounds, {
            padding: [24, 24]
          });
        }
      }

      function loadActiveFarmState(fitToBounds) {
        var state = getFarmState(activeFarmId);

        enabledToggle.checked = state.enabled;
        shapeSelector.value = state.shape;
        drawnItems.clearLayers();
        activeLayer = null;

        if (state.enabled && state.geometry) {
          var layer = buildLayerFromGeometry(state.shape, state.geometry, {
            color: '#ff9f43',
            weight: 2,
            fillColor: '#ff9f43',
            fillOpacity: 0.16
          });
          if (layer) {
            setActiveLayer(layer, fitToBounds !== false);
          } else {
            setActiveFarmHiddenFields();
          }
        } else {
          setActiveFarmHiddenFields();
        }

        updateControlState();
        refreshDrawControl();
        refreshHoverInfo(activeFarmId);
      }

      map.on(L.Draw.Event.CREATED, function(event) {
        setClientError('');

        if (!enabledToggle.checked) {
          setClientError('Enable fence for the selected farm before drawing.');
          return;
        }

        var selectedShape = normalizeShape(shapeSelector.value);
        if (!layerMatchesShape(selectedShape, event.layer)) {
          clearActiveLayer();
          setClientError('Selected shape and drawn fence do not match. Please draw the selected shape.');
          return;
        }

        setActiveLayer(event.layer, false);
        refreshStaticLayers(false);
      });

      map.on(L.Draw.Event.EDITED, function() {
        setActiveFarmHiddenFields();
        refreshStaticLayers(false);
      });

      shapeSelector.addEventListener('change', function() {
        setClientError('');

        if (activeLayer && !layerMatchesShape(normalizeShape(shapeSelector.value), activeLayer)) {
          clearActiveLayer();
        } else {
          setActiveFarmHiddenFields();
        }

        updateControlState();
        refreshDrawControl();
        refreshStaticLayers(false);
      });

      enabledToggle.addEventListener('change', function() {
        setClientError('');

        if (!enabledToggle.checked) {
          clearActiveLayer();
        } else {
          setActiveFarmHiddenFields();
        }

        updateControlState();
        refreshDrawControl();
        refreshStaticLayers(false);
        refreshHoverInfo(activeFarmId);
      });

      clearButton.addEventListener('click', function() {
        setClientError('');
        enabledToggle.checked = false;
        clearActiveLayer();
        updateControlState();
        refreshDrawControl();
        refreshStaticLayers(false);
        refreshHoverInfo(activeFarmId);
      });

      farmSelector.addEventListener('change', function() {
        setClientError('');
        setActiveFarmHiddenFields();
        activeFarmId = String(farmSelector.value || '');
        loadActiveFarmState(true);
        refreshStaticLayers(true);
      });

      farms.forEach(function(farm) {
        var inputs = farmInputs(farm.id);
        if (inputs.latitude) {
          inputs.latitude.addEventListener('input', function() {
            validateFarmLocationInputs(farm.id);
            refreshStaticLayers(false);
            refreshHoverInfo(activeFarmId);
          });
        }
        if (inputs.longitude) {
          inputs.longitude.addEventListener('input', function() {
            validateFarmLocationInputs(farm.id);
            refreshStaticLayers(false);
            refreshHoverInfo(activeFarmId);
          });
        }
      });

      form.addEventListener('submit', function(event) {
        setActiveFarmHiddenFields();

        for (var index = 0; index < farms.length; index += 1) {
          var farm = farms[index];
          var inputs = farmInputs(farm.id);
          var validFarmLocation = validateFarmLocationInputs(farm.id);
          if (!validFarmLocation) {
            event.preventDefault();
            farmSelector.value = String(farm.id);
            activeFarmId = String(farm.id);
            loadActiveFarmState(true);
            refreshStaticLayers(true);
            mapElement.scrollIntoView({
              behavior: 'smooth',
              block: 'center'
            });
            return;
          }

          if (!inputs.enabled || !inputs.shape || !inputs.geometry) {
            continue;
          }

          inputs.shape.value = normalizeShape(inputs.shape.value);
          if (String(inputs.enabled.value || '0') !== '1') {
            continue;
          }

          if (String(inputs.geometry.value || '').trim() === '') {
            event.preventDefault();
            setClientError('Please draw and save a valid farm fence.');
            farmSelector.value = String(farm.id);
            activeFarmId = String(farm.id);
            loadActiveFarmState(true);
            refreshStaticLayers(true);
            mapElement.scrollIntoView({
              behavior: 'smooth',
              block: 'center'
            });
            return;
          }
        }
      });

      if (!generalGeofenceConfigured) {
        setClientError('Configure the general geofence first before saving farm fences.');
      }

      loadActiveFarmState(true);
      refreshStaticLayers(true);

      setTimeout(function() {
        map.invalidateSize();
      }, 100);
    })();
  </script>
@endsection
