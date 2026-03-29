# Security Hardening Checklist (Prioritized)

Date: 2026-02-07
Scope: current repository state

## P0 - Fix Immediately

- Remove or disable test auth bypass endpoint.
  - Evidence: `auth_test_login.php:6` sets `$_SESSION['user_id'] = 1`.
  - Risk: full authentication bypass if exposed.
  - Action: delete file or block at web server level in all non-local environments.

- Add authorization gates to business pages that currently only require login.
  - Evidence: `add-subject.php:1`, `section.php:1`, `todays_act.php:1` include session but do not call `require_role()`/`require_approved_user()`.
  - Risk: any authenticated account can access subject/section/admin-like operations.
  - Action: add explicit role checks per page (for example admin-only for subject/section management).

- Add authorization to AJAX/data endpoints that expose records.
  - Evidence: `get_monthly_uploads.php` has no session check; `includes/list_files.php:2` and `includes/delete_file.php:2` use direct DB include without auth guard.
  - Risk: unauthenticated data leakage and/or destructive operations.
  - Action: include `layouts/session.php` and enforce role/owner checks on each endpoint.

- Implement CSRF protection for state-changing actions.
  - Evidence: POST forms and AJAX endpoints have no CSRF token validation (`admin-users.php`, `add-subject.php`, `section.php`, `process_*.php`, `includes/upload_multiple.php`).
  - Risk: forced actions from third-party sites while user is logged in.
  - Action: add per-session token generation + hidden field/header + server verification.

## P1 - High Priority

- Convert string-built SQL to prepared statements end-to-end.
  - Evidence:
    - `process_edit_subject.php:41`
    - `process_delete_subject.php:22`
    - `includes/upload.php:71`
    - `includes/upload_multiple.php:51`, `includes/upload_multiple.php:157`
    - `includes/list_files.php:14`, `includes/list_files.php:19`
    - `includes/delete_file.php:12`, `includes/delete_file.php:29`
  - Risk: SQL injection and query fragility.
  - Action: prepared statements with bound parameters for every dynamic value.

- Escape flash/session messages before HTML output.
  - Evidence:
    - `section.php:184`, `section.php:187`
    - `add-subject.php:173`, `add-subject.php:374`
  - Risk: stored/reflected XSS if message contains unsafe content.
  - Action: wrap output with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

- Fix file deletion logic to use stored path safely.
  - Evidence: `includes/delete_file.php:12` reads only `file_name`; `includes/delete_file.php:21` assumes `../uploads/<file_name>`.
  - Risk: uploaded files in nested folders from `upload_multiple.php` will not be deleted correctly; potential orphaned files and inconsistent state.
  - Action: fetch and validate `file_path` from DB, normalize via `realpath`, verify within uploads root, then unlink.

- Tighten directory permissions for created upload folders.
  - Evidence: `includes/upload_multiple.php:117` uses `mkdir(..., 0777, true)`.
  - Risk: overly permissive filesystem access.
  - Action: use `0755` (or stricter based on deployment user/group policy).

- Remove duplicate `session_start()` calls.
  - Evidence: `auth-logout.php:1` includes `layouts/session.php` (already starts session), then `auth-logout.php:6` calls `session_start()` again.
  - Risk: warnings/noise and session handling bugs in stricter environments.
  - Action: keep only one session bootstrap path.

## P2 - Medium Priority

- Add login brute-force controls and stronger password policy.
  - Evidence: `auth-login.php` has no rate limiting/lockout; `auth-register.php` has no password complexity check.
  - Action: throttle by IP/user, temporary lockout/backoff, minimum password policy.

- Enforce ownership checks for user-visible file queries.
  - Evidence: `get_monthly_uploads.php` returns all uploads for current month.
  - Risk: cross-user data visibility.
  - Action: filter by user/student identity and role.

- Resolve routing drift and dead links.
  - Evidence: `index.php:48` links to `file-manager.php` (missing), while app uses `apps-file-manager.php`.
  - Action: fix links, remove stale endpoints and unused legacy stack.

- Standardize DB configuration source.
  - Evidence: runtime DB in `config/db.php` (`doc_ease`) differs from `layouts/config.php` (`attex-php`).
  - Risk: accidental writes/reads from wrong database.
  - Action: centralize DB config and remove duplicate/conflicting config files.

## Verification Checklist After Hardening

- [ ] Unauthenticated requests to upload/list/delete/monthly endpoints return 401/403.
- [ ] Non-admin users cannot access admin or management endpoints.
- [ ] CSRF attacks fail for all POST routes.
- [ ] Upload and delete operations are consistent for nested paths.
- [ ] XSS test payloads in flash messages render as text, not HTML.
- [ ] Session flow has no warnings in PHP logs.
