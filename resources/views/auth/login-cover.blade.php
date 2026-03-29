@php
    $appBasePath = trim((string) config('app.base_path', ''), '/');
    $appBaseUrlPath = $appBasePath === '' ? '' : '/' . $appBasePath;
    $sneatBase = $appBaseUrlPath . '/sneat';
    $sneatAssetsBase = $sneatBase . '/assets';
    $sneatFontsBase = $sneatBase . '/fonts';
    $brandLogoUrl = $sneatAssetsBase . '/img/logo.png?v=20260220';
    $geofenceEnabled = (bool) ($geofenceEnabled ?? false);
    $loginBypassEnabled = (bool) ($loginBypassEnabled ?? false);
    $loginBypassRules = (array) ($loginBypassRules ?? []);
    $loginBypassEndpoint = (string) ($loginBypassEndpoint ?? '');
@endphp

<!doctype html>
<html
  lang="en"
  class="layout-wide customizer-hide"
  dir="ltr"
  data-skin="default"
  data-assets-path="{{ $sneatAssetsBase }}/"
  data-template="vertical-menu-template"
  data-bs-theme="light">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />

    <title>Sumacot - Login Cover</title>

    <link rel="icon" type="image/png" href="{{ $brandLogoUrl }}" />
    <link rel="shortcut icon" href="{{ $brandLogoUrl }}" />

    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/fonts/iconify-icons.css" />
    <link rel="stylesheet" href="{{ $appBaseUrlPath }}/vendor/fontawesome/css/all.min.css" />

    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/libs/pickr/pickr-themes.css" />
    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/css/core.css" />
    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/css/demo.css" />
    @include('partials.font-head')
    @include('partials.pwa-head')
    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/css/brand.css" />
    @include('partials.responsive-shell-styles')
    @include('partials.auth-cover-styles')

    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/libs/@form-validation/form-validation.css" />

    <link rel="stylesheet" href="{{ $sneatAssetsBase }}/vendor/css/pages/page-auth.css" />

    <script src="{{ $sneatAssetsBase }}/vendor/js/helpers.js"></script>
    <script src="{{ $sneatAssetsBase }}/js/config.js"></script>
  </head>

  <body class="auth-app-shell">
    <div class="authentication-wrapper authentication-cover">
      <a
        href="{{ route('dashboard') }}"
        id="login-brand"
        class="app-brand auth-cover-brand gap-2"
        @if ($loginBypassEnabled)
          data-login-bypass-endpoint="{{ $loginBypassEndpoint }}"
          data-login-bypass-token="{{ csrf_token() }}"
          data-login-bypass-rules='@json($loginBypassRules)'
        @endif>
        <span class="app-brand-logo demo">
          <img src="{{ $sneatAssetsBase }}/img/logo.png?v=20260226" alt="Site logo" class="app-brand-logo-img" />
        </span>
      </a>

      <div class="authentication-inner row m-0">
        <div class="d-none d-lg-flex col-lg-7 col-xl-7 p-5 auth-cover-visual">
          <div class="auth-cover-visual-stack">
            <div class="auth-cover-copy">
              <span class="badge bg-label-primary mb-3">PoultryPulse Access</span>
              <h2>Real-time poultry monitoring for desktop and mobile use.</h2>
              <p>
                Securely access farm activity, device ingest, and production records from one responsive monitoring workspace.
              </p>
            </div>

            <div class="auth-cover-illustration">
              <img
                src="{{ $sneatAssetsBase }}/img/illustrations/boy-with-rocket-light.png"
                alt="Login illustration"
                class="auth-cover-image img-fluid"
                width="700"
                data-app-light-img="illustrations/boy-with-rocket-light.png"
                data-app-dark-img="illustrations/boy-with-rocket-dark.png" />
            </div>
          </div>
        </div>

        <div class="d-flex col-12 col-lg-5 col-xl-5 align-items-center authentication-bg p-sm-12 p-6 auth-cover-panel">
          <div class="auth-cover-form-shell mx-auto mt-sm-12 mt-8">
            <h4 class="mb-1">Welcome back!</h4>
            <p class="mb-6">Please sign in to your account and start the adventure.</p>
            <p class="text-body-secondary small mb-6" data-live-sync-marker>Live sync marker: 2026-03-29</p>

            @if ($errors->any())
              <div class="alert alert-danger mb-6" role="alert">
                {{ $errors->first() }}
              </div>
            @endif

            @if (session('status'))
              <div class="alert alert-success mb-6" role="alert">
                {{ session('status') }}
              </div>
            @endif

            <form id="formAuthentication" class="mb-6" action="{{ route('login.store') }}" method="POST">
              @csrf
              <input type="hidden" id="geofence_latitude" name="geofence_latitude" value="{{ old('geofence_latitude') }}" />
              <input type="hidden" id="geofence_longitude" name="geofence_longitude" value="{{ old('geofence_longitude') }}" />

              <div class="mb-6 form-control-validation">
                <label for="username" class="form-label">Username</label>
                <input
                  type="text"
                  class="form-control"
                  id="username"
                  name="username"
                  autocomplete="username"
                  placeholder="Enter your username"
                  value="{{ old('username') }}"
                  autofocus />
              </div>

              <div class="form-password-toggle form-control-validation">
                <label class="form-label" for="password">Password</label>
                <div class="input-group input-group-merge">
                  <input type="password" id="password" class="form-control" name="password" autocomplete="current-password" placeholder="........" aria-describedby="password" required />
                  <span class="input-group-text cursor-pointer"><i class="icon-base bx bx-hide"></i></span>
                </div>
              </div>

              <div class="my-7">
                <div class="d-flex justify-content-between">
                  <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="remember-me" name="remember" value="1" />
                    <label class="form-check-label" for="remember-me">Remember Me</label>
                  </div>
                  <a href="javascript:void(0);">
                    <p class="mb-0">Forgot Password?</p>
                  </a>
                </div>
              </div>

              <button class="btn btn-primary d-grid w-100">Sign in</button>
            </form>

            <p class="text-center">
              <span>Need an account?</span>
              <a href="{{ route('register') }}">Register</a>
            </p>

            <div class="divider my-6">
              <div class="divider-text">or</div>
            </div>

            <div class="d-flex justify-content-center">
              <a href="javascript:void(0);" class="btn btn-sm btn-icon rounded-circle btn-text-facebook me-1_5">
                <i class="icon-base bx bxl-facebook-circle icon-20px"></i>
              </a>

              <a href="javascript:void(0);" class="btn btn-sm btn-icon rounded-circle btn-text-twitter me-1_5">
                <i class="icon-base bx bxl-twitter icon-20px"></i>
              </a>

              <a href="javascript:void(0);" class="btn btn-sm btn-icon rounded-circle btn-text-github me-1_5">
                <i class="icon-base bx bxl-github icon-20px"></i>
              </a>

              <a href="javascript:void(0);" class="btn btn-sm btn-icon rounded-circle btn-text-google-plus">
                <i class="icon-base bx bxl-google icon-20px"></i>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="{{ $sneatAssetsBase }}/vendor/libs/jquery/jquery.js"></script>
    <script src="{{ $sneatAssetsBase }}/vendor/libs/popper/popper.js"></script>
    <script src="{{ $sneatAssetsBase }}/vendor/js/bootstrap.js"></script>
    <script src="{{ $sneatAssetsBase }}/vendor/libs/@algolia/autocomplete-js.js"></script>
    <script src="{{ $sneatAssetsBase }}/vendor/libs/pickr/pickr.js"></script>
    <script src="{{ $sneatAssetsBase }}/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="{{ $sneatAssetsBase }}/vendor/libs/hammer/hammer.js"></script>
    <script src="{{ $sneatAssetsBase }}/vendor/libs/i18n/i18n.js"></script>
    <script src="{{ $sneatAssetsBase }}/vendor/js/menu.js"></script>

    <script src="{{ $sneatAssetsBase }}/vendor/libs/@form-validation/popular.js"></script>
    <script src="{{ $sneatAssetsBase }}/vendor/libs/@form-validation/bootstrap5.js"></script>
    <script src="{{ $sneatAssetsBase }}/vendor/libs/@form-validation/auto-focus.js"></script>

    <script src="{{ $sneatAssetsBase }}/js/main.js"></script>

    @if ($loginBypassEnabled)
      <script>
        (function() {
          const brand = document.getElementById('login-brand');
          const rulesPayload = brand ? brand.getAttribute('data-login-bypass-rules') : null;
          const endpoint = brand ? brand.getAttribute('data-login-bypass-endpoint') : null;
          const csrfToken = brand ? brand.getAttribute('data-login-bypass-token') : null;
          const latitudeInput = document.getElementById('geofence_latitude');
          const longitudeInput = document.getElementById('geofence_longitude');

          if (!brand || !rulesPayload || !endpoint || !csrfToken) {
            return;
          }

          let rules = null;

          try {
            rules = JSON.parse(rulesPayload);
          } catch (error) {
            return;
          }

          rules = Array.isArray(rules)
            ? rules
                .map((rule) => ({
                  click_count: Number(rule && rule.click_count ? rule.click_count : 0),
                  window_seconds: Number(rule && rule.window_seconds ? rule.window_seconds : 0)
                }))
                .filter((rule) => rule.click_count >= 2 && rule.window_seconds > 0)
                .sort((a, b) => {
                  if (a.click_count !== b.click_count) return b.click_count - a.click_count;
                  return a.window_seconds - b.window_seconds;
                })
            : [];

          if (rules.length === 0) {
            return;
          }

          let maxWindowMs = 0;
          for (let i = 0; i < rules.length; i += 1) {
            const windowMs = Math.round(rules[i].window_seconds * 1000);
            if (windowMs > maxWindowMs) {
              maxWindowMs = windowMs;
            }
          }
          if (maxWindowMs <= 0) {
            return;
          }

          let clickTimes = [];
          let pending = false;
          let deferredTimer = 0;

          function prune(now) {
            const kept = [];
            for (let i = 0; i < clickTimes.length; i += 1) {
              if ((now - clickTimes[i]) <= maxWindowMs) {
                kept.push(clickTimes[i]);
              }
            }
            clickTimes = kept;
          }

          function clearDeferredTimer() {
            if (!deferredTimer) {
              return;
            }
            window.clearTimeout(deferredTimer);
            deferredTimer = 0;
          }

          function attemptRule(rule, elapsedMs) {
            if (pending) {
              return;
            }
            clearDeferredTimer();
            pending = true;

            const body = new URLSearchParams();
            body.set('_token', String(csrfToken || ''));
            body.set('click_count', String(Math.round(rule.click_count)));
            body.set('duration_ms', String(Math.max(0, Math.round(elapsedMs))));

            if (latitudeInput && longitudeInput) {
              if (latitudeInput.value !== '' && longitudeInput.value !== '') {
                body.set('geofence_latitude', latitudeInput.value);
                body.set('geofence_longitude', longitudeInput.value);
              }
            }

            fetch(endpoint, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': String(csrfToken || '')
              },
              body: body.toString()
            })
              .then((res) => res.json())
              .then((data) => {
                if (data && data.ok && data.redirect) {
                  window.location.href = data.redirect;
                  return;
                }
                pending = false;
              })
              .catch(() => {
                pending = false;
              });
          }

          function findMatchedRule(now) {
            for (let i = 0; i < rules.length; i += 1) {
              const rule = rules[i];
              const needed = Math.round(rule.click_count);
              const windowMs = Math.round(rule.window_seconds * 1000);
              if (needed < 2 || windowMs <= 0) {
                continue;
              }
              if (clickTimes.length < needed) {
                continue;
              }

              const start = clickTimes[clickTimes.length - needed];
              const elapsed = now - start;
              if (elapsed <= windowMs) {
                return {
                  rule: rule,
                  elapsed: elapsed
                };
              }
            }
            return null;
          }

          function getDeferralMs(candidate, now) {
            if (!candidate || !candidate.rule || clickTimes.length === 0) {
              return 0;
            }

            const candidateCount = Math.round(candidate.rule.click_count);
            const candidateWindowMs = Math.round(candidate.rule.window_seconds * 1000);
            const candidateRemaining = candidateWindowMs - Math.max(0, Math.round(candidate.elapsed));
            if (candidateRemaining <= 0) {
              return 0;
            }

            let nextHigherCount = 0;
            for (let i = 0; i < rules.length; i += 1) {
              const count = Math.round(rules[i].click_count);
              if (count <= candidateCount) {
                continue;
              }
              if (nextHigherCount === 0 || count < nextHigherCount) {
                nextHigherCount = count;
              }
            }
            if (nextHigherCount <= 0) {
              return 0;
            }
            if (clickTimes.length >= nextHigherCount) {
              return 0;
            }

            const oldest = clickTimes[0];
            if (!oldest) {
              return 0;
            }

            let higherRemaining = 0;
            for (let i = 0; i < rules.length; i += 1) {
              const higherRule = rules[i];
              const higherCount = Math.round(higherRule.click_count);
              if (higherCount !== nextHigherCount) {
                continue;
              }

              const higherWindowMs = Math.round(higherRule.window_seconds * 1000);
              if (higherWindowMs <= 0) {
                continue;
              }

              const remaining = higherWindowMs - (now - oldest);
              if (remaining <= 0) {
                continue;
              }
              if (higherRemaining === 0 || remaining < higherRemaining) {
                higherRemaining = remaining;
              }
            }
            if (higherRemaining <= 0) {
              return 0;
            }
            return Math.min(candidateRemaining, higherRemaining);
          }

          function scheduleRecheck(delayMs) {
            clearDeferredTimer();
            const ms = Math.max(20, Math.round(delayMs) - 30);
            deferredTimer = window.setTimeout(() => {
              deferredTimer = 0;
              if (pending) {
                return;
              }
              const now = Date.now();
              prune(now);
              evaluateAndAttempt(now);
            }, ms);
          }

          function evaluateAndAttempt(now) {
            const candidate = findMatchedRule(now);
            if (!candidate) {
              return;
            }

            const deferMs = getDeferralMs(candidate, now);
            if (deferMs > 40) {
              scheduleRecheck(deferMs);
              return;
            }

            clickTimes = [];
            attemptRule(candidate.rule, candidate.elapsed);
          }

          brand.addEventListener('click', function(event) {
            event.preventDefault();
            if (pending) {
              return;
            }

            const now = Date.now();
            clickTimes.push(now);
            prune(now);
            clearDeferredTimer();
            evaluateAndAttempt(now);
          });
        })();
      </script>
    @endif

    @if ($geofenceEnabled)
      <script>
        (function() {
          const form = document.getElementById('formAuthentication');
          const latitudeInput = document.getElementById('geofence_latitude');
          const longitudeInput = document.getElementById('geofence_longitude');

          if (!form || !latitudeInput || !longitudeInput || !navigator.geolocation) {
            return;
          }

          let bypassLocationRequirement = false;

          function captureLocation(onSuccess, onFailure) {
            navigator.geolocation.getCurrentPosition(
              function(position) {
                latitudeInput.value = String(position.coords.latitude);
                longitudeInput.value = String(position.coords.longitude);
                onSuccess();
              },
              function() {
                onFailure();
              },
              {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 60000
              }
            );
          }

          // Best effort prefill.
          captureLocation(function() {}, function() {});

          form.addEventListener('submit', function(event) {
            if (bypassLocationRequirement) {
              return;
            }

            if (latitudeInput.value !== '' && longitudeInput.value !== '') {
              return;
            }

            event.preventDefault();

            captureLocation(
              function() {
                form.submit();
              },
              function() {
                bypassLocationRequirement = true;
                form.submit();
              }
            );
          });
        })();
      </script>
    @endif
  </body>
</html>
