# AGENTS.md

## Project Identity
- **System Name:** Real-Time Monitoring System of the Automated Poultry Egg Weighing and Sorting Device
- **Domain:** Poultry farm automation and monitoring
- **Primary Purpose:** Automate egg weighing/sorting and provide transparent real-time and historical production records through a web application.

## Study Abstract (Canonical Context)
This study designed, developed, and evaluated an automated poultry egg weighing and sorting device integrated with a real-time monitoring application for the poultry farms of Bontoc, Southern Leyte. The system aims to assist small- to medium-scale poultry operations in complying with the Philippine National Standard for table eggs (PNS/BAFS 35:2017) by providing accurate weight-based size classification and transparent production records. Using a quantitative research design, the prototype combines an ESP32 microcontroller, a load cell with HX711 amplifier, servo motors, and a single-flow conveyor to automatically weigh, classify, and sort table eggs into Reject, Peewee, Pullet, Small, Medium, Large, Extra-Large, and Jumbo categories.

A sensor-based filtering algorithm was implemented to address vibration and signal fluctuation in the weighing section. The ESP32 continuously samples load cell readings and detects a stable window of measurements within a specified tolerance; the average of this window is used as the final egg weight. This weight is mapped to the adjusted PNS/BAFS 35:2017 ranges, and the corresponding servo actuators direct each egg into its proper bin. Simultaneously, the microcontroller transmits egg records, comprising weight, size class, timestamp, and batch information, via Wi-Fi to a PHP-JavaScript-MySQL web application, which provides real-time dashboards and historical summaries accessible through any device with a web browser.

System evaluation was conducted using actual eggs from the Southern Leyte State University - Bontoc Campus poultry farm. Results showed that the device produced weight measurements closely comparable to a calibrated digital scale and achieved high agreement between automated and manual size classifications, demonstrating that the algorithm is effective for weight-based grading at the farm level. The throughput, expressed in seconds per egg, confirmed that the prototype can handle the daily output of a small to medium flock while significantly reducing manual handling. Overall, the study demonstrates that an IoT-enabled, sensor-based egg sorting device can enhance accuracy, efficiency, and data intelligence in rural poultry operations, contributing to the broader goals of sustainable and technology-supported agriculture.

**Keywords:** automation; automated egg sorting; ESP32 microcontroller; Internet of Things (IoT); load cell; online monitoring; PNS/BAFS 35:2017; poultry egg; poultry farmer; poultry owner; real-time monitoring; table eggs; weighing and sorting of eggs

## Technical Stack
- Laravel 12 (PHP 8.2+)
- MySQL
- Blade templating
- JavaScript (Vite build)
- Playwright-backed automation services for CES and HRMIS integrations
- TCPDF for PDF report generation

## Core Application Areas
- **Authentication & Access Control**
  - Role-based access for Admin, Poultry Owner, Poultry Staff, Customer.
  - Geofence and user-premises restrictions for non-admin users.
- **Dashboard V2 (Network-Style Poultry Dashboard)**
  - Canonical dashboard route: `/dashboard` (controller-backed, no closure view rendering).
  - Live JSON refresh endpoint: `/dashboard/data`.
  - URL + session context support:
    - `range=1d|1w|1m`
    - `context_farm_id`
    - `context_device_id`
  - Real-time refresh uses authenticated polling (default 10 seconds).
  - Dashboard-only custom shell:
    - dedicated top bar + slim icon sidebar
    - modular Blade partials and modular Vite assets
  - Role-safe context filtering:
    - Admin: all farms/devices
    - Owner: own farms/devices
    - Staff: assigned farms and related devices
    - Customer: scoped aggregate with restricted context access
- **User Management**
  - Canonical page: `/admin/users` with management table + Add User modal.
  - Legacy compatibility: `/admin/users/create` redirects to `?open=create`.
- **Monitoring and Forms**
  - Grade Sheet generation/download.
  - CES automation for grade sheet and connection checks.
  - HRMIS easy login/time-in automation.
- **Settings**
  - UI typography choices and page visibility controls.
  - Geofence configuration and persistence.
- **Device Registry + Ingest**
  - Admin-managed ESP32 registry at `/admin/devices`.
  - Device assignment uses owner + farm linkage.
  - Public JSON ingest endpoint at `/api/devices/ingest` using `X-Device-Serial` and `X-Device-Key`.
  - Device keys are hashed at rest and only shown once on create/rotate.
  - Ingest persists to `device_ingest_events` and updates device `last_seen_*` metadata.

## ESP32 Ingest Contract (Canonical)
- **Endpoint:** `POST /api/devices/ingest`
- **Headers:**
  - `X-Device-Serial` (required)
  - `X-Device-Key` (required)
- **Body (JSON):**
  - `weight_grams` (required numeric)
  - `size_class` (required: Reject, Peewee, Pullet, Small, Medium, Large, Extra-Large, Jumbo)
  - `recorded_at` (optional datetime, defaults to server timestamp)
  - `batch_code` (optional string)
  - `egg_uid` (optional string)
  - `metadata` (optional object)
- **Responses:**
  - `201` with `{ ok: true, data: { event_id, device_id, recorded_at } }`
  - `401` with `{ ok: false, message: "Unauthorized device credentials." }`
  - `422` with `{ ok: false, message, errors }`

## Important Domain Rules
- Egg classes must follow adjusted **PNS/BAFS 35:2017** weight-based classifications.
- Real-time records should preserve:
  - weight
  - size class
  - timestamp
  - batch details
- Non-admin access must respect both:
  - global geofence
  - user-specific premises zone (when configured)

## Security and Operational Guardrails
- Main login is rate-limited and uses generic failure messaging.
- Bulk admin user actions require current admin password confirmation.
- Playwright automation endpoints enforce:
  - HTTPS-only targets
  - host allowlist checks
  - rejection of localhost/internal/private/reserved IP targets
- Keep sensitive credentials in `.env` only.

## Environment and Config Notes
- Check `.env.example` for required variables.
- Critical integration allowlists:
  - `CES_ALLOWED_HOSTS`
  - `HRMIS_ALLOWED_HOSTS`
- Integration endpoints:
  - CES: `forms.gradesheet.ces.download`, `forms.gradesheet.ces.test`
  - HRMIS: `forms.easy-login.hrmis.time-in`

## Testing Expectations
- Run full suite before major changes:
  - `php artisan test`
- For quick focused checks:
  - `php artisan test tests/Feature/DashboardV2Test.php`
  - `php artisan test tests/Feature/AdminUserRegistrationTest.php`
  - `php artisan test tests/Feature/AdminReportTest.php`
  - `php artisan test tests/Feature/AuthenticationTest.php`
- Dependency security checks:
  - `composer audit --format=json`
  - `npm audit --json`

## Slash Command Aliases
- `/understand`
  - Study, learn, and understand the codebase before proposing or changing anything.
  - Start by inspecting relevant routes, controllers, services, models, Blade views, docs, config, and tests.
  - Summaries should explain current behavior, key dependencies, and likely impact areas.
- `/find b.e`
  - Find bugs and errors in the codebase.
  - Default to review mode: prioritize concrete findings, risks, regressions, broken flows, and missing validation or tests.
  - Report findings first with file references whenever possible.
- `/fix b.e`
  - Fix bugs and errors in the codebase.
  - First identify the bug scope, then implement the fix, then run relevant verification or tests.
  - Prefer fixing root causes over cosmetic workarounds.
- `/serve update`
  - Means run `powershell -ExecutionPolicy Bypass -File scripts/serve-update.ps1`.
  - If extra text is supplied after the command, use it as the commit message.
- `/try all`
  - Use Playwright to visit every meaningful application page that is reachable in the current environment.
  - Capture page-load failures, console errors, broken navigation, obvious layout regressions, and auth/route blockers.
  - Summarize which pages passed, which pages failed, and the reason for each failure.
- `/try page:{name of page}`
  - Use Playwright to visit the specific named page only.
  - Resolve the page by route, menu label, title, or known feature name from the codebase.
  - Report page behavior, console errors, screenshot or visual problems, and route/auth issues if encountered.

## Serve Update Release Command
- `/serve update` is the official Codex workflow alias, not a Laravel route and not a firmware command.
- `/serve update` means run `powershell -ExecutionPolicy Bypass -File scripts/serve-update.ps1`.
- `/serve update <message>` means run the same script and use the supplied text as the commit message.
- The release workflow must:
  - run only from branch `main`
  - verify `origin` points to `BBQ25/egg1.3`
  - fail if merge, rebase, cherry-pick, or revert state is active
  - run `git diff --check`
  - run `php artisan test`
  - stage repo changes with `git add -A`
  - exclude local release-noise paths and `.ops/serve-update.local.json`
  - commit with default message `chore: serve update` when no custom message is supplied
  - push to `origin/main`
- The live deployment target is aaPanel and production updates should happen through the existing GitHub webhook at `/ops/deploy/github`.
- Safe operational references live in `docs/ops/serve-update.md`.
- Sensitive operational values belong in `.ops/serve-update.local.json`, which must never be committed.

## Refactor Roadmap Reference
- See: `docs/ui-refactor-schedule-2026-02-25.md`
- Current priority: reduce oversized Blade views and migrate inline JS to modular assets.

## Guidance for Future Coding Agents
- Preserve compatibility routes unless explicitly removed by a migration plan.
- Favor incremental, test-backed changes over broad rewrites.
- Maintain role, geofence, and auditability constraints as non-negotiable system behavior.
- Treat this codebase as a production-oriented academic/field deployment for rural poultry operations.
