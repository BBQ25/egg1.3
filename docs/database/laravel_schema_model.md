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

## Role Mapping Used By Auth + Admin Registration

| UI label | DB value (`users.role`) |
|---|---|
| Admin | `ADMIN` |
| Poultry Owner | `OWNER` |
| Poultry Farmer | `WORKER` |
| Customer | `CUSTOMER` |
