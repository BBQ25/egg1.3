# egg_1.3

## Firmware Workflow

The official ESP32 firmware file is:

- `firmware/AESM/AESM.ino`

Backup firmware is stored at:

- `firmware/backup/AESM.ino`

Rules:

- Edit only `firmware/AESM/AESM.ino`
- Do not create or edit duplicate root-level `.ino` files
- Commit firmware changes from inside this repo so GitHub keeps the current official sketch
- Upload the sketch to the ESP32 manually from Arduino IDE or another ESP32 upload tool
