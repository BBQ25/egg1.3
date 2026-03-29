# Curated Icon Pack

This folder contains the recommended starter icon pack for the Real-Time Monitoring System of the Automated Poultry Egg Weighing and Sorting Device.

## Structure

- `ui-flat/`
  - Small flat PNGs for navigation, buttons, badges, tables, and compact UI.
- `illustration-3d/`
  - Larger 3D PNGs for dashboard hero cards, empty states, onboarding, and feature callouts.

## Style Rules

- Use `ui-flat` for operational UI.
- Use `illustration-3d` only as supporting artwork.
- Do not mix flat and 3D icons inside the same nav bar, table, or form control group.
- Prefer these curated filenames over the original vendor filenames.

## Suggested App Mapping

- Dashboard: `illustration-3d/dashboard.png`
- Farms / Topology: `ui-flat/farm.png` or `ui-flat/location.png`
- Devices: `ui-flat/devices.png`
- Egg production: `ui-flat/eggs.png`
- Connectivity: `ui-flat/signal.png`
- Reports / Grade sheets: `ui-flat/report-pdf.png` or `illustration-3d/statistics-report.png`
- Settings: `ui-flat/settings.png`
- Security: `illustration-3d/lock.png`

## Notes

- Source assets were copied from the broader `resources/icons` vendor library.
- This folder is intended to be stable and app-specific.
- If additional icons are needed, add them here with simple semantic names and update `manifest.json`.
