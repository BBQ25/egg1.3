# Active vs Dead Code Inventory

Date: 2026-02-07
Scope: top-level app in project root (excluding `attex_design/` duplicate template copy unless noted)

## Classification Rules
- Active (Core): directly part of E-Record workflows used by real users.
- Active (Support): supporting layout/session/config/API pieces used by core flows.
- Active (Template/Non-core): reachable pages, but mostly Attex demo/template functionality.
- Legacy/Unwired: not referenced by app navigation/flows or clearly test/dev-only.

## Active (Core)
- Authentication and access control:
  - `auth-login.php`
  - `auth-register.php`
  - `admin-dashboard.php`
  - `admin-users.php`
  - `user-dashboard.php`
- Academic setup:
  - `add-subject.php`
  - `process_add_subject.php`
  - `process_edit_subject.php`
  - `process_delete_subject.php`
  - `section.php`
- Student activity/upload:
  - `todays_act.php`
  - `includes/upload_multiple.php`
  - `assets/js/pages/multi-upload.js`
  - `get_monthly_uploads.php`

## Active (Support)
- Session/layout shell:
  - `layouts/session.php`
  - `layouts/main.php`
  - `layouts/menu.php`
  - `layouts/left-sidebar.php`
  - `layouts/topbar.php`
  - `layouts/head-css.php`
  - `layouts/footer-scripts.php`
- DB config used by runtime:
  - `config/db.php`
- Auth-adjacent pages still reachable:
  - `auth-logout.php`
  - `auth-recoverpw.php`
  - `auth-lock-screen.php`
  - `auth-confirm-mail.php`

## Active (Template/Non-core)
These are linked via `layouts/left-sidebar.php`/`layouts/horizontal-nav.php` and can be opened, but they are mostly stock Attex content and not core E-Record business logic.

- Dashboard/template pages:
  - `index.php`
  - `dashboard-analytics.php`
- App demos:
  - `apps-calendar.php`, `apps-chat.php`, `apps-email-inbox.php`, `apps-email-read.php`, `apps-file-manager.php`, `apps-kanban.php`, `apps-tasks.php`, `apps-tasks-details.php`
- UI/form/chart/table/map/icon demos:
  - `ui-*.php`, `form-*.php`, `charts-*.php`, `tables-*.php`, `maps-*.php`, `icons-*.php`, `widgets.php`, `extended-*.php`
- Alt layouts/pages/auth variants:
  - `layouts-*.php`, `pages-*.php`, `auth-*-2.php`, `error-*.php`

## Legacy / Unwired / Risky Utility Files
- `auth_test_login.php`
  - Test helper that force-creates an authenticated session (`$_SESSION['user_id']=1`).
  - Should not exist in production.
- `includes/upload.php`, `includes/list_files.php`, `includes/delete_file.php`, `public/script.js`, `public/style.css`
  - Older upload stack, separate from current multi-upload flow.
  - Not referenced by current core page (`todays_act.php` uses `assets/js/pages/multi-upload.js`).
- `import_users.php`
  - One-off DB import script.
- `egg.php`
  - Standalone unrelated dashboard demo.
- `58mm-print.html`, `sample.html`
  - Standalone HTML utilities/demos, not integrated into routing/navigation.

## Broken/Drift Indicators
- `index.php` links to `file-manager.php` (`index.php:48`), but actual page is `apps-file-manager.php`.
- DB naming/config drift:
  - Runtime DB: `config/db.php` -> `doc_ease`
  - Template config: `layouts/config.php` -> `attex-php`

## Recommendation
- Keep only Core + Support for production deployment.
- Move Template/Legacy files to an archive folder or remove from deploy artifact.
- Remove `auth_test_login.php` immediately.
