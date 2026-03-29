@extends('layouts.admin')

@section('title', 'APEWSD - Easy Login')

@section('content')
  <div class="row">
    <div class="col-12">
      <h4 class="mb-1">Easy Login - HRMIS My DTR</h4>
      <p class="mb-6">Runs server-side Playwright to reuse session/login, click <code>btnTimeIn</code>, and return the alert message.</p>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">HRMIS Time In Automation</h5>
      <span class="badge bg-label-warning">Experimental</span>
    </div>
    <div class="card-body">
      <div class="row g-3 align-items-end">
        <div class="col-md-6">
          <label class="form-label">HRMIS My DTR URL</label>
          <input type="url" class="form-control" id="hrmisDtrUrl" value="{{ $hrmisDtrUrl }}" />
        </div>
        <div class="col-md-6">
          <label class="form-label">Google Email</label>
          <input type="email" class="form-control" id="hrmisEmail" value="{{ $hrmisEmail }}"
            placeholder="Google account used for HRMIS (default from .env HRMIS_EMAIL)" />
        </div>
      </div>

      <div class="row g-3 align-items-end mt-1">
        <div class="col-md-3">
          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" id="hrmisVisualMode" checked />
            <label class="form-check-label" for="hrmisVisualMode">
              Visual Debug Mode (headed browser)
            </label>
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Slow Motion (ms)</label>
          <input type="number" min="0" max="2000" step="50" class="form-control" id="hrmisSlowMoMs"
            value="{{ $hrmisSlowMoMs }}" />
        </div>
      </div>

      <div class="d-flex align-items-center gap-2 mt-3 mb-3">
        <button type="button" id="runHrmisEasyLogin" class="btn btn-primary"
          data-endpoint="{{ route('forms.easy-login.hrmis.time-in') }}">
          <i class="bx bx-log-in-circle me-1"></i> Run Easy Login + Time In
        </button>
      </div>

      <div class="alert alert-warning mb-3">
        HRMIS uses Google sign-in for My DTR. First run may require interactive Google confirmation.
        For Visual Debug Mode, headed browser visibility depends on your server process having desktop access.
      </div>

      <div id="hrmisEasyLoginResult" class="alert d-none mb-3"></div>

      <div>
        <label class="form-label mb-1">Automation Log</label>
        <div id="hrmisEasyLoginStatus" class="border rounded p-2 small bg-light" style="min-height: 120px;"></div>
      </div>
    </div>
  </div>

  <script src="{{ asset('js/hrmis-easy-login.js') }}"></script>
@endsection
