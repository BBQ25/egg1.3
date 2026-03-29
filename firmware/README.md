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
