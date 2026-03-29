# E-Record Project Status and Progress Report

Date: 2026-02-07
Location: `c:\laragon\www\is_projects\e-record`

## Executive Summary
This project is a PHP + MySQL (Laragon) web app for managing student activity uploads and school records workflows. Recent work focused on making the system "comprehensive" by hardening authentication, implementing role-based access control (RBAC), adding student self-enrollment with admin approval, introducing teacher workflows, and creating admin reference CRUD for academic year and semesters.

## Current Scope (Working Features)
- Authentication
  - Login: `auth-login.php`
  - Registration (pending approval): `auth-register.php`
  - Student self-enrollment + enrollment submission: `auth-student-enroll.php`
  - Logout: `auth-logout.php`
- RBAC + approval gating
  - Central enforcement/helpers: `layouts/session.php`
  - Admin-only pages: `pages/admin-dashboard.php`, `pages/admin-users.php`, `pages/add-subject.php`, `pages/section.php`, and admin `process_*.php`
  - Approved-only access for students to submit activity and uploads: `todays_act.php`, `includes/upload_multiple.php`, `get_monthly_uploads.php`, `get_student_name.php`
- Student activity submission
  - "Today's Activity" converted into a wizard/tab flow: `todays_act.php`
  - Multi-upload UX/logic: `assets/js/pages/multi-upload.js`
- Admin account access management
  - Approve/revoke accounts, set roles, reset passwords (temporary password display): `pages/admin-users.php`
- Teacher workflow
  - Teacher dashboard (shows assigned classes): `pages/teacher-dashboard.php`
  - Teacher claim enrollments into class record: `pages/teacher-claim.php`
  - Admin assign teachers to subjects/section/term: `pages/admin-assign-teachers.php`
- Reference data (admin CRUD)
  - Academic Years + Semesters management: `pages/admin-references.php`
  - Reference table helpers + auto-create: `includes/reference.php`
- Subjects management UI upgrades
  - Add-subject page space optimization + parallax hero: `pages/add-subject.php`

## Roles (Current + Planned)
### Implemented (operational)
- `admin`
- `teacher`
- `student` (legacy value `user` is normalized to `student` by code)

### Added for future (UI-selectable; policies still minimal)
- `registrar`
- `program_chair`
- `college_dean`
- `guardian`

Note: these future roles can be assigned in the UI, but most pages do not yet have dedicated menus/workflows for them. RBAC enforcement should be extended as these roles get their own features.

## Architecture Notes
- UI template: Attex (assets/partials under `layouts/`, static template pages remain under `attex_design/` and `pages/`).
- Routing / URL aliasing:
  - Root `.htaccess` maps unknown root-level `*.php` requests into `pages/*.php`, while keeping core pages in root.
  - File: `.htaccess`
- Layout/menu includes were made include-safe (using `__DIR__` where needed):
  - `layouts/menu.php`
  - `layouts/session.php`

## Database Status (Compatibility + Recent Changes)
Target DB: `doc_ease` (MariaDB/MySQL)

### Compatibility fixes for `users`
The app expects columns like `useremail`, `role`, `is_active` while legacy DB had `email`, `role_id`, `status`.
- Added columns to `users`: `useremail`, `role`, `is_active`
- Added unique index on `useremail`
- Added/updated triggers to keep fields consistent between legacy and app columns:
  - `trg_users_bi_compat` (BEFORE INSERT)
  - `trg_users_bu_compat` (BEFORE UPDATE)

### Student linking
- Added `students.user_id` (nullable + unique intended) to link a student record to a login account.

### Enrollment/teacher structures (existing tables now actively used)
- `enrollments` (student self-enrollment writes `Pending`, admin approval can promote to `Active`, teacher claim can mark `Claimed`)
- `class_records`, `teacher_assignments`, `class_enrollments` are now used by:
  - `pages/teacher-claim.php`
  - `pages/admin-assign-teachers.php`
  - `pages/teacher-dashboard.php`

### Reference tables (new)
Auto-created if missing:
- `academic_years` (name, status, sort_order)
- `semesters` (name, status, sort_order)
Code: `includes/reference.php`

## UI / UX Improvements Delivered
- `todays_act.php`
  - Converted into a 3-step wizard with tabs.
  - Removed the "Recent Uploads" card; moved "Uploads for this Month" under the upload area.
- `pages/admin-users.php`
  - Password reset modal restyled to match the "modal essence" from `pages/add-subject.php` (colored header + hero content).
  - Action buttons converted to icon buttons with hover animation and tooltips.
- `pages/add-subject.php`
  - Space optimized: split into 2 columns (form + list).
  - Parallax hero added.
  - Replaced broken emoji action buttons with Remixicon icon buttons (Edit/Delete) + tooltips.

## Security / Hardening Changes
- Centralized access control helpers:
  - `require_role()`, `require_any_role()`, `require_active_role()`, `require_any_active_role()`
  - Role normalization (`user` -> `student`) to maintain backward compatibility
  - File: `layouts/session.php`
- CSRF protection + PRG pattern for admin account management:
  - `pages/admin-users.php`
- Password handling
  - Passwords are hashed; the system supports admin "reset password" (sets a new temporary password).
  - The system does not support viewing a user's existing password (by design).

## Known Issues / Gaps
- Reference data tables start empty.
  - Admin must populate Academic Years and Semesters in `pages/admin-references.php` to drive dropdowns cleanly (fallbacks exist).
- Teacher list depends on active teacher accounts.
  - Ensure you approve a teacher account and set role to `teacher` in `pages/admin-users.php`.
- Role-specific dashboards beyond Admin/Teacher/Student are not implemented yet.
  - Roles like Registrar/Dean/Guardian are assignable but currently have no dedicated pages/menus.
- CSRF coverage is not universal.
  - Admin users page is protected; other POST endpoints may still need CSRF where appropriate.

## Recommended Next Steps (Prioritized)
1. Populate Reference data in `pages/admin-references.php` (Academic Years + Semesters).
2. Add dedicated pages and RBAC rules per new roles (Registrar/Program Chair/College Dean/Guardian).
3. Extend teacher workflow:
   - Use assignments as a filter for claim page (only allow claims for assigned classes).
   - Add "class record" management page for teacher.
4. Add a formal "forgot password request" workflow (optional):
   - student/teacher submits request; admin approves + generates reset.
5. Add more CSRF protections for remaining admin POST endpoints where appropriate.

## File Index (Key Entry Points)
- Root
  - `index.php`
  - `auth-login.php`, `auth-register.php`, `auth-student-enroll.php`, `auth-logout.php`
  - `todays_act.php`
  - `get_monthly_uploads.php`, `get_student_name.php`
  - `.htaccess`
- Admin pages (in `pages/`)
  - `pages/admin-dashboard.php`
  - `pages/admin-users.php`
  - `pages/admin-assign-teachers.php`
  - `pages/admin-references.php`
  - `pages/add-subject.php`
  - `pages/section.php`
- Teacher pages (in `pages/`)
  - `pages/teacher-dashboard.php`
  - `pages/teacher-claim.php`
- Core auth/RBAC/layout
  - `layouts/session.php`
  - `layouts/left-sidebar.php`, `layouts/topbar.php`, `layouts/menu.php`
  - `includes/reference.php`

## Operational Notes
- This workspace is not a git repository (`.git` missing). If you want auditing, rollback, and clear releases, initialize git and commit the current working state.
