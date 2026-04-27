## Firmware

This folder tracks the ESP32 egg sorting firmware alongside the Laravel app.

Official sketch:
- `firmware/AESM/AESM.ino`

Backup sketch:
- `firmware/backup/AESM.ino`

Notes:
- The firmware files are versioned in GitHub when changed inside `egg_1.3`.
- Website auto-deploy tracks this folder because it is inside the repo.
- Firmware changes still do not flash the ESP32 automatically. The board must be uploaded manually from Arduino IDE or another ESP32 upload tool.

## Active Prototype Requirements

| Area | Requirement |
|---|---|
| Controller | ESP32 board running `firmware/AESM/AESM.ino`. |
| Final model | SGMA, exposed as `FINAL_ALGORITHM_MODEL` and included in device metadata. |
| Weight sensing | HX711 amplifier, DOUT GPIO 4, SCK GPIO 5, calibrated with `calibration_factor`. Confirm the physical load cell capacity from the installed load cell label or BOM. |
| Actuation | Five servo channels: Gate 1 GPIO 14, Gate 2 GPIO 26, Pusher GPIO 27, Pan GPIO 12, Tilt GPIO 13. Confirm the physical servo model from the installed servo label or purchase record. |
| Display | 16x2 I2C LCD at address `0x27`. |
| Network | Wi-Fi connection, NTP time sync, HTTPS access to `/api/devices/runtime-config` and `/api/devices/ingest`. |
| Server | Laravel app and database with device registry, ingest events, production batches, and validation run tables. |
| Browser/PWA | Monitoring pages run in a browser/PWA served by the Laravel app manifest and service worker. |

The firmware sends exact measurement metadata (`measurement_avg_g`, `measurement_median_g`, `measurement_trimmed_avg_g`, `measurement_span_g`, sample count, local/cloud class, firmware version, and SGMA model) so the database can support actual experiment tables instead of approximate values.
