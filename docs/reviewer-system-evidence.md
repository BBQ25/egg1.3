# Reviewer System Evidence Checklist

This checklist maps each panel comment to the working system artifact that demonstrates the fix or implementation.

| Panel area | System evidence |
|---|---|
| Recording system / timestamp | `App\Support\AppTimezone` centralizes timezone parsing/display. Default is `Asia/Manila`, labeled as Philippine Standard Time (PST / UTC+8). Ingest responses include `recorded_timezone` and `recorded_timezone_label`; Batch Monitoring shows timezone-labeled event and received timestamps. |
| Batch Monitoring module | `/monitoring/batches` and `/monitoring/batches/{farm}/{device}/{batchCode}` show batch code, farm/device, time window, egg count, reject count, average weight, total weight, status, received time, delay, and CSV exports with time per egg and throughput. |
| PWA / Monitoring App | `public/manifest.webmanifest`, `public/sw.js`, `resources/js/pwa-register.js`, and `resources/views/partials/pwa-head.blade.php` provide installable PWA/browser monitoring behavior. Live dashboard and ingest routes bypass the cache. |
| Database schema | `docs/database/data_dictionary.md`, `docs/database/egg_monitoring_erd.md`, and migrations cover users, farms, devices, device aliases, egg ingest events, production batches, validation runs, measurements, timestamps, and report/export sources. |
| Prototype design | `/machine-blueprint` renders the actual stored prototype image from `resources/images/device.png` with annotated queue ramp, weighing section, chute, and bins. |
| Prototype technical requirements | `/machine-blueprint` and `firmware/README.md` list ESP32, HX711 pins, servo channels, LCD, Wi-Fi, NTP, Laravel/database, and browser/PWA requirements. Physical-only values such as exact load cell capacity and servo model must be verified from the installed labels or BOM. |
| System integration | `firmware/AESM/AESM.ino` sends authenticated JSON to `/api/devices/runtime-config` and `/api/devices/ingest`; `DeviceIngestController` writes records to `device_ingest_events` and resolves `production_batches`; the PWA pages read those records for dashboards/reports. |
| Results from objectives | `/monitoring/validation` stores actual validation runs and measurements, shows SGMA as the final model, and calculates exact MAE, MSE, RMSE, class accuracy, and confusion counts from `evaluation_run_measurements`. |
| Actual experiment table | Export validation runs from `/monitoring/validation/{run}/export`; export batch data from `/monitoring/batches/export` and batch detail data from `/monitoring/batches/{farm}/{device}/{batchCode}/export`. These CSVs are the actual experiment log sources for Chapter IV. |
| Actual results, not approximate | Accuracy and speed values are computed from stored rows: MAE/MSE/RMSE from `reference_weight_grams` vs `automated_weight_grams`, class accuracy from `class_match`, time per egg/throughput from batch start/end and counts, and monitoring delay from `recorded_at` vs `created_at`. |
| SGMA / final model | `firmware/AESM/AESM.ino` defines `FINAL_ALGORITHM_MODEL = "SGMA"` and emits it in metadata; `evaluation_runs.algorithm_model` defaults to `SGMA`; validation pages and exports display the model. |

## Exact Data Sources for Chapter IV

Use only exported or queried database rows from the live/prototype dataset:

| Result needed | Source table/export | Exact formula |
|---|---|---|
| MAE | `evaluation_run_measurements` or validation CSV | `AVG(ABS(automated_weight_grams - reference_weight_grams))` |
| MSE | `evaluation_run_measurements` or validation CSV | `AVG(POWER(automated_weight_grams - reference_weight_grams, 2))` |
| RMSE | `evaluation_run_measurements` or validation CSV | `SQRT(MSE)` |
| Classification accuracy | `evaluation_run_measurements` or validation CSV | `matched_count / total_count * 100` |
| Time per egg | Batch Monitoring CSV | `(ended_at - started_at) / total_eggs` |
| Throughput | Batch Monitoring CSV | `total_eggs / duration_seconds * 60` |
| Monitoring delay | Batch detail CSV | `created_at - recorded_at` in seconds |

## Physical Verification Still Required

The application source verifies the electronic interfaces and software behavior, but two prototype specifications depend on the physical unit:

| Item | How to verify |
|---|---|
| Load cell capacity | Read the capacity marking on the installed load cell or the purchase/BOM record. |
| Servo motor model | Read the label on each installed servo or the purchase/BOM record. |

After verifying those labels, enter the exact values in the manuscript and, if desired, in the device registry `main_technical_specs` and `gpio_interfaces` fields.
