# E-Record

E-Record is a **comprehensive school records and class record system** built with PHP + MySQL (Laragon).
It currently includes student activity uploads, enrollments, teacher assignments, and early grading configuration,
and is designed to expand into a fuller class record platform over time.

Current scope includes:
- Student activity submission and file uploads
- Monthly Accomplishment Reports with image proof uploads (approved student/teacher accounts)
- Subjects/sections, enrollments, and class records
- Admin approval + role-based access control (RBAC)
- Teacher assignments to classes (primary and co-teachers)
- Teacher tools (claim enrollments, configure grading components/weights, copy builds across classes)
- Subject scheduling (teacher requests with admin approval, and admin-created schedules)
- Profile editing via admin-approved requests
- Basic 1:1 messaging for users
- History/Audit log (admin + user timeline) and activity heatmap

This repo is under active development and contains both app pages and the Attex UI template sources.

## Status (Feb 2026)

Recently added:
- IT 314 / IT 314L subject + section assignment utilities (see `tools/`)
- Teacher grading config: fixed bind errors, components are editable, and builds can be copied from *any* assigned class (even different subjects)
- Import utility for draft class record spreadsheet into DB (see `tools/import_it314_sheet2_class_record.py`)
- Profile page rebuilt with admin-approved profile updates, audit timeline, and GitHub-style activity heatmap
- Messaging page (`pages/messages.php`) plus navigation wiring
- Admin pages: Profile approvals and Audit Log viewer
- Teacher dashboard UI cleanup: Notes moved above Assigned Classes (`pages/teacher-dashboard.php`)
- Scheduling: teacher schedule dashboard (daily/weekly views) + admin approvals + admin scheduling pages

## Quick Start (Laragon)

1. Put this folder under Laragon web root:
   - `c:\laragon\www\is_projects\e-record`
2. Create a MySQL database named `doc_ease`.
3. Configure DB credentials in `config/db.php`.
4. Open the app in a browser via Laragon (example):
   - `http://localhost/is_projects/e-record/`

## Routing / URLs

The root `.htaccess` maps missing root-level `*.php` requests to `pages/*.php`.
Example:
- `/admin-dashboard.php` serves `pages/admin-dashboard.php`

Files that exist in the repository root (like `auth-login.php`, `todays_act.php`) are served directly.

## Roles & Access

Roles currently used:
- `admin`
- `teacher`
- `student` (legacy value `user` is normalized to `student` in `layouts/session.php`)

Approval gating:
- teachers must be `is_active = 1` to access teacher pages
- students must be approved to submit activity/uploads

Core RBAC helpers live in `layouts/session.php`:
- `require_role()`, `require_any_role()`
- `require_active_role()`, `require_any_active_role()`

## Main Workflows (Current)

Admin:
- Manage users (approve/revoke, set role, reset password): `pages/admin-users.php`
- Maintain reference tables (Academic Years, Semesters): `pages/admin-references.php`
- Assign teachers to a subject/section/term: `pages/admin-assign-teachers.php`
- Create schedules directly: `pages/admin-schedules.php`
- Approve/reject teacher schedule requests: `pages/admin-schedule-approvals.php`
- Approve/reject user profile update requests: `pages/admin-profile-approvals.php`
- View audit logs: `pages/admin-audit-log.php`

Teacher:
- View assigned classes: `pages/teacher-dashboard.php`
- Claim enrollments into a class record: `pages/teacher-claim.php`
- Configure grading components/weights for an assigned class: `pages/teacher-grading-config.php`
- Reuse/copy grading builds from other assigned classes: `pages/teacher-grading-config.php` (Copy from another class)
- Schedule dashboard (Daily / Weekly Mon-Sun / Weekly Sun-Sat) and schedule requests: `pages/teacher-schedule.php`

Student:
- Submit "Today's Activity" + uploads: `todays_act.php`
- Create and print Monthly Accomplishment Reports with proof images:
  - entrypoint: `pages/monthly-accomplishment.php` (served as `/monthly-accomplishment.php`)
  - module source: `modules/apps/accomplishment_report/monthly-accomplishment.php`

Teacher (approved):
- Create and print own Monthly Accomplishment Reports with proof images:
  - entrypoint: `pages/monthly-accomplishment.php`
  - module source: `modules/apps/accomplishment_report/monthly-accomplishment.php`

All users:
- Profile (edit requests require admin approval): `pages/pages-profile.php` (served as `/pages-profile.php`)
- Messages (1:1): `pages/messages.php`

## Product Direction (Planned / Future)

E-Record is intended to grow beyond uploads into a full class record and academic workflow system. Examples:
- Term-based grade recording (Midterm / Final) with category/component weighting
- Score entry and computation per component, per term, per class record
- Consolidated class record views (students, attendance, grades, remarks)
- Teacher-owned Class Record Builds (templates) that can be reused across multiple subjects
- Copy/clone a Class Record Build from one subject/class to another (to avoid rebuilding the same grading setup)
- Admin-controlled limits for how many Class Record Builds a teacher can create (with a request/approval path to raise limits)
- Role-based workflows for registrar/program chair/dean/guardian
- More admin reference/config pages (sections, grade policies, etc.)

## Class Record Builds (Implemented / In Progress)

Teachers are intended to be responsible for creating their own **Class Records** per subject/section/term, and each class can
have its own grading structure. To reduce repetitive setup, E-Record will introduce **Class Record Builds**:

- A **Class Record Build** is a reusable template that defines how a term grade is built.
- A build is composed of:
  - Parameters (major grading categories like Written Works, Performance Task, Projects, Term Exam)
  - Components under each parameter (optional; e.g., Quiz, Assignment, Attendance)
  - Assessment definitions/instances (how scores are collected for each component)
  - Weight rules (parameter weights and component weights)

Reuse and copying:
- A teacher can apply the same build to multiple subjects/classes they handle.
- A teacher can also copy/clone an existing build (from one subject/class) and adjust it for another.
  - This is available in `pages/teacher-grading-config.php` and is not limited to matching subjects.

Admin build limits:
- Admin can set the number of allowable builds per teacher.
- If a teacher is limited to 1 build but handles many subjects, they can:
  - reuse the single build for all subjects, or
  - request that Admin raise their build limit.

## Teacher Assignments (Data Model)

Assignments are stored in:
- `class_records` (subject + section + academic_year + semester)
- `teacher_assignments` (teacher_id + class_record_id + role)

Teachers should be authorized by `teacher_assignments` (not only `class_records.teacher_id`), so co-teachers can access class tools.

## Profile, Messaging, and Audit

Profile edits:
- Users submit profile changes as a pending request.
- Admin reviews and approves/rejects in `pages/admin-profile-approvals.php`.

Messaging:
- Basic 1:1 messaging uses `message_threads` + `message_messages` tables.
- UI: `pages/messages.php`.

Audit:
- Audit events are written to `audit_logs` (login/logout, messaging, profile changes).
- Users can view their own audit timeline and activity heatmap in `pages/pages-profile.php`.
- Admin can view global audit logs in `pages/admin-audit-log.php`.

## Scheduling

Scheduling is stored as structured slots per class record:
- Admin can add active schedule slots directly (no approval needed).
- Teachers can request create/update/delete schedule slots which require admin approval.

Tables:
- `schedule_slots` (approved slots; used for teacher dashboards)
- `schedule_change_requests` (teacher requests; admin approves/rejects)

Notes:
- When a slot is approved or changed, `class_records.schedule` is updated with a readable summary.
- `class_records.room_number` may be auto-filled when there is exactly one room across active slots.

## Grading Model (Project Intent)

This project's grading intent is term-based within a semester:
- Each semester has 2 terms:
  1. Midterm
  2. Final term

For each term, the class grade is built from 4 Major Parameters (Categories):
- Written Works = 20%
- Performance Task = 20%
- Projects = 20%
- Term Exam = 40%
Total per term = 100%

Each Category may have Components. Example:
- Written Works (20%)
  - Quiz = 10%
  - Assignment = 10%
- Performance Task (20%)
  - Recitation = 5%
  - Attendance = 5%
  - Activity = 10%
- Projects (20%)
  - Single project, no subcomponents (Midterm Project = 20%)
- Term Exam (40%)
  - Single exam, no subcomponents (Midterm Exam = 40%)

Important rules:
- Components and scores are tracked per *term* (Midterm is separate from Final term).
- Semester/subject grade is:
  - `(MidtermGrade + FinalTermGrade) / 2`

Current tables related to grading configuration (already present in DB):
- `section_grading_configs`
- `grading_categories`
- `grading_components`

Note: To fully support Midterm vs Final having different builds, the grading config needs to be term-aware (either a `term` column on config/components, or separate configs keyed by term).

## Key Files

Core:
- `layouts/session.php` (session + RBAC + CSRF helpers)
- `config/db.php` (DB connection)
- `.htaccess` (route `*.php` to `pages/*.php`)

Admin pages:
- `pages/admin-dashboard.php`
- `pages/admin-users.php`
- `pages/admin-references.php`
- `pages/admin-assign-teachers.php`
- `pages/admin-schedules.php`
- `pages/admin-schedule-approvals.php`
- `pages/admin-profile-approvals.php`
- `pages/admin-audit-log.php`

Teacher pages:
- `pages/teacher-dashboard.php`
- `pages/teacher-claim.php`
- `pages/teacher-grading-config.php`
- `pages/teacher-builds.php`
- `pages/teacher-schedule.php`

User pages:
- `pages/pages-profile.php`
- `pages/messages.php`

Tools / Utilities:
- `tools/seed_bsit3_it314_functional_programming.php` (seed IT 314/IT 314L, sections, enrollments)
- `tools/assign_instructor_langga_it314.php` (assign instructor `langga` to IT 314/IT 314L)
- `tools/import_it314_sheet2_class_record.py` (inject draft class record from `docs/2nd Sem 2025 - 2026.xlsx` Sheet2)

## Notes / Status

See the internal progress report: `docs/00-status-progress-report.md`.
