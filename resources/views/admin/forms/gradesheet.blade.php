@extends('layouts.admin')

@section('title', 'APEWSD - Grade Sheet PDF')

@section('content')
  <div class="row">
    <div class="col-12">
      <h4 class="mb-1">Grade Sheet PDF Generator</h4>
      <p class="mb-6">Uses server-side TCPDF and returns the PDF as a blob download (same request pattern as CES).</p>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">Grade Sheet Payload</h5>
      <span class="badge bg-label-primary">Server-side TCPDF</span>
    </div>
    <div class="card-body">
      <div class="row g-4 mb-4">
        <div class="col-md-3">
          <label class="form-label">Course Code</label>
          <input type="text" class="form-control" id="courseCode" value="{{ $courseCode }}" />
        </div>
        <div class="col-md-5">
          <label class="form-label">Course Title</label>
          <input type="text" class="form-control" id="courseTitle" value="{{ $courseTitle }}" />
        </div>
        <div class="col-md-4">
          <label class="form-label">Schedule</label>
          <input type="text" class="form-control" id="scheduleLabel" value="{{ $scheduleLabel }}" />
        </div>
        <div class="col-md-3">
          <label class="form-label">School Year</label>
          <input type="text" class="form-control" id="schoolYearLabel" value="{{ $schoolYear }}" />
        </div>
        <div class="col-md-3">
          <label class="form-label">Semester</label>
          <input type="text" class="form-control" id="semesterLabel" value="{{ $semester }}" />
        </div>
        <div class="col-md-3">
          <label class="form-label">Section</label>
          <input type="text" class="form-control" id="sectionLabel" value="{{ $sectionLabel }}" />
        </div>
        <div class="col-md-3">
          <label class="form-label">Filename</label>
          <input type="text" class="form-control" id="filenameLabel" value="{{ $filename }}" />
        </div>
      </div>

      <div class="d-flex align-items-center gap-2">
        <a href="#" id="gradesheet" class="btn btn-primary"
          sid="{{ $encryptedScheduleId }}"
          sy="{{ $encryptedSchoolYear }}"
          sem="{{ $encryptedSemester }}"
          filename="{{ $filename }}"
          data-endpoint="{{ route('forms.gradesheet.download') }}">
          <i class="bx bx-download me-1"></i> Generate Grade Sheet
        </a>
        <span class="text-muted small">Endpoint: <code>POST /forms/gradesheet</code></span>
      </div>
    </div>
  </div>

  <div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0">CES Iframe Automation</h5>
      <span class="badge bg-label-warning">Experimental</span>
    </div>
    <div class="card-body">
      <p class="mb-3">
        Attempts to open CES Encode Grades and auto-run: choose School Year, choose Semester, click section, then click
        Generate Grade Sheet.
      </p>

      <div class="row g-3 align-items-end">
        <div class="col-md-6">
          <label class="form-label">CES URL</label>
          <input type="url" class="form-control" id="cesFrameUrl"
            value="https://ces.southernleytestateu.edu.ph/teacher/encode-grades" />
        </div>
        <div class="col-md-2">
          <label class="form-label">Campus</label>
          <select class="form-select" id="cesCampus">
            <option value="Bontoc" selected>Bontoc</option>
            <option value="Main">Main</option>
            <option value="Maasin City">Maasin City</option>
            <option value="Tomas Oppus">Tomas Oppus</option>
            <option value="San Juan">San Juan</option>
            <option value="Hinunangan">Hinunangan</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">School Year</label>
          <input type="text" class="form-control" id="cesSchoolYear" value="2025-2026" />
        </div>
        <div class="col-md-2">
          <label class="form-label">Semester</label>
          <select class="form-select" id="cesSemester">
            <option value="1st Semester" selected>1st Semester</option>
            <option value="2nd Semester">2nd Semester</option>
            <option value="Summer">Summer</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Section</label>
          <input type="text" class="form-control" id="cesSectionCode" value="IF-2-A-6" />
        </div>
      </div>

      <div class="row g-3 align-items-end mt-1">
        <div class="col-md-4">
          <label class="form-label">CES Username</label>
          <input type="text" class="form-control" id="cesUsername" placeholder="Uses .env CES_USERNAME if blank" />
        </div>
        <div class="col-md-4">
          <label class="form-label">CES Password</label>
          <input type="password" class="form-control" id="cesPassword" placeholder="Uses .env CES_PASSWORD if blank" />
        </div>
        <div class="col-md-4">
          <label class="form-label">PDF Filename (optional)</label>
          <input type="text" class="form-control" id="cesFilename" placeholder="IF-2-A-6-2025-2026.pdf" />
        </div>
      </div>

      <div class="row g-3 align-items-end mt-1">
        <div class="col-md-3">
          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" id="cesVisualMode" />
            <label class="form-check-label" for="cesVisualMode">
              Visual Debug Mode (headed browser)
            </label>
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Slow Motion (ms)</label>
          <input type="number" min="0" max="2000" step="50" class="form-control" id="cesSlowMoMs" value="350" />
        </div>
      </div>

      <div class="d-flex align-items-center gap-2 mt-3 mb-3">
        <button type="button" id="loadCesFrame" class="btn btn-outline-primary">
          <i class="bx bx-world me-1"></i> Load CES Page
        </button>
        <button type="button" id="runCesAutomation" class="btn btn-primary">
          <i class="bx bx-play-circle me-1"></i> Run Auto Click Flow
        </button>
        <button type="button" id="runCesServerAutomation" class="btn btn-success"
          data-endpoint="{{ route('forms.gradesheet.ces.download') }}">
          <i class="bx bx-rocket me-1"></i> Run Server Playwright + Download PDF
        </button>
        <button type="button" id="testCesServerConnection" class="btn btn-outline-success"
          data-endpoint="{{ route('forms.gradesheet.ces.test') }}">
          <i class="bx bx-check-shield me-1"></i> Test CES Connection
        </button>
      </div>

      <div class="alert alert-warning mb-3">
        CES currently responds with <code>X-Frame-Options: SAMEORIGIN</code>. If that stays enabled, browsers block iframe
        control from this app. For Visual Debug Mode, headed browser visibility depends on your server process having desktop
        access.
      </div>

      <iframe id="cesAutomationFrame" class="w-100 border rounded" style="min-height: 680px;" loading="lazy"></iframe>

      <div class="mt-3">
        <label class="form-label mb-1">Automation Log</label>
        <div id="cesAutomationStatus" class="border rounded p-2 small bg-light" style="min-height: 120px;"></div>
      </div>
    </div>
  </div>

  <script src="{{ asset('js/report-gradesheet.js') }}"></script>
  <script src="{{ asset('js/ces-iframe-automation.js') }}"></script>
@endsection
