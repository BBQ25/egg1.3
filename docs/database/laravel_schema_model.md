# Laravel Schema Model (Migration-Ready)

This project now includes migration files and Eloquent models aligned to `egg_monitoring`.
The `users` table additionally includes activation lifecycle columns used by admin deactivation flow:
`is_active` and `deactivated_at`.

## Migration Coverage

| Table | Laravel migration source |
|---|---|
| `users` | `database/migrations/0001_01_01_000000_create_users_table.php` |
| `app_settings` | `database/migrations/2026_02_20_000003_create_egg_monitoring_domain_tables.php` |
| `farms` | `database/migrations/2026_02_20_000003_create_egg_monitoring_domain_tables.php` |
| `egg_items` | `database/migrations/2026_02_20_000003_create_egg_monitoring_domain_tables.php` |
| `stock_movements` | `database/migrations/2026_02_20_000003_create_egg_monitoring_domain_tables.php` |
| `egg_intake_records` | `database/migrations/2026_02_20_000003_create_egg_monitoring_domain_tables.php` |
| `farm_staff_assignments` | `database/migrations/2026_02_20_000003_create_egg_monitoring_domain_tables.php` |
| `devices` | `database/migrations/2026_02_25_232124_create_devices_domain_tables.php` |
| `device_serial_aliases` | `database/migrations/2026_02_25_232124_create_devices_domain_tables.php` |
| `device_ingest_events` | `database/migrations/2026_02_25_232124_create_devices_domain_tables.php` |
| `production_batches` | `database/migrations/2026_03_15_000001_create_production_batches_table.php` |
| `evaluation_runs` | `database/migrations/2026_03_15_130000_create_evaluation_runs_tables.php` and `database/migrations/2026_04_28_000001_add_algorithm_model_to_evaluation_runs_table.php` |
| `evaluation_run_measurements` | `database/migrations/2026_03_15_130000_create_evaluation_runs_tables.php` |

## Eloquent Model Coverage

| Table | Model |
|---|---|
| `users` | `app/Models/User.php` |
| `app_settings` | `app/Models/AppSetting.php` |
| `farms` | `app/Models/Farm.php` |
| `egg_items` | `app/Models/EggItem.php` |
| `stock_movements` | `app/Models/StockMovement.php` |
| `egg_intake_records` | `app/Models/EggIntakeRecord.php` |
| `farm_staff_assignments` | `app/Models/FarmStaffAssignment.php` |
| `devices` | `app/Models/Device.php` |
| `device_serial_aliases` | `app/Models/DeviceSerialAlias.php` |
| `device_ingest_events` | `app/Models/DeviceIngestEvent.php` |
| `production_batches` | `app/Models/ProductionBatch.php` |
| `evaluation_runs` | `app/Models/EvaluationRun.php` |
| `evaluation_run_measurements` | `app/Models/EvaluationRunMeasurement.php` |

## Role Mapping Used By Auth + Admin Registration

| UI label | DB value (`users.role`) |
|---|---|
| Admin | `ADMIN` |
| Poultry Owner | `OWNER` |
| Poultry Farmer | `WORKER` |
| Customer | `CUSTOMER` |

## Reviewer-Critical Tables

| Requirement | Database support |
|---|---|
| Egg records | `device_ingest_events` stores egg UID, weight, size class, recorded time, received time, source IP, and raw payload. |
| Batches | `production_batches` stores batch code, farm, device, owner, status, start time, and end time; ingest events link through `production_batch_id`. |
| Devices | `devices` and `device_serial_aliases` store ESP32 identity, farm assignment, API key hash, heartbeat, and technical notes. |
| Users | `users`, `farms`, and `farm_staff_assignments` support owner/staff/admin access boundaries. |
| Timestamps | `app_settings.app_timezone` drives `App\Support\AppTimezone`; default display is Philippine Standard Time (PST / UTC+8). |
| Reports and actual results | Batch, production, egg record, and validation exports are calculated from `device_ingest_events`, `production_batches`, `evaluation_runs`, and `evaluation_run_measurements`. |
| Final model | `evaluation_runs.algorithm_model` records `SGMA` for validation runs and exports. |
