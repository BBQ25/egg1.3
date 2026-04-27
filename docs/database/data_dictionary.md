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

## `devices`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | PK | Device identifier used by ingest, batch, and validation records. |
| `owner_user_id` | `int unsigned` | No | FK | Poultry owner responsible for the board (`users.id`). |
| `farm_id` | `int unsigned` | No | FK | Farm where the device is installed (`farms.id`). |
| `module_board_name` | `varchar(120)` | No |  | Board/module name displayed in the device registry. |
| `primary_serial_no` | `varchar(120)` | No | UQ | Main serial number sent in `X-Device-Serial`. |
| `main_technical_specs` | `text` | Yes |  | Human-readable hardware notes/specifications. |
| `processing_memory` | `text` | Yes |  | Controller memory/processing notes. |
| `gpio_interfaces` | `text` | Yes |  | GPIO, sensor, and actuator wiring notes. |
| `api_key_hash` | `varchar(255)` | No |  | Hashed ingest credential; plaintext key is never stored. |
| `is_active` | `tinyint(1)` | No | IDX | Device activation state for ingest authorization. |
| `last_seen_at` | `timestamp` | Yes | IDX | Latest successful runtime config or ingest heartbeat in application timezone. |
| `last_seen_ip` | `varchar(45)` | Yes |  | Latest source IP address. |
| `deactivated_at` | `timestamp` | Yes |  | Timestamp when the device was deactivated. |
| `created_by_user_id` | `int unsigned` | Yes | FK | Admin/operator who registered the device. |
| `updated_by_user_id` | `int unsigned` | Yes | FK | Admin/operator who last updated the device. |
| `created_at` | `timestamp` | No |  | Row creation timestamp. |
| `updated_at` | `timestamp` | No |  | Last update timestamp. |

## `device_serial_aliases`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | PK | Alias identifier. |
| `device_id` | `bigint unsigned` | No | FK | Linked device (`devices.id`). |
| `serial_no` | `varchar(120)` | No | UQ | Alternate serial accepted by ingest authentication. |
| `created_at` | `timestamp` | No |  | Alias creation timestamp. |

## `device_ingest_events`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | PK | Immutable egg measurement event identifier. |
| `device_id` | `bigint unsigned` | No | FK + IDX | Device that uploaded the event (`devices.id`). |
| `farm_id` | `int unsigned` | No | FK + IDX | Farm context copied from the device assignment. |
| `owner_user_id` | `int unsigned` | No | FK + IDX | Owner context copied from the device assignment. |
| `production_batch_id` | `bigint unsigned` | Yes | IDX | Linked production batch, when a batch is resolved. |
| `egg_uid` | `varchar(80)` | Yes |  | Egg identifier used for application-level deduplication per device. |
| `batch_code` | `varchar(80)` | Yes | IDX | Batch code supplied by firmware or generated by the server. |
| `weight_grams` | `decimal(8,2)` | No |  | Automated measured weight from the prototype. |
| `size_class` | `enum(...)` | No | IDX | Automated size classification. |
| `recorded_at` | `timestamp` | No | IDX | Effective event time normalized/displayed using Philippine Standard Time (PST / UTC+8) unless changed in settings. |
| `source_ip` | `varchar(45)` | Yes |  | IP address of the device request. |
| `raw_payload_json` | `longtext` | Yes |  | Full JSON payload including firmware metadata and SGMA measurement details. |
| `created_at` | `timestamp` | No |  | Server receipt time, used to compute monitoring delay. |

## `production_batches`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | PK | Production batch identifier. |
| `device_id` | `bigint unsigned` | No | FK + IDX | Device that produced the batch. |
| `farm_id` | `int unsigned` | No | FK + IDX | Farm where the batch was produced. |
| `owner_user_id` | `int unsigned` | No | FK | Owner of the batch data. |
| `batch_code` | `varchar(80)` | No | IDX | Human-readable batch code shown in Batch Monitoring. |
| `status` | `varchar(20)` | No | IDX | Batch state, usually `open` or `closed`. |
| `started_at` | `timestamp` | No | IDX | Batch start timestamp in application timezone. |
| `ended_at` | `timestamp` | Yes | IDX | Batch close timestamp; null when still open. |
| `created_at` | `timestamp` | No |  | Row creation timestamp. |
| `updated_at` | `timestamp` | No |  | Last update timestamp. |

## `evaluation_runs`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | PK | Validation/test run identifier. |
| `farm_id` | `int unsigned` | No | FK + IDX | Farm tested. |
| `device_id` | `bigint unsigned` | No | FK + UQ with run code | Prototype/device tested. |
| `owner_user_id` | `int unsigned` | No | FK | Owner of the run. |
| `performed_by_user_id` | `int unsigned` | Yes | FK | User who performed the experiment. |
| `run_code` | `varchar(80)` | No | UQ with device | Experiment run code. |
| `title` | `varchar(150)` | No |  | Descriptive run title. |
| `algorithm_model` | `varchar(40)` | No |  | Final model used by the prototype; defaults to `SGMA`. |
| `status` | `varchar(20)` | No | IDX | Run state, usually `in_progress` or `completed`. |
| `sample_size_target` | `int unsigned` | Yes |  | Target number of measured samples. |
| `started_at` | `timestamp` | No | IDX | Run start timestamp. |
| `ended_at` | `timestamp` | Yes |  | Run completion timestamp. |
| `notes` | `text` | Yes |  | Experiment notes. |
| `created_at` | `timestamp` | No |  | Row creation timestamp. |
| `updated_at` | `timestamp` | No |  | Last update timestamp. |

## `evaluation_run_measurements`

| Column | Type | Null | Key | Purpose |
|---|---|---|---|---|
| `id` | `bigint unsigned` | No | PK | Individual experiment measurement identifier. |
| `evaluation_run_id` | `bigint unsigned` | No | FK + IDX | Parent validation run. |
| `device_ingest_event_id` | `bigint unsigned` | Yes | FK + IDX | Automated device event being compared. |
| `egg_uid` | `varchar(80)` | Yes |  | Egg identifier copied for export/table readability. |
| `batch_code` | `varchar(80)` | Yes |  | Batch code copied for export/table readability. |
| `reference_weight_grams` | `decimal(8,2)` | No |  | Manual/reference scale result. |
| `automated_weight_grams` | `decimal(8,2)` | Yes |  | Prototype automated result. |
| `manual_size_class` | `varchar(20)` | No |  | Manual/reference size class. |
| `automated_size_class` | `varchar(20)` | Yes |  | Prototype size class. |
| `weight_error_grams` | `decimal(8,2)` | Yes |  | Signed error (`automated - reference`). |
| `absolute_error_grams` | `decimal(8,2)` | Yes |  | Absolute error used for MAE. |
| `class_match` | `tinyint(1)` | No |  | Whether manual and automated classifications match. |
| `measured_at` | `timestamp` | No | IDX | Experiment timestamp. |
| `notes` | `text` | Yes |  | Measurement notes. |
| `created_at` | `timestamp` | No |  | Row creation timestamp. |
| `updated_at` | `timestamp` | No |  | Last update timestamp. |

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

Important settings:

| Key | Purpose |
|---|---|
| `app_timezone` | Controls system timestamp parsing/display. The default is `Asia/Manila`, shown as Philippine Standard Time (PST / UTC+8). |

## Report Sources

The application does not require a separate report table for generated CSV/PDF-style outputs. Production reports, batch monitoring exports, egg record explorer exports, and validation accuracy exports are computed directly from `device_ingest_events`, `production_batches`, `evaluation_runs`, and `evaluation_run_measurements` so report values remain tied to the actual recorded dataset.
