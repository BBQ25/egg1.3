@php
  $fieldValues = is_array($values ?? null) ? $values : [];
  $ownersList = $owners ?? collect();
  $fieldPrefix = (string) ($prefix ?? '');
@endphp

<div class="row g-3">
  <div class="col-12 col-md-7">
    <label class="form-label" for="{{ $fieldPrefix }}farm_name">Farm Name</label>
    <input
      type="text"
      class="form-control"
      id="{{ $fieldPrefix }}farm_name"
      name="farm_name"
      value="{{ $fieldValues['farm_name'] ?? '' }}"
      maxlength="120"
      required>
  </div>

  <div class="col-12 col-md-5">
    <label class="form-label" for="{{ $fieldPrefix }}owner_user_id">Owner</label>
    <select class="form-select" id="{{ $fieldPrefix }}owner_user_id" name="owner_user_id" required>
      <option value="">Select owner</option>
      @foreach ($ownersList as $owner)
        <option value="{{ $owner->id }}" @selected((string) ($fieldValues['owner_user_id'] ?? '') === (string) $owner->id)>
          {{ $owner->full_name }} ({{ '@' . $owner->username }})
        </option>
      @endforeach
    </select>
  </div>

  <div class="col-12 col-md-6">
    <label class="form-label" for="{{ $fieldPrefix }}location">Location</label>
    <input
      type="text"
      class="form-control"
      id="{{ $fieldPrefix }}location"
      name="location"
      value="{{ $fieldValues['location'] ?? '' }}"
      maxlength="160"
      required>
  </div>

  <div class="col-12 col-md-6">
    <label class="form-label" for="{{ $fieldPrefix }}sitio">Sitio</label>
    <input
      type="text"
      class="form-control"
      id="{{ $fieldPrefix }}sitio"
      name="sitio"
      value="{{ $fieldValues['sitio'] ?? '' }}"
      maxlength="120"
      required>
  </div>

  <div class="col-12 col-md-6">
    <label class="form-label" for="{{ $fieldPrefix }}barangay">Barangay</label>
    <input
      type="text"
      class="form-control"
      id="{{ $fieldPrefix }}barangay"
      name="barangay"
      value="{{ $fieldValues['barangay'] ?? '' }}"
      maxlength="120"
      required>
  </div>

  <div class="col-12 col-md-6">
    <label class="form-label" for="{{ $fieldPrefix }}municipality">Municipality</label>
    <input
      type="text"
      class="form-control"
      id="{{ $fieldPrefix }}municipality"
      name="municipality"
      value="{{ $fieldValues['municipality'] ?? '' }}"
      maxlength="120"
      required>
  </div>

  <div class="col-12">
    <label class="form-label" for="{{ $fieldPrefix }}province">Province</label>
    <input
      type="text"
      class="form-control"
      id="{{ $fieldPrefix }}province"
      name="province"
      value="{{ $fieldValues['province'] ?? '' }}"
      maxlength="120"
      required>
  </div>

  <div class="col-12">
    <div class="farm-location-picker-card" data-farm-picker-card="{{ $fieldPrefix }}">
      <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3 mb-3">
        <div>
          <div class="fw-semibold">Pin Farm Coordinates</div>
          <div class="small text-body-secondary">Click the map to drop a pin, or drag the pin to refine the exact farm position.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" id="{{ $fieldPrefix }}use_current_location">
            <i class="bx bx-current-location me-1"></i>Use Current Location
          </button>
          <button type="button" class="btn btn-sm btn-outline-danger" id="{{ $fieldPrefix }}clear_pin">
            <i class="bx bx-x-circle me-1"></i>Clear Pin
          </button>
        </div>
      </div>

      <div
        class="farm-location-picker-map"
        id="{{ $fieldPrefix }}location_picker_map"
        data-farm-location-map="{{ $fieldPrefix }}"
        role="img"
        aria-label="Farm coordinate picker map"></div>

      <div class="small text-body-secondary mt-2" id="{{ $fieldPrefix }}picker_status">
        No map pin selected yet.
      </div>
    </div>
  </div>

  <div class="col-12 col-md-6">
    <label class="form-label" for="{{ $fieldPrefix }}latitude">Latitude</label>
    <input
      type="number"
      class="form-control"
      id="{{ $fieldPrefix }}latitude"
      name="latitude"
      value="{{ $fieldValues['latitude'] ?? '' }}"
      min="-90"
      max="90"
      step="0.0000001"
      readonly
      required>
  </div>

  <div class="col-12 col-md-6">
    <label class="form-label" for="{{ $fieldPrefix }}longitude">Longitude</label>
    <input
      type="number"
      class="form-control"
      id="{{ $fieldPrefix }}longitude"
      name="longitude"
      value="{{ $fieldValues['longitude'] ?? '' }}"
      min="-180"
      max="180"
      step="0.0000001"
      readonly
      required>
  </div>

  <div class="col-12">
    <p class="small text-body-secondary mb-0">
      Coordinates are set from the map pin and must stay inside the configured general geofence when geofence is set.
    </p>
  </div>
</div>
