@php
  $appBasePath = trim((string) config('app.base_path', ''), '/');
  $appBaseUrlPath = $appBasePath === '' ? '' : '/' . $appBasePath;
  $sneatBase = $appBaseUrlPath . '/sneat';
  $sneatAssetsBase = $sneatBase . '/assets';
@endphp

<!doctype html>
<html lang="en" class="layout-wide customizer-hide" dir="ltr" data-skin="default"
  data-assets-path="{{ $sneatAssetsBase }}/" data-template="vertical-menu-template" data-bs-theme="light">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Doc-Ease Login (Laravel)</title>

  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/fonts/iconify-icons.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/css/core.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/css/demo.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/css/pages/page-auth.css" />

  <script src="{{ $sneatAssetsBase }}/vendor/js/helpers.js"></script>
  <script src="{{ $sneatAssetsBase }}/js/config.js"></script>
</head>
<body>
  <div class="authentication-wrapper authentication-cover">
    <div class="authentication-inner row m-0">
      <div class="d-none d-lg-flex col-lg-7 col-xl-8 align-items-center p-5">
        <div class="w-100 d-flex justify-content-center">
          <img src="{{ $sneatAssetsBase }}/img/illustrations/girl-verify-password-light.png"
               class="img-fluid"
               alt="Doc-Ease"
               width="700"
               data-app-dark-img="illustrations/girl-verify-password-dark.png"
               data-app-light-img="illustrations/girl-verify-password-light.png" />
        </div>
      </div>
      <div class="d-flex col-12 col-lg-5 col-xl-4 align-items-center authentication-bg p-sm-12 p-6">
        <div class="w-px-400 mx-auto mt-sm-12 mt-8">
          <h4 class="mb-1">Doc-Ease (Laravel)</h4>
          <p class="mb-6">Sign in using Doc-Ease credentials (email or student ID).</p>

          @if ($errors->any())
            <div class="alert alert-danger mb-4" role="alert">
              {{ $errors->first() }}
            </div>
          @endif

          <form method="POST" action="{{ route('doc-ease.login.store') }}">
            @csrf

            <div class="mb-3">
              <label for="login" class="form-label">Email or Student ID</label>
              <input type="text"
                     class="form-control"
                     id="login"
                     name="login"
                     value="{{ old('login') }}"
                     placeholder="name@example.com or 2410001-1"
                     required
                     autofocus />
            </div>

            <div class="mb-4">
              <label for="password" class="form-label">Password</label>
              <input type="password"
                     class="form-control"
                     id="password"
                     name="password"
                     placeholder="Enter your password"
                     required />
            </div>

            <button class="btn btn-primary d-grid w-100" type="submit">Sign In</button>
          </form>
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
