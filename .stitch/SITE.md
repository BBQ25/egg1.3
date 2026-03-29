# SITE.md

## 1. Vision
Use Stitch as an exploration layer for admin-facing screens, while Laravel Blade remains the production integration target.

## 2. Stitch Project
- Project resource: `projects/7130479851483612059`
- Project title: `APEWSD Admin UI`

## 3. Existing Stitch Screens
- [x] `admin-settings` -> `projects/7130479851483612059/screens/622970c0abf54e2d90fb17b90634554f`

## 4. Existing App Pages
- `/dashboard`
- `/admin/users`
- `/admin/devices`
- `/admin/settings`
- `/admin/maps/farms`
- `/admin/forms/gradesheet`
- `/admin/forms/easy-login`

## 5. Roadmap
- [ ] Create Stitch screen for Device Registry
- [ ] Create Stitch screen for Farm & Map admin page
- [ ] Create Stitch screen for Admin Users index
- [ ] Create Stitch concept for Dashboard refinement

## 6. Integration Notes
- Stitch output should inform Blade view refinements, not replace Laravel routing or backend behavior.
- Keep Sneat conventions intact.
- Save generated HTML and screenshots to `.stitch/designs/` for reference.