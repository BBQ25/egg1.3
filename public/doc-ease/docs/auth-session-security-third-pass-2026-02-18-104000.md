# Third-Pass Auth Security Report

As of: 2026-02-18 10:40:00 +08:00

## Scope
- Third pass focused on runtime auth hardening.
- Primary targets:
  - `pages/auth/auth-login.php` brute-force mitigation (IP + identifier throttling/lockout)
  - logout CSRF hardening with POST-first enforcement and temporary GET fallback

## Changes Implemented
1. Brute-force throttling and lockout in `pages/auth/auth-login.php`
- Added DB-backed throttle table `auth_login_throttle` (auto-created if missing).
- Enforced dual-scope throttling:
  - IP scope policy: 15 failures within 15 minutes => 15-minute lock
  - Identifier scope policy: 8 failures within 15 minutes => 20-minute lock
- Added lock checks before credential verification.
- Added failure registration for invalid credentials and lock activation flow.
- Added throttle reset on successful login.
- Added audit events for throttled and lockout outcomes.

2. Logout converted to CSRF-protected POST in `pages/auth/auth-logout.php`
- `POST` is now the primary logout method.
- CSRF token is required for authenticated sessions.
- Temporary legacy `GET` fallback retained only until `2026-03-31 23:59:59` (server local time).
- After fallback deadline, GET logout is rejected with `405` and `Allow: POST`.
- Added audit logging for:
  - CSRF failures
  - legacy GET usage
  - legacy GET rejection after deadline

3. Logout callers migrated to POST + CSRF
- `layouts/topbar.php`: logout dropdown entry now submits a POST form.
- `layouts/horizontal-nav.php`: logout entry now submits a POST form.
- `pages/auth-force-password.php`: sign-out action now POSTs CSRF token.
- `pages/auth-logout-2.php`: converted to auto-forward POST form into `auth-logout.php`.

4. Session timeout logout path updated
- `layouts/footer-scripts.php`: session timeout config now ships logout POST data:
  - `logoutUrl`
  - `logoutReason`
  - `logoutCsrfToken`
  - legacy fallback URL
- `assets/js/session-timeout.js`: idle-timeout logout now POSTs CSRF token first, with compatibility fallback redirect.

5. Alternate login variant alignment
- `pages/auth-login-2.php` form now posts to `auth-login.php` with CSRF token and proper credential field names, so it inherits the same hardened login controls.

## Validation
- `php -l` passed for:
  - `pages/auth/auth-login.php`
  - `pages/auth/auth-logout.php`
  - `pages/auth-login-2.php`
  - `pages/auth-logout-2.php`
  - `pages/auth-force-password.php`
  - `layouts/topbar.php`
  - `layouts/horizontal-nav.php`
  - `layouts/footer-scripts.php`

## Residual Notes
- IP detection currently uses `REMOTE_ADDR`; if behind reverse proxies/load balancers, trusted-forwarded-IP handling should be added carefully to avoid spoofing.
- Throttle table retention/cleanup is not yet implemented; periodic pruning can be added if table growth becomes significant.
