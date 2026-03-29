# Egg Monitoring Data Dictionary

## `users`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `id` | `int unsigned` | No | PK | Internal user identifier used across all relationships. |
| `full_name` | `varchar(120)` | No |  | Display/legal name of the account holder. |
| `username` | `varchar(60)` | No | UQ | Login identity; unique handle used for authentication. |
| `password_hash` | `varchar(255)` | No |  | Hashed password (bcrypt/argon hash, never plain text). |
| `role` | `enum('ADMIN','OWNER','WORKER','CUSTOMER')` | No |  | Authorization role driving menu access and permissions. |
| `is_active` | `tinyint(1)` | No |  | Account activation flag used for login access control. |
| `deactivated_at` | `timestamp` | Yes |  | Timestamp when an admin deactivated the account. |
| `created_at` | `timestamp` | No |  | Account creation timestamp. |

## `farms`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `id` | `int unsigned` | No | PK | Farm identifier. |
| `farm_name` | `varchar(120)` | No |  | Human-readable farm name. |
| `location` | `varchar(160)` | Yes |  | Free-form location text for quick display. |
| `sitio` | `varchar(120)` | Yes |  | Sub-village/local area detail. |
| `barangay` | `varchar(120)` | Yes |  | Barangay-level address detail. |
| `municipality` | `varchar(120)` | Yes |  | Municipality/city field. |
| `province` | `varchar(120)` | Yes |  | Province field. |
| `latitude` | `decimal(10,7)` | Yes |  | GPS latitude for mapping and geo analytics. |
| `longitude` | `decimal(10,7)` | Yes |  | GPS longitude for mapping and geo analytics. |
| `owner_user_id` | `int unsigned` | Yes | FK | Linked owner account (`users.id`). |
| `is_active` | `tinyint(1)` | No |  | Farm lifecycle state (active/inactive). |
| `created_at` | `timestamp` | No |  | Farm record creation timestamp. |
| `updated_at` | `timestamp` | No |  | Last update timestamp. |

## `egg_items`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `id` | `int unsigned` | No | PK | Item/SKU identifier. |
| `farm_id` | `int unsigned` | No | FK | Owning farm (`farms.id`). |
| `item_code` | `varchar(40)` | No | UQ (with farm) | Farm-scoped item code. |
| `egg_type` | `varchar(80)` | No | IDX | Egg classification (e.g., layer, duck, specialty). |
| `size_class` | `enum(...)` | No | IDX | Commercial size grade (Reject to Jumbo). |
| `unit_cost` | `decimal(10,2)` | No |  | Cost basis per unit. |
| `selling_price` | `decimal(10,2)` | No |  | Selling rate per unit. |
| `reorder_level` | `int` | No | IDX | Threshold that triggers restock decisions. |
| `current_stock` | `int` | No | IDX | On-hand stock quantity. |
| `created_at` | `timestamp` | No |  | Item creation timestamp. |
| `updated_at` | `timestamp` | No |  | Item update timestamp. |

## `stock_movements`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | PK | Stock ledger transaction identifier. |
| `item_id` | `int unsigned` | No | FK | Item being moved (`egg_items.id`). |
| `movement_type` | `enum('IN','OUT','ADJUSTMENT')` | No | IDX | Movement category for reporting. |
| `quantity` | `int` | No |  | Quantity moved in the transaction. |
| `stock_before` | `int` | No |  | Item stock before the movement. |
| `stock_after` | `int` | No |  | Item stock after the movement. |
| `unit_cost` | `decimal(10,2)` | No |  | Cost basis at movement time. |
| `reference_no` | `varchar(80)` | No |  | Business reference/trace number. |
| `notes` | `varchar(255)` | Yes |  | Operator notes for context/audit. |
| `movement_date` | `date` | No | IDX | Business date of movement. |
| `created_at` | `timestamp` | No |  | Row insertion timestamp. |

## `egg_intake_records`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | PK | Intake event identifier. |
| `farm_id` | `int unsigned` | No | FK | Intake farm (`farms.id`). |
| `item_id` | `int unsigned` | No | FK | Affected egg item (`egg_items.id`). |
| `movement_id` | `bigint unsigned` | No | FK | Linked stock movement record (`stock_movements.id`). |
| `source` | `enum('MANUAL','ESP32')` | No | IDX | Source of intake capture (manual UI or IoT device). |
| `egg_type` | `varchar(80)` | No |  | Captured egg type at intake time. |
| `size_class` | `varchar(20)` | No |  | Captured size class at intake time. |
| `weight_grams` | `decimal(8,2)` | No |  | Observed or calculated weight. |
| `quantity` | `int` | No |  | Units added in this intake. |
| `stock_before` | `int` | No |  | Stock before intake. |
| `stock_after` | `int` | No |  | Stock after intake. |
| `reference_no` | `varchar(80)` | No |  | Cross-reference with movement/device payload. |
| `notes` | `varchar(255)` | Yes |  | Additional context. |
| `payload_json` | `text` | Yes |  | Raw device/request payload for audit/debug. |
| `created_by_user_id` | `int unsigned` | Yes | FK | Operator who recorded intake (`users.id`). |
| `recorded_at` | `timestamp` | No |  | Effective event time. |
| `created_at` | `timestamp` | No |  | Row insertion time. |

## `farm_staff_assignments`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `id` | `int unsigned` | No | PK | Assignment identifier. |
| `farm_id` | `int unsigned` | No | FK + UQ (pair) | Farm being assigned. |
| `user_id` | `int unsigned` | No | FK + UQ (pair) | Staff user assigned to farm. |
| `created_at` | `timestamp` | No |  | Assignment creation timestamp. |

## `app_settings`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `setting_key` | `varchar(100)` | No | PK | Configuration key name (unique). |
| `setting_value` | `varchar(255)` | No |  | Configuration value payload. |
| `updated_at` | `timestamp` | No |  | Last modification timestamp. |
