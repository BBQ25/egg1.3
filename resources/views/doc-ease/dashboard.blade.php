@extends('layouts.admin')

@section('title', 'Doc-Ease Laravel Dashboard')

@php
  $stats = $snapshot['stats'] ?? [];
  $timestamps = $snapshot['timestamps'] ?? [];
  $connected = (bool) ($snapshot['connected'] ?? false);
  $error = (string) ($snapshot['error'] ?? '');

  $formatStat = static function ($value): string {
      if ($value === null) {
          return 'N/A';
      }

      return number_format((int) $value);
  };
@endphp

@section('content')
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Laravelized Doc-Ease Dashboard</h5>
          <span class="badge bg-label-primary">Laravel Native</span>
        </div>
        <div class="card-body">
          @if ($connected)
            <div class="alert alert-success" role="alert">
              Connected to Doc-Ease database through Laravel connection <code>doc_ease</code>.
            </div>
          @else
            <div class="alert alert-warning" role="alert">
              Doc-Ease DB connection is unavailable. Configure <code>DOC_EASE_DB_*</code> in <code>.env</code>.
              @if ($error !== '')
                <div class="small mt-2"><code>{{ $error }}</code></div>
              @endif
            </div>
          @endif

          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <div class="border rounded p-3 h-100">
                <h6 class="mb-2">Users</h6>
                <div>Total: <strong>{{ $formatStat($stats['users_total'] ?? null) }}</strong></div>
                <div>Admins: <strong>{{ $formatStat($stats['admins_total'] ?? null) }}</strong></div>
                <div>Teachers: <strong>{{ $formatStat($stats['teachers_total'] ?? null) }}</strong></div>
                <div>Students: <strong>{{ $formatStat($stats['students_total'] ?? null) }}</strong></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="border rounded p-3 h-100">
                <h6 class="mb-2">Content</h6>
                <div>Uploaded files: <strong>{{ $formatStat($stats['uploaded_files_total'] ?? null) }}</strong></div>
                <div>Attendance attachments: <strong>{{ $formatStat($stats['attendance_attachments_total'] ?? null) }}</strong></div>
                <div>Learning material files: <strong>{{ $formatStat($stats['learning_material_files_total'] ?? null) }}</strong></div>
                <div>Latest upload: <strong>{{ $timestamps['uploaded_files_latest'] ?? 'N/A' }}</strong></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="border rounded p-3 h-100">
                <h6 class="mb-2">Academic</h6>
                <div>Class records: <strong>{{ $formatStat($stats['class_records_total'] ?? null) }}</strong></div>
                <div>Subjects: <strong>{{ $formatStat($stats['subjects_total'] ?? null) }}</strong></div>
                <div>Sections: <strong>{{ $formatStat($stats['sections_total'] ?? null) }}</strong></div>
              </div>
            </div>
          </div>

          <ul class="mb-4">
            <li>Legacy entrypoint: <code>{{ $entrypoint }}</code> ({{ $entrypointExists ? 'present' : 'missing' }})</li>
            <li>Bridge mode: <code>{{ $bridgeEnabled ? 'enabled' : 'disabled' }}</code> at <code>{{ $bridgePath }}</code></li>
            <li>Bridge endpoint file: <code>{{ $bridgeExists ? 'present' : 'missing' }}</code></li>
            <li>Bridge secret configured: <code>{{ $bridgeSecretConfigured ? 'yes' : 'no' }}</code></li>
            <li>Direct-path lock: <code>{{ $directPathLockEnabled ? 'enabled' : 'disabled' }}</code> via <code>{{ $directPathGatewayPath }}</code></li>
            <li>Allowed roles: <code>{{ implode(', ', $allowedRoles) }}</code></li>
          </ul>

          <div class="d-flex gap-2">
            <a href="{{ route('legacy.doc-ease.index') }}" class="btn btn-outline-primary">Legacy Gateway</a>
            <form method="POST" action="{{ route('legacy.doc-ease.launch') }}" class="d-inline">
              @csrf
              <button type="submit" class="btn btn-primary" @disabled(! $entrypointExists)>
                Open Legacy App
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
@endsection

