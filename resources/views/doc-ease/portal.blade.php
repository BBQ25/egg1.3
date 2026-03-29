@php
  $appBasePath = trim((string) config('app.base_path', ''), '/');
  $appBaseUrlPath = $appBasePath === '' ? '' : '/' . $appBasePath;
  $sneatBase = $appBaseUrlPath . '/sneat';
  $sneatAssetsBase = $sneatBase . '/assets';
  $role = $user?->normalizedRole() ?? 'student';
  $isAdmin = $user?->isAdminRole() ?? false;
  $active = (bool) ($user?->is_active ?? false);
@endphp

<!doctype html>
<html lang="en" class="layout-wide" dir="ltr" data-skin="default"
  data-assets-path="{{ $sneatAssetsBase }}/" data-template="vertical-menu-template" data-bs-theme="light">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Doc-Ease Portal (Laravel)</title>

  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/fonts/iconify-icons.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/css/core.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/css/demo.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

  <script src="{{ $sneatAssetsBase }}/vendor/js/helpers.js"></script>
  <script src="{{ $sneatAssetsBase }}/js/config.js"></script>
</head>
<body>
  <div class="container-xxl py-5">
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="card">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Doc-Ease User Portal (Laravel Auth)</h5>
            <span class="badge bg-label-primary">{{ strtoupper($role) }}</span>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <div><strong>Username:</strong> {{ $user?->username ?? 'N/A' }}</div>
              <div><strong>Email:</strong> {{ $user?->useremail ?? 'N/A' }}</div>
              <div><strong>Role:</strong> {{ $role }}</div>
              <div><strong>Active:</strong> {{ $active ? 'yes' : 'no' }}</div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
              <form method="POST" action="{{ route('doc-ease.portal.launch-legacy') }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-primary">
                  Open Legacy Doc-Ease
                </button>
              </form>

              <a href="{{ route('doc-ease.dashboard') }}" class="btn btn-outline-primary">
                Open Laravelized Dashboard
              </a>

              @if ($isAdmin)
                <a href="{{ route('doc-ease.academic.assignments.index') }}" class="btn btn-outline-warning">
                  Academic Modules
                </a>
              @endif

              <a href="{{ route('login') }}" class="btn btn-outline-info">
                Main App Login
              </a>

              <form method="POST" action="{{ route('doc-ease.logout') }}" class="d-inline ms-auto">
                @csrf
                <button type="submit" class="btn btn-outline-secondary">
                  Sign Out
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="{{ $sneatAssetsBase }}/vendor/libs/jquery/jquery.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/popper/popper.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/js/bootstrap.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/hammer/hammer.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/libs/i18n/i18n.js"></script>
  <script src="{{ $sneatAssetsBase }}/vendor/js/menu.js"></script>
  <script src="{{ $sneatAssetsBase }}/js/main.js"></script>
</body>
</html>
