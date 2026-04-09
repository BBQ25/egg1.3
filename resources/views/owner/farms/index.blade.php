@extends('layouts.admin')

@section('title', 'APEWSD - My Farms')

@push('styles')
  <link rel="stylesheet" href="{{ asset('sneat/assets/css/farm-map-admin.css') }}" />
  <style>
    .owner-farms-map {
      min-height: 420px;
    }

    .owner-farms-modal .modal-dialog {
      max-width: 960px;
      margin: 0.75rem auto;
    }

    .owner-farms-modal .modal-content {
      max-height: calc(100vh - 2.5rem);
      overflow: hidden;
    }

    .owner-farms-modal .modal-body {
      max-height: calc(100vh - 11.5rem);
      overflow-y: auto;
      padding-bottom: 0.75rem;
    }

    .owner-farms-modal .modal-footer {
      position: sticky;
      bottom: 0;
      z-index: 2;
      background: #fff;
      border-top: 1px solid rgba(67, 89, 113, 0.12);
    }

    .owner-farms-modal .farm-location-picker-card {
      padding: 0.75rem;
    }

    .owner-farms-modal .farm-location-picker-map {
      min-height: 170px;
      height: min(24vh, 210px);
    }

    @media (max-width: 767.98px) {
      .owner-farms-modal .modal-content {
        max-height: calc(100vh - 1rem);
      }

      .owner-farms-modal .modal-body {
        max-height: calc(100vh - 9.75rem);
      }

      .owner-farms-modal .farm-location-picker-map {
        min-height: 150px;
        height: min(22vh, 180px);
      }
    }
  </style>
@endpush

@section('content')
  @php
    $payloadJson = json_encode($farmLocationsMapPayload ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $farmRows = $farms ?? collect();
    $requestRows = $changeRequests ?? collect();
    $mappedFarmCount = $farmRows->filter(fn ($farm) => $farm->latitude !== null && $farm->longitude !== null)->count();
    $activeFarmCount = $farmRows->where('is_active', true)->count();
    $deviceCoverageCount = $farmRows->sum(fn ($farm) => (int) ($farm->devices_count ?? 0));
    $pendingRequestCount = $requestRows->where('status', 'PENDING')->count();
  @endphp

  <div class="row mb-4">
    <div class="col-12 d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <h4 class="mb-1">My Farms</h4>
        <p class="mb-0 text-body-secondary">
          Claim farms and submit location changes for admin approval. Approved records become your live farm registry.
        </p>
      </div>
      <div class="text-lg-end">
        <button type="button" class="btn btn-primary btn-sm mb-2" data-bs-toggle="modal" data-bs-target="#claimFarmModal">
          <i class="bx bx-plus me-1"></i>Claim a Farm
        </button>
        <div class="small text-body-secondary">
          General geofence:
          @if ($generalGeofenceConfigured)
            <span class="badge bg-label-success">Configured</span>
          @else
            <span class="badge bg-label-warning">Not Configured</span>
          @endif
        </div>
      </div>
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

  <div class="card mb-4 farm-map-overview-card">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-sm-6 col-xl-4">
          <div class="farm-map-stat-card is-farms">
            <span class="farm-map-stat-icon"><i class="bx bx-store-alt"></i></span>
            <div>
              <div class="farm-map-stat-label">Owned Farms</div>
              <div class="farm-map-stat-value">{{ number_format($activeFarmCount) }}</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-4">
          <div class="farm-map-stat-card is-mapped">
            <span class="farm-map-stat-icon"><i class="bx bx-map-pin"></i></span>
            <div>
              <div class="farm-map-stat-label">Mapped Farms</div>
              <div class="farm-map-stat-value">{{ number_format($mappedFarmCount) }}</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-4">
          <div class="farm-map-stat-card is-owners">
            <span class="farm-map-stat-icon"><i class="bx bx-time-five"></i></span>
            <div>
              <div class="farm-map-stat-label">Pending Requests</div>
              <div class="farm-map-stat-value">{{ number_format($pendingRequestCount) }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="alert alert-label-primary mb-4" role="alert">
    <div class="fw-semibold mb-1">How owner farm submission works</div>
    <div class="small">
      You can claim a new farm or submit an updated location for an existing farm. Every submission goes to an admin review queue first.
      If the pinned coordinates are outside the current general geofence, the request is still allowed and will be highlighted for admin review instead of being blocked.
    </div>
  </div>

  <div class="card mb-4 farm-map-panel-card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <div class="farm-map-section-kicker"><i class="bx bx-table"></i>Registry</div>
        <h5 class="mb-0">Owned Farm Registry</h5>
      </div>
      <span class="badge bg-label-primary">{{ $farmRows->count() }} farms</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead>
            <tr>
              <th>Farm</th>
              <th>Location</th>
              <th>Coordinates</th>
              <th>Devices</th>
              <th>Status</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($farmRows as $farm)
              @php
                $locationLabel = collect([
                  $farm->location,
                  $farm->sitio,
                  $farm->barangay,
                  $farm->municipality,
                  $farm->province,
                ])->filter(fn ($value) => is_string($value) && trim($value) !== '')->implode(', ');
              @endphp
              <tr>
                <td>
                  <div class="fw-semibold">{{ $farm->farm_name }}</div>
                  <div class="small text-body-secondary">Farm ID #{{ $farm->id }}</div>
                </td>
                <td>
                  <div class="small text-body-secondary">
                    {{ $locationLabel !== '' ? $locationLabel : 'No location details' }}
                  </div>
                </td>
                <td>
                  @if ($farm->latitude !== null && $farm->longitude !== null)
                    <code class="farm-map-location-code">{{ number_format((float) $farm->latitude, 7) }}, {{ number_format((float) $farm->longitude, 7) }}</code>
                  @else
                    <span class="text-body-secondary">Not set</span>
                  @endif
                </td>
                <td>{{ number_format((int) ($farm->devices_count ?? 0)) }}</td>
                <td>
                  @if ($farm->is_active)
                    <span class="farm-map-status-chip" style="--farm-map-chip-color: #3bb273;">Active</span>
                  @else
                    <span class="farm-map-status-chip" style="--farm-map-chip-color: #8592a3;">Inactive</span>
                  @endif
                </td>
                <td class="text-end">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary js-edit-farm"
                    data-farm-id="{{ $farm->id }}"
                    data-farm-name="{{ $farm->farm_name }}"
                    data-location="{{ $farm->location }}"
                    data-sitio="{{ $farm->sitio }}"
                    data-barangay="{{ $farm->barangay }}"
                    data-municipality="{{ $farm->municipality }}"
                    data-province="{{ $farm->province }}"
                    data-latitude="{{ $farm->latitude }}"
                    data-longitude="{{ $farm->longitude }}">
                    Request Update
                  </button>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="6" class="text-center py-4 text-body-secondary">
                  No farms are assigned to your owner account yet.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card mb-4 farm-map-panel-card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <div class="farm-map-section-kicker"><i class="bx bx-git-pull-request"></i>Approvals</div>
        <h5 class="mb-0">Submitted Requests</h5>
      </div>
      <span class="badge bg-label-primary">{{ $requestRows->count() }} requests</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead>
            <tr>
              <th>Request</th>
              <th>Farm</th>
              <th>Coordinates</th>
              <th>Geofence</th>
              <th>Status</th>
              <th>Submitted</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($requestRows as $requestRow)
              <tr>
                <td>
                  <div class="fw-semibold">{{ $requestRow->request_type === 'CLAIM' ? 'Farm Claim' : 'Location Update' }}</div>
                  @if ($requestRow->admin_notes)
                    <div class="small text-body-secondary">{{ $requestRow->admin_notes }}</div>
                  @endif
                </td>
                <td>
                  <div>{{ $requestRow->farm_name }}</div>
                  <div class="small text-body-secondary">
                    {{ $requestRow->farm?->farm_name ? 'Current record: ' . $requestRow->farm->farm_name : 'New farm claim' }}
                  </div>
                </td>
                <td>
                  @if ($requestRow->latitude !== null && $requestRow->longitude !== null)
                    <code class="farm-map-location-code">{{ number_format((float) $requestRow->latitude, 7) }}, {{ number_format((float) $requestRow->longitude, 7) }}</code>
                  @else
                    <span class="text-body-secondary">Not set</span>
                  @endif
                </td>
                <td>
                  @if ($requestRow->inside_general_geofence === true)
                    <span class="badge bg-label-success">Inside</span>
                  @elseif ($requestRow->inside_general_geofence === false)
                    <span class="badge bg-label-warning">Outside - needs review</span>
                  @else
                    <span class="badge bg-label-secondary">No general geofence</span>
                  @endif
                </td>
                <td>
                  @if ($requestRow->status === 'PENDING')
                    <span class="badge bg-label-warning">Pending</span>
                  @elseif ($requestRow->status === 'APPROVED')
                    <span class="badge bg-label-success">Approved</span>
                  @else
                    <span class="badge bg-label-danger">Rejected</span>
                  @endif
                </td>
                <td>{{ \App\Support\AppTimezone::formatDateTime($requestRow->submitted_at) }}</td>
              </tr>
            @empty
              <tr>
                <td colspan="6" class="text-center py-4 text-body-secondary">
                  No farm requests submitted yet.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card farm-map-panel-card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <div class="farm-map-section-kicker"><i class="bx bx-map-alt"></i>Spatial Overview</div>
        <h5 class="mb-0">My Farm Locations</h5>
      </div>
      <div class="d-flex align-items-center gap-3">
        <div class="farm-map-legend">
          <span class="farm-map-legend-item" style="--farm-map-legend-color: #696cff;">General Geofence</span>
          <span class="farm-map-legend-item" style="--farm-map-legend-color: #28c76f;">Farm Fence</span>
          <span class="farm-map-legend-item" style="--farm-map-legend-color: #0aa2c0;">Farm Marker</span>
        </div>
        <div class="small text-body-secondary text-end">
          <div id="farm-map-count">Loading...</div>
          <div>Maps can be displayed in Standard, Satellite, and Terrain views.</div>
        </div>
      </div>
    </div>
    <div class="card-body p-0">
      <div id="farm-map-canvas" class="owner-farms-map" role="img" aria-label="My farm locations map"></div>
    </div>
  </div>

  <div class="modal fade owner-farms-modal" id="editFarmModal" tabindex="-1" aria-labelledby="editFarmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editFarmModalLabel">Request Farm Update</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="{{ route('owner.farms.update', ['farm' => 0]) }}" id="editFarmForm">
          @csrf
          @method('PUT')
          <input type="hidden" name="farm_form_mode" value="edit">
          <input type="hidden" name="farm_id" id="edit_farm_id" value="{{ old('farm_id') }}">
          <div class="modal-body">
            @include('owner.farms.partials.form-fields', [
              'values' => [
                'farm_name' => old('farm_name'),
                'location' => old('location'),
                'sitio' => old('sitio'),
                'barangay' => old('barangay'),
                'municipality' => old('municipality'),
                'province' => old('province'),
                'latitude' => old('latitude'),
                'longitude' => old('longitude'),
              ],
              'prefix' => 'edit_',
            ])
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Submit for Approval</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade owner-farms-modal" id="claimFarmModal" tabindex="-1" aria-labelledby="claimFarmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="claimFarmModalLabel">Claim a Farm</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="{{ route('owner.farms.store') }}" id="claimFarmForm">
          @csrf
          <input type="hidden" name="farm_form_mode" value="claim">
          <div class="modal-body">
            @include('owner.farms.partials.form-fields', [
              'values' => [
                'farm_name' => old('farm_name'),
                'location' => old('location'),
                'sitio' => old('sitio'),
                'barangay' => old('barangay'),
                'municipality' => old('municipality'),
                'province' => old('province'),
                'latitude' => old('latitude'),
                'longitude' => old('longitude'),
              ],
              'prefix' => 'claim_',
            ])
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Submit Claim</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script id="farm_map_payload" type="application/json">{!! $payloadJson !!}</script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const editModalElement = document.getElementById('editFarmModal');
      const claimModalElement = document.getElementById('claimFarmModal');
      const editModal = editModalElement && window.bootstrap ? new window.bootstrap.Modal(editModalElement) : null;
      const claimModal = claimModalElement && window.bootstrap ? new window.bootstrap.Modal(claimModalElement) : null;
      const editForm = document.getElementById('editFarmForm');
      const editFarmIdInput = document.getElementById('edit_farm_id');
      const farmMapCount = document.getElementById('farm-map-count');
      const reverseGeocodeUrl = @json(route('owner.farms.reverse-geocode'));

      const editFields = {
        farm_name: document.getElementById('edit_farm_name'),
        location: document.getElementById('edit_location'),
        sitio: document.getElementById('edit_sitio'),
        barangay: document.getElementById('edit_barangay'),
        municipality: document.getElementById('edit_municipality'),
        province: document.getElementById('edit_province'),
        latitude: document.getElementById('edit_latitude'),
        longitude: document.getElementById('edit_longitude'),
      };

      function toNumber(value, fallback) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : fallback;
      }

      function roundCoordinate(value) {
        return Math.round(Number(value) * 10000000) / 10000000;
      }

      function escapeHtml(value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function createBaseLayers() {
        return {
          Standard: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
          }),
          Satellite: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19,
            attribution: 'Tiles &copy; Esri',
          }),
          Terrain: L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            maxZoom: 17,
            attribution: 'Map data: &copy; OpenStreetMap contributors, SRTM | Map style: &copy; OpenTopoMap',
          }),
        };
      }

      function normalizeShape(shape) {
        const normalized = String(shape || '').toUpperCase();
        return ['CIRCLE', 'RECTANGLE', 'SQUARE', 'POLYGON'].includes(normalized) ? normalized : null;
      }

      function buildLayerFromGeometry(geometry, style) {
        if (!geometry || typeof geometry !== 'object') {
          return null;
        }

        const shapeType = normalizeShape(geometry.shape_type);
        if (!shapeType) {
          return null;
        }

        let layer = null;
        if (shapeType === 'CIRCLE') {
          const centerLatitude = toNumber(geometry.center_latitude, NaN);
          const centerLongitude = toNumber(geometry.center_longitude, NaN);
          const radiusMeters = toNumber(geometry.radius_meters, NaN);
          if (Number.isFinite(centerLatitude) && Number.isFinite(centerLongitude) && Number.isFinite(radiusMeters) && radiusMeters > 0) {
            layer = L.circle([centerLatitude, centerLongitude], { radius: radiusMeters });
          }
        } else if (shapeType === 'RECTANGLE' || shapeType === 'SQUARE') {
          const bounds = geometry.bounds || geometry;
          const north = toNumber(bounds.north, NaN);
          const south = toNumber(bounds.south, NaN);
          const east = toNumber(bounds.east, NaN);
          const west = toNumber(bounds.west, NaN);
          if (Number.isFinite(north) && Number.isFinite(south) && Number.isFinite(east) && Number.isFinite(west) && north > south && east > west) {
            layer = L.rectangle([[south, west], [north, east]]);
          }
        } else if (shapeType === 'POLYGON') {
          const points = (Array.isArray(geometry.vertices) ? geometry.vertices : []).map(function (point) {
            if (!Array.isArray(point) || point.length < 2) return null;
            const lat = toNumber(point[0], NaN);
            const lng = toNumber(point[1], NaN);
            return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : null;
          }).filter(Boolean);

          if (points.length >= 3) {
            layer = L.polygon(points);
          }
        }

        if (layer && style && typeof layer.setStyle === 'function') {
          layer.setStyle(style);
        }

        return layer;
      }

      function parsePayload() {
        const payloadNode = document.getElementById('farm_map_payload');
        if (!payloadNode) return { farms: [], geofence: {}, map_center: { latitude: 10.354727, longitude: 124.96598 } };

        try {
          return JSON.parse(payloadNode.textContent || '{}');
        } catch (error) {
          return { farms: [], geofence: {}, map_center: { latitude: 10.354727, longitude: 124.96598 } };
        }
      }

      function createLocationPicker(prefix, modalElement) {
        const mapElement = document.getElementById(prefix + 'location_picker_map');
        const latitudeInput = document.getElementById(prefix + 'latitude');
        const longitudeInput = document.getElementById(prefix + 'longitude');
        const barangayInput = document.getElementById(prefix + 'barangay');
        const municipalityInput = document.getElementById(prefix + 'municipality');
        const provinceInput = document.getElementById(prefix + 'province');
        const statusNode = document.getElementById(prefix + 'picker_status');
        const useCurrentButton = document.getElementById(prefix + 'use_current_location');
        const clearButton = document.getElementById(prefix + 'clear_pin');

        if (!mapElement || !latitudeInput || !longitudeInput) {
          return null;
        }

        const payload = parsePayload();
        const center = payload.map_center || payload.default_center || { latitude: 10.354727, longitude: 124.96598 };
        const baseLayers = createBaseLayers();
        const map = L.map(mapElement, { zoomControl: true, layers: [baseLayers.Standard] }).setView([
          toNumber(center.latitude, 10.354727),
          toNumber(center.longitude, 124.96598),
        ], 13);

        L.control.layers(baseLayers, null, { position: 'topright' }).addTo(map);

        const geofenceGeometry = payload.geofence && payload.geofence.geometry ? payload.geofence.geometry : null;
        const geofenceLayer = buildLayerFromGeometry(geofenceGeometry, {
          color: '#696cff',
          weight: 2,
          opacity: 0.8,
          fillColor: '#696cff',
          fillOpacity: 0.08,
        });
        if (geofenceLayer) {
          geofenceLayer.addTo(map);
        }

        let marker = null;

        function updateStatus(latitude, longitude) {
          if (!statusNode) return;
          if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            statusNode.textContent = 'No map pin selected yet.';
            return;
          }

          statusNode.textContent = 'Pinned at ' + latitude.toFixed(7) + ', ' + longitude.toFixed(7) + '.';
        }

        function setCoordinates(latitude, longitude, shouldReverseGeocode) {
          const lat = roundCoordinate(latitude);
          const lng = roundCoordinate(longitude);

          latitudeInput.value = lat.toFixed(7);
          longitudeInput.value = lng.toFixed(7);

          if (!marker) {
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            marker.on('dragend', function () {
              const point = marker.getLatLng();
              setCoordinates(point.lat, point.lng, true);
            });
          } else {
            marker.setLatLng([lat, lng]);
          }

          map.panTo([lat, lng]);
          updateStatus(lat, lng);

          if (shouldReverseGeocode) {
            const params = new URLSearchParams({
              latitude: lat.toFixed(7),
              longitude: lng.toFixed(7),
            });

            fetch(reverseGeocodeUrl + '?' + params.toString(), {
              headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
            }).then(function (response) {
              if (!response.ok) {
                throw new Error('Reverse geocoding failed.');
              }
              return response.json();
            }).then(function (payload) {
              if (!payload || !payload.ok || !payload.data) return;
              if (barangayInput && payload.data.barangay) barangayInput.value = payload.data.barangay;
              if (municipalityInput && payload.data.municipality) municipalityInput.value = payload.data.municipality;
              if (provinceInput && payload.data.province) provinceInput.value = payload.data.province;
            }).catch(function () {
              // Ignore reverse geocode failures.
            });
          }
        }

        map.on('click', function (event) {
          setCoordinates(event.latlng.lat, event.latlng.lng, true);
        });

        if (useCurrentButton) {
          useCurrentButton.addEventListener('click', function () {
            if (!navigator.geolocation) return;
            navigator.geolocation.getCurrentPosition(function (position) {
              setCoordinates(position.coords.latitude, position.coords.longitude, true);
            });
          });
        }

        if (clearButton) {
          clearButton.addEventListener('click', function () {
            latitudeInput.value = '';
            longitudeInput.value = '';
            updateStatus(NaN, NaN);
            if (marker) {
              map.removeLayer(marker);
              marker = null;
            }
          });
        }

        if (modalElement) {
          modalElement.addEventListener('shown.bs.modal', function () {
            map.invalidateSize();
          });
        }

        return {
          map: map,
          setCoordinates: setCoordinates,
          clearCoordinates: function () {
            if (marker) {
              map.removeLayer(marker);
              marker = null;
            }
            latitudeInput.value = '';
            longitudeInput.value = '';
            updateStatus(NaN, NaN);
          },
        };
      }

      const mapPayload = parsePayload();
      const baseLayers = createBaseLayers();
      const mapCenter = mapPayload.map_center || mapPayload.default_center || { latitude: 10.354727, longitude: 124.96598 };
      const map = L.map('farm-map-canvas', { zoomControl: true, layers: [baseLayers.Standard] }).setView([
        toNumber(mapCenter.latitude, 10.354727),
        toNumber(mapCenter.longitude, 124.96598),
      ], 12);

      L.control.layers(baseLayers, null, { position: 'topright' }).addTo(map);

      const mainGeofenceLayer = buildLayerFromGeometry(mapPayload.geofence && mapPayload.geofence.geometry ? mapPayload.geofence.geometry : null, {
        color: '#696cff',
        weight: 2,
        opacity: 0.8,
        fillColor: '#696cff',
        fillOpacity: 0.08,
      });

      if (mainGeofenceLayer) {
        mainGeofenceLayer.addTo(map);
      }

      const featureBounds = [];
      (Array.isArray(mapPayload.farms) ? mapPayload.farms : []).forEach(function (farm) {
        const latitude = toNumber(farm.latitude, NaN);
        const longitude = toNumber(farm.longitude, NaN);

        const fenceGeometry = farm.fence && farm.fence.geometry ? farm.fence.geometry : null;
        const fenceLayer = buildLayerFromGeometry(fenceGeometry, {
          color: '#28c76f',
          weight: 2,
          opacity: 0.75,
          fillColor: '#28c76f',
          fillOpacity: 0.08,
        });

        if (fenceLayer) {
          fenceLayer.addTo(map);
          if (typeof fenceLayer.getBounds === 'function') {
            featureBounds.push(fenceLayer.getBounds());
          }
        }

        if (Number.isFinite(latitude) && Number.isFinite(longitude)) {
          const marker = L.circleMarker([latitude, longitude], {
            radius: 7,
            color: '#0aa2c0',
            fillColor: '#0aa2c0',
            fillOpacity: 0.95,
            weight: 2,
          }).addTo(map);

          marker.bindPopup(
            '<strong>' + escapeHtml(farm.farm_name || 'Farm') + '</strong><br>' +
            escapeHtml(farm.location_label || 'No location details') + '<br>' +
            'Coordinates: ' + latitude.toFixed(7) + ', ' + longitude.toFixed(7)
          );
          featureBounds.push(L.latLngBounds([latitude, longitude], [latitude, longitude]));
        }
      });

      if (featureBounds.length > 0) {
        const bounds = featureBounds.reduce(function (carry, item) {
          return carry.extend(item);
        }, featureBounds.shift());
        map.fitBounds(bounds.pad(0.12));
      }

      if (farmMapCount) {
        const farmCount = Array.isArray(mapPayload.farms) ? mapPayload.farms.length : 0;
        farmMapCount.textContent = farmCount + ' farm' + (farmCount === 1 ? '' : 's') + ' in view';
      }

      const editPicker = createLocationPicker('edit_', editModalElement);
      createLocationPicker('claim_', claimModalElement);

      function fillEditForm(dataset) {
        editFarmIdInput.value = dataset.farmId || '';
        editForm.action = @json(url('owner/my-farms')) + '/' + String(dataset.farmId || '');
        editFields.farm_name.value = dataset.farmName || '';
        editFields.location.value = dataset.location || '';
        editFields.sitio.value = dataset.sitio || '';
        editFields.barangay.value = dataset.barangay || '';
        editFields.municipality.value = dataset.municipality || '';
        editFields.province.value = dataset.province || '';

        const latitude = toNumber(dataset.latitude, NaN);
        const longitude = toNumber(dataset.longitude, NaN);
        if (editPicker) {
          if (Number.isFinite(latitude) && Number.isFinite(longitude)) {
            editPicker.setCoordinates(latitude, longitude, false);
          } else {
            editPicker.clearCoordinates();
          }
        }
      }

      document.querySelectorAll('.js-edit-farm').forEach(function (button) {
        button.addEventListener('click', function () {
          fillEditForm(button.dataset);
          if (editModal) {
            editModal.show();
          }
        });
      });

      @if (old('farm_form_mode') === 'edit')
        fillEditForm({
          farmId: @json(old('farm_id')),
          farmName: @json(old('farm_name')),
          location: @json(old('location')),
          sitio: @json(old('sitio')),
          barangay: @json(old('barangay')),
          municipality: @json(old('municipality')),
          province: @json(old('province')),
          latitude: @json(old('latitude')),
          longitude: @json(old('longitude')),
        });
        if (editModal) {
          editModal.show();
        }
      @endif

      @if (old('farm_form_mode') === 'claim' && claimModal)
        claimModal.show();
      @endif
    });
  </script>
@endsection
