# DESIGN.md

## 1. Product Context
This project is the admin and monitoring interface for the Real-Time Monitoring System of the Automated Poultry Egg Weighing and Sorting Device. Interfaces should feel operational, practical, and suitable for a production-oriented academic field deployment.

## 2. UI Baseline
- Preserve Sneat admin patterns.
- Prefer compact desktop density over oversized marketing layouts.
- Use white or light neutral card surfaces.
- Use rounded cards, subtle shadows, strong spacing rhythm, and clear hierarchy.
- Do not use gradients unless explicitly requested.
- Keep components practical and information-rich.

## 3. Color and Tone
- Primary accent: Sneat-compatible admin blue.
- Support states: success green, warning amber, danger red, info cyan.
- Avoid experimental color themes unless explicitly requested.

## 4. Typography
- Respect the project's runtime font setting system.
- UI should remain legible and dense at admin dashboard scale.
- Avoid oversized headings and excessive whitespace.

## 5. Component Rules
- Use Sneat-style cards, badges, buttons, dropdowns, form controls, tabs, and side panels.
- Buttons should feel product-grade, not generic or overly decorative.
- Prefer icon + label pairings for admin actions.
- For settings pages, surface quick summary stats at the top and keep editing controls close to their previews.

## 6. Layout Rules
- Desktop-first for admin pages.
- Support mobile without collapsing into huge stacked blocks.
- Keep navigation, save actions, and page summary visible without wasting vertical space.
- Use two-column settings layouts when appropriate.

## 7. Domain-Specific Guidance
- Emphasize clarity for poultry farm operators and administrators.
- Key modules include Dashboard, Users, Devices, Settings, Farm & Map, and Reports.
- Geofence, device credential handling, and historical visibility controls should feel trustworthy and auditable.