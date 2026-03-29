# Changelog

## 2026-02-20 - Bootstrap upgrade (Sneat vendor assets)

- Old version: `Bootstrap 5.3.3`
- New version: `Bootstrap 5.3.8`
- Updated directory: `public/vendor/sneat/assets`
- Verified custom assets still present:
  - `public/vendor/sneat/assets/img/logo.png`
  - `public/vendor/sneat/assets/css/brand.css`
  - `public/vendor/sneat/fonts/*`

### Notes

- Base-path routing behavior remains unchanged for `/sumacot/egg1.3/*`.
- Clean URL policy remains enforced (`*.php` direct access blocked by `.htaccess`, except `index.php` front controller).
- Safety snapshot created at:
  - `C:/laragon/www/sumacot/backups/egg1.3-sneat-20260220-184709`
- In this workspace, the upgrade was applied through the local staged candidate (`tmp/sneat_candidate/assets`) with validated `Bootstrap v5.3.8` runtime bundle.
