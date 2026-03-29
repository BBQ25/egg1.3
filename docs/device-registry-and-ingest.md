# Device Registry and ESP32 Ingest

## Purpose
This module registers ESP32 boards to a Poultry Owner + Farm pair and accepts authenticated device uploads.

## Admin Registry
- **Page:** `/admin/devices`
- **Access:** Admin only (`auth`, `geofence`, `admin`)
- **Capabilities:**
  - Add device
  - Edit device profile and serial aliases
  - Deactivate/reactivate device
  - Rotate API key

## Data Model
### `devices`
- Core board profile and assignment
- `primary_serial_no` is unique
- `api_key_hash` stores hashed credential
- `last_seen_at` and `last_seen_ip` updated on successful ingest

### `device_serial_aliases`
- Optional additional serial identifiers per device
- `serial_no` is unique across the system

### `device_ingest_events`
- Immutable ingest event log
- Stores parsed fields and raw payload JSON

## Ingest Endpoint
- **Route:** `POST /api/devices/ingest`
- **Throttle:** `throttle:device-ingest`
- **CSRF:** exempted for:
  - `api/devices/ingest`
  - `sumacot/egg1.3/api/devices/ingest`

### Required Headers
- `X-Device-Serial`
- `X-Device-Key`

### JSON Body
- `weight_grams` (required, numeric, `> 0`)
- `size_class` (required enum):
  - `Reject`
  - `Peewee`
  - `Pullet`
  - `Small`
  - `Medium`
  - `Large`
  - `Extra-Large`
  - `Jumbo`
- `recorded_at` (optional datetime)
- `batch_code` (optional string, max 80)
- `egg_uid` (optional string, max 80, canonical format `egg-...`)
- `metadata` (optional object)
  - supported monitoring keys include:
    - `esp32_mac`
    - `router_mac`
    - `wifi_ssid`

### Success Response (`201`)
```json
{
  "ok": true,
  "message": "Ingest accepted.",
  "data": {
    "event_id": 123,
    "device_id": 9,
    "recorded_at": "2026-02-25T23:00:00+00:00"
  }
}
```

### Unauthorized Response (`401`)
```json
{
  "ok": false,
  "message": "Unauthorized device credentials."
}
```

### Validation Error (`422`)
```json
{
  "ok": false,
  "message": "The given data was invalid.",
  "errors": {
    "weight_grams": [
      "The weight grams field is required."
    ]
  }
}
```

## Key Rotation Notes
- Device API keys are generated with high-entropy random values.
- Plaintext key is returned only once via session flash after create/rotate.
- The database stores only `api_key_hash`.
- Rotating a key immediately invalidates the previous key.

## ESP32 Example (HTTP)
```http
POST /api/devices/ingest HTTP/1.1
Host: your-host
Content-Type: application/json
X-Device-Serial: ESP32-MAIN-001
X-Device-Key: dev_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

{
  "weight_grams": 61.45,
  "size_class": "Large",
  "recorded_at": "2026-02-25T23:05:10Z",
  "batch_code": "BATCH-009",
  "egg_uid": "egg-000019",
  "metadata": {
    "firmware": "1.0.3",
    "sensor": "HX711",
    "esp32_mac": "24:6F:28:AA:10:01",
    "router_mac": "F4:EC:38:9B:77:20",
    "wifi_ssid": "PoultryPulse-Lab"
  }
}
```
