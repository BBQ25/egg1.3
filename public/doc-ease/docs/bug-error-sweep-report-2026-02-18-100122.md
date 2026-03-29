# Bug & Error Sweep Report
As of `2026-02-18 10:01:22 +08:00`

## Scope
- Project-wide PHP syntax sweep.
- Static include/require path validation.
- Static local `.php` link target validation.
- Fixes for high-confidence broken references/pages found during the sweep.

## Checks Run
1. `php -l` across all PHP files.
   - Result: `PHP_LINT_OK`
2. Static include/require path scan.
   - Initial findings:
     - `pages/ui-cards.php` had `@@include("./partials/page-title.html", ...)`
     - `attex_design/ui-cards.php` had `@@include("./partials/page-title.html", ...)`
   - Final result after fixes: `INCLUDE_PATHS_OK`
3. Static local PHP link target scan.
   - Initial finding:
     - `layouts/horizontal-nav.php` referenced `ui-ribbons.php` (missing file)
   - Final result after fixes: `PHP_LINKS_OK`

## Fixes Applied
1. Removed stray template directives that leaked into runtime pages:
   - `pages/ui-cards.php`
   - `attex_design/ui-cards.php`
2. Added missing target page for broken nav link:
   - `pages/ui-ribbons.php` (new)
3. Added matching page in design variant for consistency:
   - `attex_design/ui-ribbons.php` (new)
4. (From active login bypass bug work) Applied deferred multi-click matching so higher-click rules can win:
   - `pages/auth/auth-login.php`
   - `pages/auth-login-2.php`

## Validation After Fixes
- `php -l pages/ui-cards.php` passed.
- `php -l attex_design/ui-cards.php` passed.
- `php -l pages/ui-ribbons.php` passed.
- `php -l attex_design/ui-ribbons.php` passed.
- Full-project `php -l` sweep passed.
- Include path scan passed.
- Local PHP link target scan passed.

## Remaining Gaps
- No automated integration/functional test suite is present (no `composer`, `phpunit`, or JS lint config detected), so runtime behavior still requires manual QA for:
  - Authentication flows (especially Login 2 variant).
  - UI navigation in less-used template/demo pages.
