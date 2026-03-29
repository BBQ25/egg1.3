# Auth/Session/Security Second Pass Report
As of `2026-02-18 10:10:10 +08:00`

## Scope
- Runtime-level review focused on auth/session/security flows.
- Targeted files: session bootstrap, auth login/register/enrollment, forced password change, login bypass auth path, and test/debug auth endpoints.

## High-Confidence Findings Fixed

1. Session cookie/session-mode hardening was not explicitly enforced.
- Risk:
  - Weaker defaults on some PHP environments (session fixation/session hijack surface).
- Fix:
  - Hardened session initialization before `session_start()`:
    - `session.use_strict_mode=1`
    - `session.use_only_cookies=1`
    - `session.cookie_httponly=1`
    - `session.cookie_secure` based on request HTTPS
    - `SameSite=Lax`
  - Updated files:
    - `layouts/session.php`
    - `attex_design/layouts/session.php`

2. Missing CSRF protection on primary auth POST flows.
- Risk:
  - Login CSRF / unsolicited account actions from third-party origins.
- Fix:
  - Added CSRF token validation on POST and hidden CSRF fields in forms:
    - `pages/auth/auth-login.php`
    - `pages/auth/auth-register.php`
    - `pages/auth/auth-student-enroll.php`

3. CSRF token was not rotated after successful login transitions.
- Risk:
  - Pre-auth token reuse across auth boundary.
- Fix:
  - Added CSRF token rotation after successful auth state transitions:
    - Standard login: `pages/auth/auth-login.php`
    - Click-bypass login: `includes/login_click_bypass.php`
    - Force-password completion: `pages/auth-force-password.php`

4. Account-enumeration signal in login error behavior.
- Risk:
  - Different error paths could reveal whether a student ID exists/has linked account.
- Fix:
  - Unified missing-user credential failures to a generic invalid-credentials message.
  - Updated file:
    - `pages/auth/auth-login.php`

5. Critical test backdoor endpoint existed.
- Risk:
  - `pages/auth_test_login.php` could set `$_SESSION['user_id']=1` without authentication.
- Fix:
  - Removed file:
    - `pages/auth_test_login.php` (deleted)

## Validation Performed
- PHP syntax checks passed for all modified files:
  - `layouts/session.php`
  - `attex_design/layouts/session.php`
  - `pages/auth/auth-login.php`
  - `pages/auth/auth-register.php`
  - `pages/auth/auth-student-enroll.php`
  - `pages/auth-force-password.php`
  - `includes/login_click_bypass.php`
- Confirmed auth POST handlers in reviewed auth flows now call `csrf_validate(...)`.

## Remaining Notes / Next Security Steps
- No brute-force/rate-limit control is currently enforced in `pages/auth/auth-login.php`.
- Logout still supports GET navigation (common but CSRF-logouts remain possible by design).
- `attex_design/*` pages are template/demo surfaces; if they are public in production, consider restricting or removing.
