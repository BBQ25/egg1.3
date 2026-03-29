@extends('layouts.admin')

@section('title', 'APEWSD - Farm & Map Management')

@push('styles')
  <link rel="stylesheet" href="{{ asset('sneat/assets/css/farm-map-admin.css') }}" />
@endpush

@section('content')
  @php
    $payloadJson = json_encode($farmLocationsMapPayload ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $farmRows = $farms ?? collect();
    $ownerRows = $owners ?? collect();
    $farmRequestRows = $farmChangeRequests ?? collect();
    $mappedFarmCount = $farmRows->filter(fn ($farm) => $farm->latitude !== null && $farm->longitude !== null)->count();
    $activeFarmCount = $farmRows->where('is_active', true)->count();
    $ownerCoverageCount = $farmRows->pluck('owner_user_id')->filter()->unique()->count();
    $pendingFarmRequestCount = $farmRequestRows->where('status', 'PENDING')->count();
  @endphp

  <div class="row mb-4">
    <div class="col-12 d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <h4 class="mb-1">Farm &amp; Map Management</h4>
        <p class="mb-0 text-body-secondary">
          Create farms, assign/reassign owners, and review map coordinates in one page.
        </p>
      </div>
      <div class="text-lg-end">
        <div class="d-flex flex-wrap justify-content-lg-end gap-2">
          @include('admin.partials.map-tour', [
            'tourId' => 'farm-management-tour',
            'tourTitle' => 'Farm & Map Guide',
            'tourIntro' => 'This page combines farm registry work and the live map so admins can maintain plots with context.',
            'tourSteps' => [
              [
                'title' => 'Overview cards',
                'body' => 'Use the top overview cards to confirm farm coverage, map completeness, and whether the general geofence is configured.',
                'selector' => '#farm-map-overview',
              ],
              [
                'title' => 'Farm registry',
                'body' => 'The registry table is where you review each farm, its owner, saved coordinates, and access the edit or delete actions.',
                'selector' => '#farm-registry-panel',
              ],
              [
                'title' => 'Spatial overview',
                'body' => 'On the map, the general geofence appears in blue, farm fences in green, and farm markers show the saved latitude and longitude for each farm.',
                'selector' => '#farm-map-panel',
              ],
              [
                'title' => 'Map legend',
                'body' => 'The legend tells you what each plot color means so you can quickly distinguish the system boundary from farm-level overlays.',
                'selector' => '#farm-map-legend',
              ],
              [
                'title' => 'Add farm',
                'body' => 'Use the Add Farm button to create a new registry entry and place a new marker on the map once valid coordinates are saved.',
                'selector' => '#farm-add-button',
              ],
            ],
          ])
          <button type="button" class="btn btn-primary" id="farm-add-button" data-bs-toggle="modal" data-bs-target="#createFarmModal">
            <i class="bx bx-plus me-1"></i>
            Add Farm
          </button>
        </div>
        <div class="small text-body-secondary mt-2">
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

  @if (session('error'))
    <div class="alert alert-danger" role="alert">
      {{ session('error') }}
    </div>
  @endif

  @if ($errors->any())
    <div class="alert alert-danger" role="alert">
      {{ $errors->first() }}
    </div>
  @endif

  <div class="card mb-4 farm-map-overview-card" id="farm-map-overview">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-sm-6 col-xl-3">
          <div class="farm-map-stat-card is-farms">
            <span class="farm-map-stat-icon"><i class="bx bx-store-alt"></i></span>
            <div>
              <div class="farm-map-stat-label">Registered Farms</div>
              <div class="farm-map-stat-value">{{ number_format($activeFarmCount) }}</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="farm-map-stat-card is-geofence {{ $generalGeofenceConfigured ? '' : 'is-off' }}">
            <span class="farm-map-stat-icon"><i class="bx bx-shield-quarter"></i></span>
            <div>
              <div class="farm-map-stat-label">General Geofence</div>
              <div class="farm-map-stat-value">{{ $generalGeofenceConfigured ? 'On' : 'Off' }}</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="farm-map-stat-card is-mapped">
            <span class="farm-map-stat-icon"><i class="bx bx-map-pin"></i></span>
            <div>
              <div class="farm-map-stat-label">Mapped Farms</div>
              <div class="farm-map-stat-value">{{ number_format($mappedFarmCount) }}</div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="farm-map-stat-card is-owners">
            <span class="farm-map-stat-icon"><i class="bx bx-git-pull-request"></i></span>
            <div>
              <div class="farm-map-stat-label">Pending Requests</div>
              <div class="farm-map-stat-value">{{ number_format($pendingFarmRequestCount) }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-lg-6">
          <div class="border rounded p-3 h-100">
            <div class="fw-semibold mb-2">General Geofence</div>
            <div class="small text-body-secondary">
              The general geofence is the system-wide boundary for non-admin access. It answers:
              <strong>Can this user operate or sign in from this larger area at all?</strong>
              Owner claim requests outside this boundary are still allowed, but they should be reviewed carefully before approval.
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="border rounded p-3 h-100">
            <div class="fw-semibold mb-2">Premises</div>
            <div class="small text-body-secondary">
              Premises are smaller, more specific zones inside the wider operating boundary. Farm premises define the expected plot for a farm.
              User premises define where a specific non-admin user may access the system. Use premises for precise local restrictions, not for the global boundary itself.
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-4 farm-map-panel-card" id="farm-request-panel">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <div class="farm-map-section-kicker"><i class="bx bx-git-pull-request"></i>Review Queue</div>
        <h5 class="mb-0">Owner Claim And Location Requests</h5>
      </div>
      <span class="badge bg-label-primary">{{ $farmRequestRows->count() }} requests</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead>
            <tr>
              <th>Owner</th>
              <th>Request</th>
              <th>Farm</th>
              <th>Coordinates</th>
              <th>Geofence</th>
              <th>Status</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($farmRequestRows as $requestRow)
              <tr>
                <td>
                  <div>{{ $requestRow->owner?->full_name ?? 'Unknown owner' }}</div>
                  @if ($requestRow->owner?->username)
                    <div class="small text-body-secondary">{{ '@' . $requestRow->owner->username }}</div>
                  @endif
                </td>
                <td>
                  <div class="fw-semibold">{{ $requestRow->request_type === 'CLAIM' ? 'Farm Claim' : 'Location Update' }}</div>
                  <div class="small text-body-secondary">{{ optional($requestRow->submitted_at)->format('M j, Y g:i A') }}</div>
                </td>
                <td>
                  <div>{{ $requestRow->farm_name }}</div>
                  <div class="small text-body-secondary">
                    {{ $requestRow->farm?->farm_name ? 'Current record: ' . $requestRow->farm->farm_name : 'New farm on approval' }}
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
                    <span class="badge bg-label-warning">Outside - admin decision required</span>
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
                  @if ($requestRow->reviewer?->full_name)
                    <div class="small text-body-secondary mt-1">By {{ $requestRow->reviewer->full_name }}</div>
                  @endif
                  @if ($requestRow->admin_notes)
                    <div class="small text-body-secondary mt-1">{{ $requestRow->admin_notes }}</div>
                  @endif
                </td>
                <td class="text-end">
                  @if ($requestRow->status === 'PENDING')
                    <div class="d-flex flex-column align-items-end gap-2">
                      <form method="POST" action="{{ route('admin.maps.farm-requests.approve', $requestRow) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-success">Approve</button>
                      </form>
                      <form method="POST" action="{{ route('admin.maps.farm-requests.reject', $requestRow) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                      </form>
                    </div>
                  @else
                    <span class="text-body-secondary small">Reviewed</span>
                  @endif
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="7" class="text-center py-4 text-body-secondary">
                  No owner farm requests in the queue.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card mb-4 farm-map-panel-card" id="farm-registry-panel">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <div class="farm-map-section-kicker"><i class="bx bx-table"></i>Registry</div>
        <h5 class="mb-0">Farm Registry</h5>
      </div>
      <span class="badge bg-label-primary">{{ $farmRows->count() }} farms</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead>
            <tr>
              <th>Farm</th>
              <th>Owner</th>
              <th>Location</th>
              <th>Coordinates</th>
              <th>Status</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($farmRows as $farm)
              @php
                $ownerLabel = $farm->owner?->full_name ?: 'Unassigned owner';
                $ownerUsername = $farm->owner?->username ? '@' . $farm->owner->username : null;
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
                  <div>{{ $ownerLabel }}</div>
                  @if ($ownerUsername)
                    <div class="small text-body-secondary">{{ $ownerUsername }}</div>
                  @endif
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
                <td>
                  @if ($farm->is_active)
                    <span class="farm-map-status-chip" style="--farm-map-chip-color: #3bb273;">Active</span>
                  @else
                    <span class="farm-map-status-chip" style="--farm-map-chip-color: #8592a3;">Inactive</span>
                  @endif
                </td>
                <td class="text-end">
                  <div class="admin-row-actions justify-content-end">
                    <button
                      type="button"
                      class="btn btn-sm btn-icon btn-outline-primary admin-row-action-btn js-edit-farm"
                      aria-label="Edit farm {{ $farm->farm_name }}"
                      data-action-label="Edit"
                      data-farm-id="{{ $farm->id }}"
                      data-farm-name="{{ $farm->farm_name }}"
                      data-owner-user-id="{{ $farm->owner_user_id }}"
                      data-location="{{ $farm->location }}"
                      data-sitio="{{ $farm->sitio }}"
                      data-barangay="{{ $farm->barangay }}"
                      data-municipality="{{ $farm->municipality }}"
                      data-province="{{ $farm->province }}"
                      data-latitude="{{ $farm->latitude }}"
                      data-longitude="{{ $farm->longitude }}">
                      <i class="bx bx-edit-alt"></i>
                    </button>
                    <button
                      type="button"
                      class="btn btn-sm btn-icon btn-outline-danger admin-row-action-btn js-delete-farm"
                      aria-label="Delete farm {{ $farm->farm_name }}"
                      data-action-label="Delete"
                      data-farm-id="{{ $farm->id }}"
                      data-farm-name="{{ $farm->farm_name }}">
                      <i class="bx bx-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="6" class="text-center py-4 text-body-secondary">
                  No farms yet. Add your first farm using the "Add Farm" button.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card farm-map-panel-card" id="farm-map-panel">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <div class="farm-map-section-kicker"><i class="bx bx-map-alt"></i>Spatial Overview</div>
        <h5 class="mb-0">Farm Locations Map</h5>
      </div>
      <div class="d-flex align-items-center gap-3">
        <div class="farm-map-legend" id="farm-map-legend">
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
      <div id="farm-map-canvas" role="img" aria-label="Farm locations map"></div>
    </div>
  </div>

  <div class="modal fade" id="createFarmModal" tabindex="-1" aria-labelledby="createFarmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="createFarmModalLabel">Create Farm</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="{{ route('admin.maps.farms.store') }}">
          @csrf
          <input type="hidden" name="farm_form_mode" value="create">
          <div class="modal-body">
            @include('admin.maps.partials.farm-form-fields', [
              'owners' => $ownerRows,
              'values' => [
                'farm_name' => old('farm_name'),
                'owner_user_id' => old('owner_user_id'),
                'location' => old('location'),
                'sitio' => old('sitio'),
                'barangay' => old('barangay'),
                'municipality' => old('municipality'),
                'province' => old('province'),
                'latitude' => old('latitude'),
                'longitude' => old('longitude'),
              ],
              'prefix' => 'create_',
            ])
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Farm</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editFarmModal" tabindex="-1" aria-labelledby="editFarmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editFarmModalLabel">Edit Farm</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="{{ route('admin.maps.farms.update', ['farm' => 0]) }}" id="editFarmForm">
          @csrf
          @method('PUT')
          <input type="hidden" name="farm_form_mode" value="edit">
          <input type="hidden" name="farm_id" id="edit_farm_id" value="{{ old('farm_id') }}">
          <div class="modal-body">
            @include('admin.maps.partials.farm-form-fields', [
              'owners' => $ownerRows,
              'values' => [
                'farm_name' => old('farm_name'),
                'owner_user_id' => old('owner_user_id'),
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
            <button type="submit" class="btn btn-primary">Update Farm</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteFarmModal" tabindex="-1" aria-labelledby="deleteFarmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="deleteFarmModalLabel">Delete Farm</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="{{ route('admin.maps.farms.destroy', ['farm' => 0]) }}" id="deleteFarmForm">
          @csrf
          @method('DELETE')
          <input type="hidden" name="farm_form_mode" value="delete">
          <input type="hidden" name="farm_id" id="delete_farm_id" value="{{ old('farm_id') }}">
          <div class="modal-body">
            <p class="mb-2">
              You are about to delete:
              <strong id="delete_farm_name">this farm</strong>
            </p>
            <p class="text-body-secondary mb-3">
              This action removes the farm and cascades related devices and ingest records.
            </p>
            <label class="form-label" for="delete_current_password">Confirm Admin Password</label>
            <input
              type="password"
              class="form-control"
              id="delete_current_password"
              name="current_password"
              autocomplete="current-password"
              required>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger">Delete Farm</button>
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
      const createModalElement = document.getElementById('createFarmModal');
      const deleteModalElement = document.getElementById('deleteFarmModal');
      const editModal = editModalElement && window.bootstrap ? new window.bootstrap.Modal(editModalElement) : null;
      const createModal = createModalElement && window.bootstrap ? new window.bootstrap.Modal(createModalElement) : null;
      const deleteModal = deleteModalElement && window.bootstrap ? new window.bootstrap.Modal(deleteModalElement) : null;
      const editForm = document.getElementById('editFarmForm');
      const deleteForm = document.getElementById('deleteFarmForm');
      const editFarmIdInput = document.getElementById('edit_farm_id');
      const deleteFarmIdInput = document.getElementById('delete_farm_id');
      const deleteFarmNameNode = document.getElementById('delete_farm_name');
      const deleteCurrentPasswordInput = document.getElementById('delete_current_password');
      const farmMapCount = document.getElementById('farm-map-count');

      const editFields = {
        farm_name: document.getElementById('edit_farm_name'),
        owner_user_id: document.getElementById('edit_owner_user_id'),
        location: document.getElementById('edit_location'),
        sitio: document.getElementById('edit_sitio'),
        barangay: document.getElementById('edit_barangay'),
        municipality: document.getElementById('edit_municipality'),
        province: document.getElementById('edit_province'),
        latitude: document.getElementById('edit_latitude'),
        longitude: document.getElementById('edit_longitude'),
      };

      const createFields = {
        barangay: document.getElementById('create_barangay'),
        municipality: document.getElementById('create_municipality'),
        province: document.getElementById('create_province'),
        latitude: document.getElementById('create_latitude'),
        longitude: document.getElementById('create_longitude'),
      };
      let createPicker = null;
      let editPicker = null;

      function toNumber(value, fallback) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : fallback;
      }

      function escapeHtml(value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function normalizeShape(shape) {
        const normalized = String(shape || '').toUpperCase();
        return ['CIRCLE', 'RECTANGLE', 'SQUARE', 'POLYGON'].includes(normalized) ? normalized : null;
      }

      function roundCoordinate(value) {
        return Math.round(Number(value) * 10000000) / 10000000;
      }

      function shapeLabel(shape) {
        const normalized = normalizeShape(shape);
        if (normalized === 'CIRCLE') return 'Circle';
        if (normalized === 'RECTANGLE') return 'Rectangle';
        if (normalized === 'SQUARE') return 'Square';
        if (normalized === 'POLYGON') return 'Polygon';
        return 'Not configured';
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
          const vertices = Array.isArray(geometry.vertices) ? geometry.vertices : [];
          const points = vertices.map(function (point) {
            if (!Array.isArray(point) || point.length < 2) {
              return null;
            }
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

      function farmPopupHtml(farm, latitude, longitude) {
        const locationLine = [farm.location, farm.sitio, farm.barangay, farm.municipality, farm.province]
          .filter(function (part) {
            return part !== null && String(part).trim() !== '';
          })
          .join(', ');

        const ownerLabel = farm.owner_name || farm.owner_username || 'Unassigned owner';
        const fence = farm.fence || {};
        const fenceShape = fence.shape_type || (fence.geometry ? fence.geometry.shape_type : null);
        const fenceLabel = fence.enabled ? shapeLabel(fenceShape) : 'Disabled';

        return [
          '<strong>' + escapeHtml(farm.farm_name || 'Farm') + '</strong>',
          'Owner: ' + escapeHtml(ownerLabel),
          locationLine ? escapeHtml(locationLine) : 'No location details',
          Number.isFinite(latitude) && Number.isFinite(longitude)
            ? 'Coordinates: ' + latitude.toFixed(7) + ', ' + longitude.toFixed(7)
            : 'Coordinates: Not set',
          'Fence: ' + escapeHtml(fenceLabel),
        ].join('<br />');
      }

      function populateEditForm(data) {
        const farmId = String(data.farm_id || '').trim();
        if (farmId === '' || !editForm || !editFarmIdInput) {
          return;
        }

        const actionTemplate = '{{ route('admin.maps.farms.update', ['farm' => '__FARM__']) }}';
        editForm.setAttribute('action', actionTemplate.replace('__FARM__', encodeURIComponent(farmId)));
        editFarmIdInput.value = farmId;

        Object.keys(editFields).forEach(function (key) {
          if (!editFields[key]) return;
          const value = data[key] === undefined || data[key] === null ? '' : String(data[key]);
          editFields[key].value = value;
        });

        if (editPicker) {
          editPicker.syncFromInputs(false);
        }
      }

      function populateDeleteForm(data) {
        const farmId = String(data.farm_id || '').trim();
        if (farmId === '' || !deleteForm || !deleteFarmIdInput) {
          return;
        }

        const actionTemplate = '{{ route('admin.maps.farms.destroy', ['farm' => '__FARM__']) }}';
        deleteForm.setAttribute('action', actionTemplate.replace('__FARM__', encodeURIComponent(farmId)));
        deleteFarmIdInput.value = farmId;

        if (deleteFarmNameNode) {
          deleteFarmNameNode.textContent = String(data.farm_name || 'this farm');
        }

        if (deleteCurrentPasswordInput) {
          deleteCurrentPasswordInput.value = '';
        }
      }

      document.querySelectorAll('.js-edit-farm').forEach(function (button) {
        button.addEventListener('click', function () {
          populateEditForm({
            farm_id: button.getAttribute('data-farm-id'),
            farm_name: button.getAttribute('data-farm-name'),
            owner_user_id: button.getAttribute('data-owner-user-id'),
            location: button.getAttribute('data-location'),
            sitio: button.getAttribute('data-sitio'),
            barangay: button.getAttribute('data-barangay'),
            municipality: button.getAttribute('data-municipality'),
            province: button.getAttribute('data-province'),
            latitude: button.getAttribute('data-latitude'),
            longitude: button.getAttribute('data-longitude'),
          });
          if (editModal) {
            editModal.show();
          }
        });
      });

      document.querySelectorAll('.js-delete-farm').forEach(function (button) {
        button.addEventListener('click', function () {
          populateDeleteForm({
            farm_id: button.getAttribute('data-farm-id'),
            farm_name: button.getAttribute('data-farm-name'),
          });
          if (deleteModal) {
            deleteModal.show();
          }
        });
      });

      const oldFormMode = @json(old('farm_form_mode'));
      const oldFarmId = @json(old('farm_id'));
      if (@json($errors->any())) {
        if (oldFormMode === 'create' && createModal) {
          createModal.show();
        } else if (oldFormMode === 'edit' && editModal) {
          const targetButton = oldFarmId
            ? document.querySelector('.js-edit-farm[data-farm-id="' + String(oldFarmId) + '"]')
            : null;

          if (targetButton) {
            populateEditForm({
              farm_id: targetButton.getAttribute('data-farm-id'),
              farm_name: targetButton.getAttribute('data-farm-name'),
              owner_user_id: targetButton.getAttribute('data-owner-user-id'),
              location: targetButton.getAttribute('data-location'),
              sitio: targetButton.getAttribute('data-sitio'),
              barangay: targetButton.getAttribute('data-barangay'),
              municipality: targetButton.getAttribute('data-municipality'),
              province: targetButton.getAttribute('data-province'),
              latitude: targetButton.getAttribute('data-latitude'),
              longitude: targetButton.getAttribute('data-longitude'),
            });
          }

          populateEditForm({
            farm_id: oldFarmId,
            farm_name: @json(old('farm_name')),
            owner_user_id: @json(old('owner_user_id')),
            location: @json(old('location')),
            sitio: @json(old('sitio')),
            barangay: @json(old('barangay')),
            municipality: @json(old('municipality')),
            province: @json(old('province')),
            latitude: @json(old('latitude')),
            longitude: @json(old('longitude')),
          });
          editModal.show();
        } else if (oldFormMode === 'delete' && deleteModal) {
          const targetDeleteButton = oldFarmId
            ? document.querySelector('.js-delete-farm[data-farm-id="' + String(oldFarmId) + '"]')
            : null;

          populateDeleteForm({
            farm_id: oldFarmId || (targetDeleteButton ? targetDeleteButton.getAttribute('data-farm-id') : ''),
            farm_name: targetDeleteButton ? targetDeleteButton.getAttribute('data-farm-name') : 'this farm',
          });
          deleteModal.show();
        }
      }

      if (deleteModalElement && deleteCurrentPasswordInput) {
        deleteModalElement.addEventListener('shown.bs.modal', function () {
          deleteCurrentPasswordInput.focus();
        });
      }

      if (createModalElement) {
        createModalElement.addEventListener('hidden.bs.modal', function () {
          if (@json($errors->any()) && oldFormMode === 'create') {
            return;
          }

          if (createFields.latitude) {
            createFields.latitude.value = '';
          }
          if (createFields.longitude) {
            createFields.longitude.value = '';
          }
          if (createPicker) {
            createPicker.clearCoordinates();
          }
        });
      }

      const mapElement = document.getElementById('farm-map-canvas');
      const payloadElement = document.getElementById('farm_map_payload');
      if (!mapElement || !payloadElement || typeof L === 'undefined') {
        return;
      }

      let payload = {};
      try {
        payload = JSON.parse(payloadElement.textContent || '{}');
      } catch (error) {
        payload = {};
      }

      const defaultCenter = payload.default_center || { latitude: 10.354727, longitude: 124.965980 };
      const farms = Array.isArray(payload.farms) ? payload.farms : [];
      const geofence = payload.geofence && typeof payload.geofence === 'object' ? payload.geofence : {};
      const reverseGeocodeUrl = @json(route('admin.maps.farms.reverse-geocode'));
      let geofenceGeometries = Array.isArray(geofence.geometries)
        ? geofence.geometries.filter(function (item) { return item && typeof item === 'object'; })
        : [];
      if (geofenceGeometries.length === 0 && geofence.geometry && typeof geofence.geometry === 'object') {
        geofenceGeometries = [geofence.geometry];
      }

      if (farmMapCount) {
        farmMapCount.textContent = farms.length + ' farm marker' + (farms.length === 1 ? '' : 's');
      }

      function createFarmLocationPicker(options) {
        const mapCanvas = document.getElementById(options.prefix + 'location_picker_map');
        const latitudeInput = document.getElementById(options.prefix + 'latitude');
        const longitudeInput = document.getElementById(options.prefix + 'longitude');
        const statusNode = document.getElementById(options.prefix + 'picker_status');
        const clearButton = document.getElementById(options.prefix + 'clear_pin');
        const currentLocationButton = document.getElementById(options.prefix + 'use_current_location');
        const modalElement = options.modalElement || null;
        const barangayInput = document.getElementById(options.prefix + 'barangay');
        const municipalityInput = document.getElementById(options.prefix + 'municipality');
        const provinceInput = document.getElementById(options.prefix + 'province');

        if (!mapCanvas || !latitudeInput || !longitudeInput || !modalElement) {
          return null;
        }

        let pickerMap = null;
        let pickerMarker = null;
        let reverseLookupController = null;
        const referenceBounds = L.latLngBounds([]);

        function setStatus(message) {
          if (statusNode) {
            statusNode.textContent = message;
          }
        }

        function readCoordinates() {
          const latitude = toNumber(latitudeInput.value, NaN);
          const longitude = toNumber(longitudeInput.value, NaN);

          if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            return null;
          }

          return {
            latitude: latitude,
            longitude: longitude,
          };
        }

        function writeCoordinates(latitude, longitude) {
          latitudeInput.value = roundCoordinate(latitude).toFixed(7);
          longitudeInput.value = roundCoordinate(longitude).toFixed(7);
          setStatus('Pinned coordinates: ' + roundCoordinate(latitude).toFixed(7) + ', ' + roundCoordinate(longitude).toFixed(7));
        }

        function applyReverseGeocode(data) {
          if (!data || typeof data !== 'object') {
            return;
          }

          if (barangayInput && data.barangay) {
            barangayInput.value = String(data.barangay);
          }
          if (municipalityInput && data.municipality) {
            municipalityInput.value = String(data.municipality);
          }
          if (provinceInput && data.province) {
            provinceInput.value = String(data.province);
          }
        }

        function fetchReverseGeocode(latitude, longitude) {
          if (!reverseGeocodeUrl || typeof window.fetch !== 'function') {
            return;
          }

          if (reverseLookupController) {
            reverseLookupController.abort();
          }

          reverseLookupController = new AbortController();
          setStatus('Pinned coordinates: ' + roundCoordinate(latitude).toFixed(7) + ', ' + roundCoordinate(longitude).toFixed(7) + ' | Looking up address...');

          const params = new URLSearchParams({
            latitude: roundCoordinate(latitude).toFixed(7),
            longitude: roundCoordinate(longitude).toFixed(7),
          });

          window.fetch(reverseGeocodeUrl + '?' + params.toString(), {
            method: 'GET',
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            signal: reverseLookupController.signal,
          })
            .then(function(response) {
              if (!response.ok) {
                throw new Error('Reverse geocoding failed.');
              }
              return response.json();
            })
            .then(function(payload) {
              if (!payload || payload.ok !== true || !payload.data || typeof payload.data !== 'object') {
                throw new Error('Reverse geocoding failed.');
              }

              applyReverseGeocode(payload.data);

              const parts = [payload.data.barangay, payload.data.municipality, payload.data.province]
                .filter(function(part) {
                  return typeof part === 'string' && part.trim() !== '';
                });

              if (parts.length > 0) {
                setStatus('Pinned coordinates: ' + roundCoordinate(latitude).toFixed(7) + ', ' + roundCoordinate(longitude).toFixed(7) + ' | Autofilled: ' + parts.join(', '));
              } else if (payload.data.display_name) {
                setStatus('Pinned coordinates: ' + roundCoordinate(latitude).toFixed(7) + ', ' + roundCoordinate(longitude).toFixed(7) + ' | Address found, but some fields still need review.');
              } else {
                setStatus('Pinned coordinates: ' + roundCoordinate(latitude).toFixed(7) + ', ' + roundCoordinate(longitude).toFixed(7) + ' | No matching address fields were found.');
              }
            })
            .catch(function(error) {
              if (error && error.name === 'AbortError') {
                return;
              }

              setStatus('Pinned coordinates: ' + roundCoordinate(latitude).toFixed(7) + ', ' + roundCoordinate(longitude).toFixed(7) + ' | Unable to autofill address. Fill in the fields manually if needed.');
            });
        }

        function clearCoordinates() {
          if (reverseLookupController) {
            reverseLookupController.abort();
            reverseLookupController = null;
          }

          latitudeInput.value = '';
          longitudeInput.value = '';

          if (pickerMap && pickerMarker) {
            pickerMap.removeLayer(pickerMarker);
            pickerMarker = null;
          }

          setStatus('No map pin selected yet.');

          if (pickerMap) {
            if (referenceBounds.isValid()) {
              pickerMap.fitBounds(referenceBounds, { padding: [20, 20] });
            } else {
              pickerMap.setView(
                [toNumber(defaultCenter.latitude, 10.354727), toNumber(defaultCenter.longitude, 124.965980)],
                12
              );
            }
          }
        }

        function setMarker(latitude, longitude, fitToPoint) {
          const latLng = [latitude, longitude];

          if (!pickerMarker) {
            pickerMarker = L.marker(latLng, {
              draggable: true,
              title: 'Selected farm coordinates',
            }).addTo(pickerMap);

            pickerMarker.on('dragend', function(event) {
              const markerLatLng = event.target.getLatLng();
              writeCoordinates(markerLatLng.lat, markerLatLng.lng);
            });
          } else {
            pickerMarker.setLatLng(latLng);
          }

          writeCoordinates(latitude, longitude);
          fetchReverseGeocode(latitude, longitude);

          if (fitToPoint !== false) {
            pickerMap.setView(latLng, Math.max(pickerMap.getZoom(), 16));
          }
        }

        function syncFromInputs(fitToPoint) {
          const coordinates = readCoordinates();
          if (!coordinates) {
            clearCoordinates();
            return;
          }

          setMarker(coordinates.latitude, coordinates.longitude, fitToPoint);
        }

        function ensureMap() {
          if (pickerMap) {
            return pickerMap;
          }

          const pickerBaseLayers = createBaseLayers();
          pickerMap = L.map(mapCanvas, { zoomControl: true }).setView(
            [toNumber(defaultCenter.latitude, 10.354727), toNumber(defaultCenter.longitude, 124.965980)],
            12
          );

          pickerBaseLayers.Standard.addTo(pickerMap);
          L.control.layers(pickerBaseLayers, null, { collapsed: true }).addTo(pickerMap);

          geofenceGeometries.forEach(function (geometry) {
            const layer = buildLayerFromGeometry(geometry, {
              color: '#696cff',
              dashArray: '6 4',
              weight: 2,
              fillColor: '#696cff',
              fillOpacity: 0.08,
            });

            if (!layer) {
              return;
            }

            layer.addTo(pickerMap);
            if (typeof layer.getBounds === 'function') {
              referenceBounds.extend(layer.getBounds());
            }
          });

          farms.forEach(function (farm) {
            const latitude = toNumber(farm.latitude, NaN);
            const longitude = toNumber(farm.longitude, NaN);

            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
              return;
            }

            referenceBounds.extend([latitude, longitude]);

            const marker = L.circleMarker([latitude, longitude], {
              radius: 5,
              color: '#0aa2c0',
              weight: 2,
              fillColor: '#0aa2c0',
              fillOpacity: 0.18,
            }).addTo(pickerMap);

            marker.bindTooltip(String(farm.farm_name || 'Farm'), {
              direction: 'top',
              offset: [0, -6],
            });
          });

          pickerMap.on('click', function(event) {
            setMarker(event.latlng.lat, event.latlng.lng, false);
          });

          if (referenceBounds.isValid()) {
            pickerMap.fitBounds(referenceBounds, { padding: [20, 20] });
          }

          syncFromInputs(false);

          return pickerMap;
        }

        if (clearButton) {
          clearButton.addEventListener('click', function () {
            ensureMap();
            clearCoordinates();
          });
        }

        if (currentLocationButton) {
          currentLocationButton.addEventListener('click', function () {
            ensureMap();

            if (!navigator.geolocation) {
              setStatus('Current location is not available in this browser.');
              return;
            }

            setStatus('Fetching current location...');
            navigator.geolocation.getCurrentPosition(
              function(position) {
                setMarker(position.coords.latitude, position.coords.longitude, true);
              },
              function() {
                setStatus('Unable to read current location. Drop the pin manually on the map.');
              },
              {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0,
              }
            );
          });
        }

        modalElement.addEventListener('shown.bs.modal', function () {
          ensureMap();
          window.setTimeout(function() {
            pickerMap.invalidateSize();
            syncFromInputs(false);
          }, 120);
        });

        return {
          syncFromInputs: function(fitToPoint) {
            ensureMap();
            syncFromInputs(fitToPoint);
          },
          clearCoordinates: function() {
            ensureMap();
            clearCoordinates();
          },
        };
      }

      createPicker = createFarmLocationPicker({
        prefix: 'create_',
        modalElement: createModalElement,
      });

      editPicker = createFarmLocationPicker({
        prefix: 'edit_',
        modalElement: editModalElement,
      });

      const baseLayers = createBaseLayers();
      const map = L.map(mapElement).setView(
        [toNumber(defaultCenter.latitude, 10.354727), toNumber(defaultCenter.longitude, 124.965980)],
        12
      );

      baseLayers.Standard.addTo(map);
      L.control.layers(baseLayers, null, { collapsed: false }).addTo(map);

      const bounds = L.latLngBounds([]);

      geofenceGeometries.forEach(function (geometry) {
        const layer = buildLayerFromGeometry(geometry, {
          color: '#696cff',
          dashArray: '6 4',
          weight: 2,
          fillColor: '#696cff',
          fillOpacity: 0.1,
        });
        if (layer) {
          layer.addTo(map);
          if (typeof layer.getBounds === 'function') {
            bounds.extend(layer.getBounds());
          }
        }
      });

      farms.forEach(function (farm) {
        const fence = farm.fence && typeof farm.fence === 'object' ? farm.fence : null;
        if (fence && fence.enabled && fence.geometry) {
          const fenceLayer = buildLayerFromGeometry(fence.geometry, {
            color: '#28c76f',
            weight: 2,
            fillColor: '#28c76f',
            fillOpacity: 0.1,
          });
          if (fenceLayer) {
            fenceLayer.addTo(map);
            fenceLayer.bindPopup(farmPopupHtml(farm, NaN, NaN));
            if (typeof fenceLayer.getBounds === 'function') {
              bounds.extend(fenceLayer.getBounds());
            }
          }
        }

        const latitude = toNumber(farm.latitude, NaN);
        const longitude = toNumber(farm.longitude, NaN);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
          return;
        }

        const marker = L.marker([latitude, longitude]).addTo(map);
        marker.bindPopup(farmPopupHtml(farm, latitude, longitude));
        marker.bindTooltip(String(farm.owner_name || farm.owner_username || 'Unassigned owner'));
        bounds.extend([latitude, longitude]);
      });

      if (bounds.isValid()) {
        map.fitBounds(bounds, { padding: [20, 20] });
      }
    });
  </script>
@endsection
