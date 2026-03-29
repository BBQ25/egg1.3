# Doc-Ease Laravelization Roadmap

## Current State
- Legacy app source is still under `public/doc-ease` (406 PHP files).
- Access boundary, role gate, and bridge/session lock are already controlled by Laravel.
- A Laravel-native Doc-Ease dashboard now exists at `/doc-ease`.
- Laravel-native Doc-Ease auth/portal routes are active.
- Core academic modules are partially ported:
  - Subjects CRUD at `/doc-ease/academic/subjects`.
  - Teacher assignment management with roster lock policy at `/doc-ease/academic/assignments`.

## Target State (Fully Laravelized)
- No business-critical requests execute legacy PHP entry files directly.
- Routing, auth, authorization, validation, and persistence are all handled through Laravel controllers/services/models.
- Legacy PHP pages are retired module-by-module behind feature flags.

## Migration Waves
1. Foundation (Completed)
- Laravel gateway + role middleware.
- Bridge login and direct-path lock policy.
- Laravel-native Doc-Ease dashboard and Doc-Ease DB read model.

2. Auth and Identity (In Progress)
- Laravel-native Doc-Ease auth routes now exist:
  - `GET /doc-ease/login`
  - `POST /doc-ease/login`
  - `GET /doc-ease/portal`
  - `POST /doc-ease/portal/launch-legacy`
  - `POST /doc-ease/logout`
- Remaining:
  - Complete parity for lockout/session timeout policy in Laravel middleware/session configuration.

3. Core Academic Modules (In Progress)
- Completed in Laravel:
  - Subjects CRUD + status management.
  - Teacher assignment/revoke workflow with class roster lock enforcement.
- Remaining:
  - Port sections, class records, and enrollments admin workflows.
  - Replace remaining direct mysqli academic pages with Laravel routes/controllers.

4. File and Attendance Modules
- Port upload/download/attendance endpoints to Laravel request lifecycle.
- Add policy checks and storage abstraction.

5. Admin Tools and AI Endpoints
- Port admin maintenance pages and AI-backed endpoints.
- Centralize secret handling and request auditing in Laravel.

6. Cutover
- Disable legacy launch route in production.
- Keep legacy subtree read-only for rollback window, then remove.

## Definition of Done
- Legacy launch route removed or permanently disabled.
- No production traffic path depends on `public/doc-ease/*.php` business pages.
- Doc-Ease feature tests run entirely against Laravel routes/controllers.
