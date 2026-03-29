@extends('layouts.admin')

@section('title', 'Doc-Ease Legacy Gateway')

@section('content')
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0">Doc-Ease Legacy Gateway</h5>
          <span class="badge bg-label-warning">Transitional</span>
        </div>
        <div class="card-body">
          <p class="mb-3">
            This page is the Laravel-managed access boundary for the legacy <code>public/doc-ease</code> app.
          </p>

          @php
            $launchReady = $entrypointExists && (! $bridgeEnabled || ($bridgeExists && $bridgeSecretConfigured));
          @endphp

          @if (! $entrypointExists)
            <div class="alert alert-danger" role="alert">
              Legacy entrypoint not found at <code>{{ $entrypointPublicPath }}</code>.
            </div>
          @endif

          @if ($bridgeEnabled && ! $bridgeExists)
            <div class="alert alert-danger" role="alert">
              Bridge endpoint not found at <code>{{ $bridgePublicPath }}</code>.
            </div>
          @endif

          @if ($bridgeEnabled && ! $bridgeSecretConfigured)
            <div class="alert alert-warning" role="alert">
              Bridge is enabled but <code>DOC_EASE_BRIDGE_SECRET</code> is empty.
            </div>
          @endif

          <ul class="mb-4">
            <li>Entrypoint URL: <code>{{ $entrypoint }}</code></li>
            <li>Entrypoint file: <code>{{ $entrypointPublicPath }}</code></li>
            <li>Allowed Laravel roles: <code>{{ implode(', ', $allowedRoles) }}</code></li>
            <li>Bridge mode: <code>{{ $bridgeEnabled ? 'enabled' : 'disabled' }}</code></li>
            <li>Bridge path: <code>{{ $bridgePath }}</code></li>
            <li>Bridge file: <code>{{ $bridgePublicPath }}</code></li>
            <li>Bridge secret configured: <code>{{ $bridgeSecretConfigured ? 'yes' : 'no' }}</code></li>
            <li>Direct-path lock: <code>{{ $directPathLockEnabled ? 'enabled' : 'disabled' }}</code></li>
            <li>Direct lock gateway path: <code>{{ $directPathGatewayPath }}</code></li>
          </ul>

          <form method="POST" action="{{ route('legacy.doc-ease.launch') }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-primary" @disabled(! $launchReady)>
              Open Legacy Doc-Ease
            </button>
          </form>
          <a href="{{ route('doc-ease.dashboard') }}" class="btn btn-outline-primary ms-2">
            Open Laravelized Doc-Ease
          </a>
        </div>
      </div>
    </div>
  </div>
@endsection
