@extends('layouts.admin')

@section('title', 'APEWSD - Device Registry')

@section('content')
  <div class="row">
    <div class="col-12">
      <h4 class="mb-1">Device Registry</h4>
      <p class="mb-6">Register ESP32 boards, assign them to owner farms, and manage device lifecycle and API credentials.</p>
    </div>
  </div>

  @if (session('status'))
    <div class="alert alert-success" role="alert">
      {{ session('status') }}
    </div>
  @endif

  @if (session('device_api_key'))
    <div class="alert alert-warning" role="alert">
      <div class="fw-semibold mb-1">
        API key for {{ session('device_api_key_serial') ? 'device ' . session('device_api_key_serial') : 'this device' }}
      </div>
      <div class="small mb-2">Keep this API key secure. Admin password is required to show it again.</div>
      <code class="text-dark">{{ session('device_api_key') }}</code>
    </div>
  @endif

  @if ($errors->any())
    <div class="alert alert-danger" role="alert">
      {{ $errors->first() }}
    </div>
  @endif

  <div class="card">
    <div class="card-header py-3">
      <div class="row g-2 align-items-center">
        <div class="col-12 col-md">
          <form method="GET" action="{{ route('admin.devices.index') }}" class="d-flex gap-2 mb-0 device-search-form">
            <input
              type="text"
              name="q"
              class="form-control"
              placeholder="Search serial, board, owner, or farm"
              value="{{ $search }}" />
            <button type="submit" class="btn btn-primary text-nowrap device-toolbar-button">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/find/icons/icons8-search--v2.png',
                'alt' => 'Search',
                'classes' => 'device-button-icon me-1',
              ])
              Search
            </button>
          </form>
        </div>
        <div class="col-12 col-md-auto">
          <button
            type="button"
            class="btn btn-primary device-toolbar-button"
            data-bs-toggle="modal"
            data-bs-target="#createDeviceModal">
            @include('partials.curated-shell-icon', [
              'src' => 'resources/icons/dusk/add/icons/icons8-add-database.png',
              'alt' => 'Add device',
              'classes' => 'device-button-icon me-1',
            ])
            Add Device
          </button>
        </div>
      </div>
    </div>

    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Module / Board</th>
              <th>Primary Serial</th>
              <th>Owner</th>
              <th>Farm</th>
              <th>Status</th>
              <th>Last Seen</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($devices as $device)
              @php
                $aliasesText = $device->aliases->pluck('serial_no')->implode(PHP_EOL);
              @endphp
              <tr>
                <td>{{ $device->module_board_name }}</td>
                <td>
                  <div class="fw-semibold">{{ $device->primary_serial_no }}</div>
                  @if ($device->aliases->isNotEmpty())
                    <div class="small text-body-secondary">
                      Aliases: {{ $device->aliases->pluck('serial_no')->join(', ') }}
                    </div>
                  @endif
                </td>
                <td>{{ $device->owner?->full_name ?? '-' }}</td>
                <td>
                  {{ $device->farm?->farm_name ?? '-' }}
                  @if ($device->farm)
                    @php
                      $deviceFarmLocation = collect([
                          $device->farm->location,
                          $device->farm->sitio,
                          $device->farm->barangay,
                          $device->farm->municipality,
                          $device->farm->province,
                      ])->filter(static fn ($value) => is_string($value) && trim($value) !== '')->implode(', ');
                    @endphp
                    @if ($deviceFarmLocation !== '')
                      <div class="small text-body-secondary">{{ $deviceFarmLocation }}</div>
                    @endif
                  @endif
                </td>
                <td>
                  @if ($device->is_active)
                    <span class="device-dusk-status">
                      @include('partials.curated-shell-icon', [
                        'src' => 'resources/icons/dusk/hand/animated/icons8-ok-hand--v2.gif',
                        'alt' => 'Active',
                        'classes' => 'device-status-icon',
                      ])
                      Active
                    </span>
                  @else
                    <span class="badge bg-label-danger">Inactive</span>
                  @endif
                </td>
                <td>
                  @if ($device->last_seen_at)
                    {{ $device->last_seen_at->format('Y-m-d H:i') }}
                    @if ($device->last_seen_ip)
                      <div class="small text-body-secondary">{{ $device->last_seen_ip }}</div>
                    @endif
                  @else
                    <span class="text-body-secondary">Never</span>
                  @endif
                </td>
                <td>
                  <div class="admin-row-actions">
                    <button
                      type="button"
                      class="btn btn-sm btn-icon btn-outline-primary admin-row-action-btn edit-device-btn"
                      data-bs-toggle="modal"
                      data-bs-target="#editDeviceModal"
                      aria-label="Edit device {{ $device->primary_serial_no }}"
                      data-action-label="Edit"
                      data-device-id="{{ $device->id }}"
                      data-device-action="{{ route('admin.devices.update', $device) }}"
                      data-module-board-name="{{ $device->module_board_name }}"
                      data-primary-serial-no="{{ $device->primary_serial_no }}"
                      data-owner-user-id="{{ $device->owner_user_id }}"
                      data-farm-id="{{ $device->farm_id }}"
                      data-main-technical-specs="{{ $device->main_technical_specs }}"
                      data-processing-memory="{{ $device->processing_memory }}"
                      data-gpio-interfaces="{{ $device->gpio_interfaces }}"
                      data-aliases-text="{{ $aliasesText }}">
                      @include('partials.curated-shell-icon', [
                        'src' => 'resources/icons/dusk/edit/icons/icons8-edit-user-male.png',
                        'alt' => 'Edit device',
                        'classes' => 'device-row-action-icon',
                      ])
                    </button>

                    <button
                      type="button"
                      class="btn btn-sm btn-icon btn-outline-info admin-row-action-btn show-key-btn"
                      data-bs-toggle="modal"
                      data-bs-target="#showDeviceKeyModal"
                      aria-label="Show API key for device {{ $device->primary_serial_no }}"
                      data-action-label="Show Key"
                      data-device-id="{{ $device->id }}"
                      data-device-serial="{{ $device->primary_serial_no }}"
                      data-device-show-key-action="{{ route('admin.devices.show-key', $device) }}">
                      @include('partials.curated-shell-icon', [
                        'src' => 'resources/icons/dusk/key/icons/icons8-key-security.png',
                        'alt' => 'Show key',
                        'classes' => 'device-row-action-icon',
                      ])
                    </button>

                    <form method="POST" action="{{ route('admin.devices.rotate-key', $device) }}">
                      @csrf
                      <button
                        type="submit"
                        class="btn btn-sm btn-icon btn-outline-warning admin-row-action-btn"
                        aria-label="Rotate API key for device {{ $device->primary_serial_no }}"
                        data-action-label="Rotate Key">
                        @include('partials.curated-shell-icon', [
                          'src' => 'resources/icons/dusk/circle/animated/icons8-refresh--v2.gif',
                          'alt' => 'Rotate key',
                          'classes' => 'device-row-action-icon',
                        ])
                      </button>
                    </form>

                    @if ($device->is_active)
                      <form method="POST" action="{{ route('admin.devices.deactivate', $device) }}">
                        @csrf
                        @method('PATCH')
                        <button
                          type="submit"
                          class="btn btn-sm btn-icon btn-outline-danger admin-row-action-btn"
                          aria-label="Deactivate device {{ $device->primary_serial_no }}"
                          data-action-label="Deactivate">
                          @include('partials.curated-shell-icon', [
                            'src' => 'resources/icons/dusk/computer/icons/icons8-power-off-button.png',
                            'alt' => 'Deactivate device',
                            'classes' => 'device-row-action-icon',
                          ])
                        </button>
                      </form>
                    @else
                      <form method="POST" action="{{ route('admin.devices.reactivate', $device) }}">
                        @csrf
                        @method('PATCH')
                        <button
                          type="submit"
                          class="btn btn-sm btn-icon btn-outline-success admin-row-action-btn"
                          aria-label="Reactivate device {{ $device->primary_serial_no }}"
                          data-action-label="Reactivate">
                          @include('partials.curated-shell-icon', [
                            'src' => 'resources/icons/dusk/check/icons/icons8-ok--v2.png',
                            'alt' => 'Reactivate device',
                            'classes' => 'device-row-action-icon',
                          ])
                        </button>
                      </form>
                    @endif
                  </div>
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="7" class="text-center text-body-secondary py-5">No devices found.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2 device-table-footer">
      <div class="text-body-secondary">
        Showing {{ $devices->firstItem() ?? 0 }} to {{ $devices->lastItem() ?? 0 }} of {{ $devices->total() }} devices
      </div>
      <div class="d-flex gap-2 device-table-pagination">
        @if ($devices->previousPageUrl())
          <a href="{{ $devices->previousPageUrl() }}" class="btn btn-sm btn-outline-secondary device-toolbar-button">
            @include('partials.curated-shell-icon', [
              'src' => 'resources/icons/dusk/key/icons/icons8-left-arrow-key.png',
              'alt' => 'Previous',
              'classes' => 'device-button-icon me-1',
            ])
            Previous
          </a>
        @endif
        @if ($devices->nextPageUrl())
          <a href="{{ $devices->nextPageUrl() }}" class="btn btn-sm btn-outline-secondary device-toolbar-button">
            @include('partials.curated-shell-icon', [
              'src' => 'resources/icons/dusk/computer/icons/icons8-right-arrow-key.png',
              'alt' => 'Next',
              'classes' => 'device-button-icon me-1',
            ])
            Next
          </a>
        @endif
      </div>
    </div>
  </div>

  <div class="modal fade" id="createDeviceModal" tabindex="-1" aria-labelledby="createDeviceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="createDeviceModalLabel">Register Device</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form action="{{ route('admin.devices.store') }}" method="POST" id="create-device-form">
          @csrf
          <input type="hidden" name="device_form_mode" value="create" />
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label" for="create_module_board_name">Module / Board Name</label>
                <input
                  type="text"
                  id="create_module_board_name"
                  name="module_board_name"
                  class="form-control"
                  value="{{ old('device_form_mode') === 'create' ? old('module_board_name') : '' }}"
                  maxlength="120"
                  required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="create_primary_serial_no">Primary Serial No.</label>
                <input
                  type="text"
                  id="create_primary_serial_no"
                  name="primary_serial_no"
                  class="form-control"
                  value="{{ old('device_form_mode') === 'create' ? old('primary_serial_no') : '' }}"
                  maxlength="120"
                  required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="create_owner_user_id">Poultry Owner</label>
                <select id="create_owner_user_id" name="owner_user_id" class="form-select owner-select" data-farm-target="create_farm_id" required>
                  <option value="">Select owner</option>
                  @foreach ($owners as $owner)
                    <option
                      value="{{ $owner->id }}"
                      @selected(old('device_form_mode') === 'create' && (int) old('owner_user_id') === (int) $owner->id)>
                      {{ $owner->full_name }} ({{ $owner->username }})
                    </option>
                  @endforeach
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="create_farm_id">Farm</label>
                <select id="create_farm_id" name="farm_id" class="form-select farm-select" required>
                  <option value="">Select farm</option>
                  @foreach ($farms as $farm)
                    @php
                      $farmLocationLabel = collect([
                          $farm->location,
                          $farm->sitio,
                          $farm->barangay,
                          $farm->municipality,
                          $farm->province,
                      ])->filter(static fn ($value) => is_string($value) && trim($value) !== '')->implode(', ');
                    @endphp
                    <option
                      value="{{ $farm->id }}"
                      data-owner-user-id="{{ $farm->owner_user_id }}"
                      data-location-label="{{ $farmLocationLabel }}"
                      @selected(old('device_form_mode') === 'create' && (int) old('farm_id') === (int) $farm->id)>
                      {{ $farm->farm_name }}
                    </option>
                  @endforeach
                </select>
                <div class="form-text farm-location-text" id="create_farm_location_text">Select owner and farm to view assigned location.</div>
                <div class="form-text">
                  Need to update farm ownership or location? <a href="{{ route('admin.maps.farms') }}">Manage farms in Farm &amp; Map</a>.
                </div>
              </div>
              <div class="col-12">
                <label class="form-label" for="create_aliases_text">Additional Serial Nos. (aliases)</label>
                <textarea
                  id="create_aliases_text"
                  name="aliases_text"
                  class="form-control"
                  rows="2"
                  placeholder="Separate by comma or new line">{{ old('device_form_mode') === 'create' ? old('aliases_text') : '' }}</textarea>
              </div>
              <div class="col-12">
                <label class="form-label" for="create_main_technical_specs">Main Technical Specifications</label>
                <textarea
                  id="create_main_technical_specs"
                  name="main_technical_specs"
                  class="form-control"
                  rows="2">{{ old('device_form_mode') === 'create' ? old('main_technical_specs') : '' }}</textarea>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="create_processing_memory">Processing and Memory</label>
                <textarea
                  id="create_processing_memory"
                  name="processing_memory"
                  class="form-control"
                  rows="2">{{ old('device_form_mode') === 'create' ? old('processing_memory') : '' }}</textarea>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="create_gpio_interfaces">GPIO and Interfaces</label>
                <textarea
                  id="create_gpio_interfaces"
                  name="gpio_interfaces"
                  class="form-control"
                  rows="2">{{ old('device_form_mode') === 'create' ? old('gpio_interfaces') : '' }}</textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary device-toolbar-button" data-bs-dismiss="modal">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/delete/icons/icons8-cancel--v2.png',
                'alt' => 'Cancel',
                'classes' => 'device-button-icon me-1',
              ])
              Cancel
            </button>
            <button type="submit" class="btn btn-primary device-toolbar-button">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/add/icons/icons8-add-database.png',
                'alt' => 'Create device',
                'classes' => 'device-button-icon me-1',
              ])
              Create Device
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="editDeviceModal" tabindex="-1" aria-labelledby="editDeviceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editDeviceModalLabel">Edit Device</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" id="edit-device-form">
          @csrf
          @method('PUT')
          <input type="hidden" name="device_form_mode" value="edit" />
          <input type="hidden" name="device_id" id="edit_device_id" value="" />
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label" for="edit_module_board_name">Module / Board Name</label>
                <input type="text" id="edit_module_board_name" name="module_board_name" class="form-control" maxlength="120" required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="edit_primary_serial_no">Primary Serial No.</label>
                <input type="text" id="edit_primary_serial_no" name="primary_serial_no" class="form-control" maxlength="120" required />
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="edit_owner_user_id">Poultry Owner</label>
                <select id="edit_owner_user_id" name="owner_user_id" class="form-select owner-select" data-farm-target="edit_farm_id" required>
                  <option value="">Select owner</option>
                  @foreach ($owners as $owner)
                    <option value="{{ $owner->id }}">{{ $owner->full_name }} ({{ $owner->username }})</option>
                  @endforeach
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="edit_farm_id">Farm</label>
                <select id="edit_farm_id" name="farm_id" class="form-select farm-select" required>
                  <option value="">Select farm</option>
                  @foreach ($farms as $farm)
                    @php
                      $farmLocationLabel = collect([
                          $farm->location,
                          $farm->sitio,
                          $farm->barangay,
                          $farm->municipality,
                          $farm->province,
                      ])->filter(static fn ($value) => is_string($value) && trim($value) !== '')->implode(', ');
                    @endphp
                    <option value="{{ $farm->id }}" data-owner-user-id="{{ $farm->owner_user_id }}" data-location-label="{{ $farmLocationLabel }}">
                      {{ $farm->farm_name }}
                    </option>
                  @endforeach
                </select>
                <div class="form-text farm-location-text" id="edit_farm_location_text">Select owner and farm to view assigned location.</div>
                <div class="form-text">
                  Need to update farm ownership or location? <a href="{{ route('admin.maps.farms') }}">Manage farms in Farm &amp; Map</a>.
                </div>
              </div>
              <div class="col-12">
                <label class="form-label" for="edit_aliases_text">Additional Serial Nos. (aliases)</label>
                <textarea id="edit_aliases_text" name="aliases_text" class="form-control" rows="2" placeholder="Separate by comma or new line"></textarea>
              </div>
              <div class="col-12">
                <label class="form-label" for="edit_main_technical_specs">Main Technical Specifications</label>
                <textarea id="edit_main_technical_specs" name="main_technical_specs" class="form-control" rows="2"></textarea>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="edit_processing_memory">Processing and Memory</label>
                <textarea id="edit_processing_memory" name="processing_memory" class="form-control" rows="2"></textarea>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label" for="edit_gpio_interfaces">GPIO and Interfaces</label>
                <textarea id="edit_gpio_interfaces" name="gpio_interfaces" class="form-control" rows="2"></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary device-toolbar-button" data-bs-dismiss="modal">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/delete/icons/icons8-cancel--v2.png',
                'alt' => 'Cancel',
                'classes' => 'device-button-icon me-1',
              ])
              Cancel
            </button>
            <button type="submit" class="btn btn-primary device-toolbar-button">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/save/animated/icons8-save--v2.gif',
                'alt' => 'Save changes',
                'classes' => 'device-button-icon me-1',
              ])
              Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="showDeviceKeyModal" tabindex="-1" aria-labelledby="showDeviceKeyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="showDeviceKeyModalLabel">Show Device API Key</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" id="show-device-key-form">
          @csrf
          <div class="modal-body">
            <input type="hidden" name="device_action_mode" value="reveal-key" />
            <input type="hidden" name="device_id" id="show_device_id" value="{{ old('device_action_mode') === 'reveal-key' ? old('device_id') : '' }}" />
            <p class="mb-3">
              Re-enter the admin password to display the current API key for
              <span class="fw-semibold" id="show_device_serial">{{ old('device_action_mode') === 'reveal-key' ? ('device #' . old('device_id')) : 'this device' }}</span>.
            </p>
            <div class="mb-0">
              <label class="form-label" for="show_current_password">Confirm Admin Password</label>
              <input
                type="password"
                id="show_current_password"
                name="current_password"
                class="form-control"
                autocomplete="current-password"
                required />
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary device-toolbar-button" data-bs-dismiss="modal">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/delete/icons/icons8-cancel--v2.png',
                'alt' => 'Cancel',
                'classes' => 'device-button-icon me-1',
              ])
              Cancel
            </button>
            <button type="submit" class="btn btn-info device-toolbar-button">
              @include('partials.curated-shell-icon', [
                'src' => 'resources/icons/dusk/key/icons/icons8-key-security.png',
                'alt' => 'Show API key',
                'classes' => 'device-button-icon me-1',
              ])
              Show API Key
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <style>
    .device-toolbar-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .device-button-icon,
    .device-row-action-icon,
    .device-status-icon {
      width: 1rem;
      height: 1rem;
      display: inline-block;
      flex-shrink: 0;
    }

    .device-dusk-status {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      color: #566a7f;
      font-size: 0.75rem;
      font-weight: 600;
      line-height: 1;
      white-space: nowrap;
    }

    @media (max-width: 767.98px) {
      .device-search-form,
      .device-table-pagination {
        width: 100%;
      }

      .device-search-form,
      .device-table-footer,
      .device-table-pagination {
        flex-direction: column;
        align-items: stretch !important;
      }

      .device-search-form > *,
      .device-table-pagination > * {
        width: 100%;
      }
    }
  </style>

  @php
    $deviceOldInput = [
        'device_form_mode' => old('device_form_mode'),
        'device_action_mode' => old('device_action_mode'),
        'device_id' => old('device_id'),
        'module_board_name' => old('module_board_name'),
        'primary_serial_no' => old('primary_serial_no'),
        'owner_user_id' => old('owner_user_id'),
        'farm_id' => old('farm_id'),
        'aliases_text' => old('aliases_text'),
        'main_technical_specs' => old('main_technical_specs'),
        'processing_memory' => old('processing_memory'),
        'gpio_interfaces' => old('gpio_interfaces'),
    ];
  @endphp

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const ownerFarmsEndpoint = @json(route('admin.devices.owner-farms'));
      const updateRouteTemplate = @json(route('admin.devices.update', ['device' => '__DEVICE__']));
      const openModal = @json($openModal);
      const openEditDeviceId = @json($openEditDeviceId);
      const openRevealDeviceId = @json($openRevealDeviceId);
      const oldInput = @json($deviceOldInput);

      const createModalElement = document.getElementById('createDeviceModal');
      const editModalElement = document.getElementById('editDeviceModal');
      const showKeyModalElement = document.getElementById('showDeviceKeyModal');
      const editForm = document.getElementById('edit-device-form');
      const editDeviceIdInput = document.getElementById('edit_device_id');
      const showKeyForm = document.getElementById('show-device-key-form');
      const showDeviceIdInput = document.getElementById('show_device_id');
      const showDeviceSerial = document.getElementById('show_device_serial');
      const showCurrentPasswordInput = document.getElementById('show_current_password');
      const editFields = {
        module_board_name: document.getElementById('edit_module_board_name'),
        primary_serial_no: document.getElementById('edit_primary_serial_no'),
        owner_user_id: document.getElementById('edit_owner_user_id'),
        farm_id: document.getElementById('edit_farm_id'),
        aliases_text: document.getElementById('edit_aliases_text'),
        main_technical_specs: document.getElementById('edit_main_technical_specs'),
        processing_memory: document.getElementById('edit_processing_memory'),
        gpio_interfaces: document.getElementById('edit_gpio_interfaces'),
      };

      const createModal = createModalElement && typeof window.bootstrap !== 'undefined'
        ? new window.bootstrap.Modal(createModalElement)
        : null;

      const editModal = editModalElement && typeof window.bootstrap !== 'undefined'
        ? new window.bootstrap.Modal(editModalElement)
        : null;

      const showKeyModal = showKeyModalElement && typeof window.bootstrap !== 'undefined'
        ? new window.bootstrap.Modal(showKeyModalElement)
        : null;

      const getFarmLocationTextElement = (farmSelect) => {
        if (!(farmSelect instanceof HTMLSelectElement) || !farmSelect.id) {
          return null;
        }

        const locationTextId = farmSelect.id.replace('_id', '_location_text');
        const locationTextElement = document.getElementById(locationTextId);

        return locationTextElement instanceof HTMLElement ? locationTextElement : null;
      };

      const updateFarmLocationText = (farmSelect, message, isError = false) => {
        const locationTextElement = getFarmLocationTextElement(farmSelect);
        if (!locationTextElement) {
          return;
        }

        locationTextElement.textContent = message;
        locationTextElement.classList.toggle('text-danger', isError);
      };

      const refreshFarmLocationTextFromSelection = (farmSelect) => {
        if (!(farmSelect instanceof HTMLSelectElement)) {
          return;
        }

        const selectedOption = farmSelect.options[farmSelect.selectedIndex] ?? null;
        if (!selectedOption || selectedOption.value === '') {
          updateFarmLocationText(farmSelect, 'Select owner and farm to view assigned location.');
          return;
        }

        const locationLabel = String(selectedOption.getAttribute('data-location-label') || '').trim();
        if (locationLabel === '') {
          updateFarmLocationText(farmSelect, 'Location not set for this farm.');
          return;
        }

        updateFarmLocationText(farmSelect, 'Location: ' + locationLabel);
      };

      const resetFarmSelect = (farmSelect, placeholder, disabled = true, locationMessage = 'Select owner and farm to view assigned location.', isError = false) => {
        farmSelect.innerHTML = '';

        const option = document.createElement('option');
        option.value = '';
        option.textContent = placeholder;

        farmSelect.appendChild(option);
        farmSelect.value = '';
        farmSelect.disabled = disabled;

        updateFarmLocationText(farmSelect, locationMessage, isError);
      };

      const renderFarmOptions = (farmSelect, farms, preferredFarmId = '') => {
        const normalizedPreferredFarmId = Number(preferredFarmId || 0);

        if (!Array.isArray(farms) || farms.length === 0) {
          resetFarmSelect(farmSelect, 'No active farms found', true, 'No active farms are assigned to this owner yet.');
          return;
        }

        farmSelect.innerHTML = '';

        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = 'Select farm';
        farmSelect.appendChild(placeholderOption);

        let hasPreferred = false;

        farms.forEach((farm) => {
          const option = document.createElement('option');
          option.value = String(farm.id);
          const farmName = String(farm.farm_name ?? '');
          const locationLabel = String(farm.location_label ?? '').trim();

          option.textContent = locationLabel !== '' ? `${farmName} - ${locationLabel}` : farmName;
          option.setAttribute('data-owner-user-id', String(farm.owner_user_id ?? ''));
          option.setAttribute('data-location-label', locationLabel);

          if (Number(farm.id) === normalizedPreferredFarmId) {
            hasPreferred = true;
          }

          farmSelect.appendChild(option);
        });

        farmSelect.disabled = false;
        farmSelect.value = hasPreferred ? String(normalizedPreferredFarmId) : '';
        refreshFarmLocationTextFromSelection(farmSelect);
      };

      const fetchOwnerFarms = async (ownerUserId) => {
        const endpoint = new URL(ownerFarmsEndpoint, window.location.origin);
        endpoint.searchParams.set('owner_user_id', String(ownerUserId));

        const response = await fetch(endpoint.toString(), {
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        });

        const contentType = response.headers.get('content-type') || '';
        const payload = contentType.includes('application/json')
          ? await response.json().catch(() => ({}))
          : {};

        if (!response.ok || !contentType.includes('application/json') || payload.ok === false) {
          throw new Error(typeof payload.message === 'string' ? payload.message : 'Unable to load farms.');
        }

        return Array.isArray(payload.data) ? payload.data : [];
      };

      const refreshFarmOptions = async (ownerSelect, preferredFarmId = '') => {
        if (!(ownerSelect instanceof HTMLSelectElement)) {
          return;
        }

        const farmTargetId = ownerSelect.getAttribute('data-farm-target');
        if (!farmTargetId) {
          return;
        }

        const farmSelect = document.getElementById(farmTargetId);
        if (!(farmSelect instanceof HTMLSelectElement)) {
          return;
        }

        const selectedOwnerId = Number(ownerSelect.value || 0);
        if (selectedOwnerId <= 0) {
          resetFarmSelect(farmSelect, 'Select farm', true, 'Select an owner first to load available farms.');
          return;
        }

        resetFarmSelect(farmSelect, 'Loading farms...', true, 'Loading farms for selected owner...');

        const requestNonce = String(Date.now()) + Math.random().toString(16).slice(2);
        farmSelect.dataset.requestNonce = requestNonce;

        try {
          const farms = await fetchOwnerFarms(selectedOwnerId);

          if (farmSelect.dataset.requestNonce !== requestNonce) {
            return;
          }

          renderFarmOptions(farmSelect, farms, preferredFarmId);
        } catch (error) {
          if (farmSelect.dataset.requestNonce !== requestNonce) {
            return;
          }

          resetFarmSelect(farmSelect, 'Unable to load farms', true, 'Unable to load farms right now. Please retry.', true);
          console.error(error);
        }
      };

      document.querySelectorAll('.farm-select').forEach((farmSelect) => {
        farmSelect.addEventListener('change', function () {
          refreshFarmLocationTextFromSelection(farmSelect);
        });

        refreshFarmLocationTextFromSelection(farmSelect);
      });

      document.querySelectorAll('.owner-select').forEach((ownerSelect) => {
        ownerSelect.addEventListener('change', function () {
          refreshFarmOptions(ownerSelect);
        });

        const farmTargetId = ownerSelect.getAttribute('data-farm-target');
        const farmSelect = farmTargetId ? document.getElementById(farmTargetId) : null;
        const currentFarmId = farmSelect instanceof HTMLSelectElement ? farmSelect.value : '';

        refreshFarmOptions(ownerSelect, currentFarmId);
      });

      const hydrateEditForm = (payload) => {
        if (!editForm) {
          return;
        }

        const deviceId = String(payload.device_id || '');
        editForm.action = updateRouteTemplate.replace('__DEVICE__', deviceId);
        if (editDeviceIdInput) {
          editDeviceIdInput.value = deviceId;
        }

        Object.keys(editFields).forEach((key) => {
          const field = editFields[key];
          if (!field) {
            return;
          }
          field.value = payload[key] ?? '';
        });

        if (editFields.owner_user_id) {
          refreshFarmOptions(editFields.owner_user_id, payload.farm_id ?? '');
        }
      };

      document.querySelectorAll('.edit-device-btn').forEach((button) => {
        button.addEventListener('click', function () {
          hydrateEditForm({
            device_id: button.getAttribute('data-device-id'),
            module_board_name: button.getAttribute('data-module-board-name'),
            primary_serial_no: button.getAttribute('data-primary-serial-no'),
            owner_user_id: button.getAttribute('data-owner-user-id'),
            farm_id: button.getAttribute('data-farm-id'),
            aliases_text: button.getAttribute('data-aliases-text'),
            main_technical_specs: button.getAttribute('data-main-technical-specs'),
            processing_memory: button.getAttribute('data-processing-memory'),
            gpio_interfaces: button.getAttribute('data-gpio-interfaces'),
          });
        });
      });

      const hydrateShowKeyForm = (payload) => {
        if (!showKeyForm) {
          return;
        }

        const deviceId = String(payload.device_id || '');
        const deviceSerialLabel = String(payload.device_serial || '').trim();
        const formAction = String(payload.action || '').trim();

        if (formAction !== '') {
          showKeyForm.action = formAction;
        }

        if (showDeviceIdInput) {
          showDeviceIdInput.value = deviceId;
        }

        if (showDeviceSerial) {
          showDeviceSerial.textContent = deviceSerialLabel !== '' ? deviceSerialLabel : 'this device';
        }

        if (showCurrentPasswordInput) {
          showCurrentPasswordInput.value = '';
        }
      };

      document.querySelectorAll('.show-key-btn').forEach((button) => {
        button.addEventListener('click', function () {
          hydrateShowKeyForm({
            device_id: button.getAttribute('data-device-id'),
            device_serial: button.getAttribute('data-device-serial'),
            action: button.getAttribute('data-device-show-key-action'),
          });
        });
      });

      if (oldInput.device_form_mode === 'create' && createModal) {
        createModal.show();
      }

      if (oldInput.device_form_mode === 'edit' && editModal) {
        hydrateEditForm(oldInput);
        editModal.show();
      } else if (oldInput.device_action_mode === 'reveal-key' && showKeyModal) {
        const targetButton = document.querySelector('.show-key-btn[data-device-id="' + String(oldInput.device_id || '') + '"]');
        if (targetButton instanceof HTMLElement) {
          targetButton.click();
        }
        showKeyModal.show();
      } else if (openModal === 'edit' && openEditDeviceId && editModal) {
        const targetButton = document.querySelector('.edit-device-btn[data-device-id="' + String(openEditDeviceId) + '"]');
        if (targetButton instanceof HTMLElement) {
          targetButton.click();
          editModal.show();
        }
      } else if (openModal === 'reveal-key' && openRevealDeviceId && showKeyModal) {
        const targetButton = document.querySelector('.show-key-btn[data-device-id="' + String(openRevealDeviceId) + '"]');
        if (targetButton instanceof HTMLElement) {
          targetButton.click();
          showKeyModal.show();
        }
      } else if (openModal === 'create' && createModal) {
        createModal.show();
      }
    });
  </script>
@endsection
