# UI Refactor Schedule (Created February 25, 2026)

## Goal
Break oversized Blade templates and inline JavaScript into smaller, testable modules without changing behavior.

## Scope Targets
- `resources/views/partials/sidebar.blade.php`
- `resources/views/admin/users/index.blade.php`
- `resources/views/admin/settings/edit.blade.php`

## Delivery Plan

### Phase 1: Users Page Extraction
- **Dates:** February 26, 2026 to March 2, 2026
- **Outputs:**
  - Move bulk-action/search/modal JS from `admin/users/index.blade.php` into `resources/js/admin/users/index.js`.
  - Keep Blade view focused on markup/partials only.
  - Add feature tests for JS-dependent states already handled server-side (modal open flags, error paths).

### Phase 2: Sidebar Decomposition
- **Dates:** March 3, 2026 to March 6, 2026
- **Outputs:**
  - Split `partials/sidebar.blade.php` into:
    - role-aware section partials
    - shared menu-item partial
    - logo/brand partial
  - Preserve all active-state and visibility behavior.
  - Add snapshot-style rendering tests for admin/customer/staff/owner menus.

### Phase 3: Settings Page JS Modularization
- **Dates:** March 9, 2026 to March 13, 2026
- **Outputs:**
  - Move large inline settings scripts into `resources/js/admin/settings/edit.js`.
  - Keep configuration payload in Blade as compact `data-*` or JSON script tag.
  - Add focused tests for settings validation/submit behavior.

### Phase 4: Cleanup and Quality Gate
- **Dates:** March 16, 2026 to March 18, 2026
- **Outputs:**
  - Standardize JS module structure under `resources/js/admin/*`.
  - Add lint/format checks for extracted JS files.
  - Final regression pass: `php artisan test` + UI smoke checks.

## Acceptance Criteria
- No single Blade file over ~500 lines in the targeted set.
- No business-critical inline scripts left in targeted views.
- Existing feature tests pass; no route or UX regressions.
- Build pipeline (`npm run build`) succeeds with extracted modules.
