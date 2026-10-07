# Tasks

> Never build the whole application in one prompt. Work one task at a time:
>
> `TASK-001 → Implement → Test → Review → Mark complete → TASK-002`
>
> Requirement IDs in brackets refer to PRD.md. Test items refer to TEST_PLAN.md. Update `MEMORY.md` at the end of every session.

**Milestones** (PRD §19). A **GATE** line means: stop, deploy, run the listed checks on the deployed URL, and fix issues before moving on.

| Milestone | Phases |
|---|---|
| A: Core journey (Wednesday-critical) | 0 – 10 |
| B: Academic completeness | 11 – 14 |
| C: Operations | 15 – 17 |
| D: Hardening and demo readiness | 18 – 20 |

---

## Phase 0: Accounts and prerequisites (owner)

- [ ] TASK-001 Create a private GitHub repository
- [ ] TASK-002 Create a DigitalOcean account (check for new-account credit) and note billing requirements
- [ ] TASK-003 Create an Aiven account and a **free MySQL** service on DigitalOcean in the region nearest the planned app region; download the CA certificate; note host, port, user, password
- [ ] TASK-004 Ask the institution's bursary which payment provider they use or must use (**Remita** or **Interswitch/Quickteller**); request sandbox/demo credentials and start live merchant onboarding (the Demo Gateway works without them)
- [ ] TASK-005 Create a Brevo account, verify a sender email address, create an API key or SMTP credentials (optional; the log mailer works without them)
- [ ] TASK-006 Choose demo institution name, short code and a placeholder logo
- [ ] TASK-007 (Fallback) Create a Render account for the free web service host

## Phase 1: Project setup

- [ ] TASK-008 Create the Laravel project (PHP 8.3+), commit the initial skeleton
- [ ] TASK-009 Install and configure Tailwind via Vite; set design tokens from DESIGN.md (colours, Inter self-hosted, radius)
- [ ] TASK-010 Install Livewire, Filament (staff panel at `/staff`), Pest, Larastan, Pint, `maatwebsite/excel`, `barryvdh/laravel-dompdf`
- [ ] TASK-011 Create the folder structure from ARCHITECTURE.md §4 (empty service classes, enums, support classes)
- [ ] TASK-012 Configure environments: `.env.example`, MySQL over TLS option (`DB_SSL_CA`), database sessions/cache/queue
- [ ] TASK-013 Git setup: `.gitignore`, conventional commits, protect `main`
- [ ] TASK-014 GitHub Actions CI: Pint, Larastan, Pest (against MySQL service), Composer and npm audit, frontend build
- [x] TASK-015 Docker: Dockerfile, nginx config, supervisor (php-fpm, queue worker, scheduler), entrypoint
- [x] TASK-016 Health endpoints `/up` and `/health` (database, queue heartbeat, mail/payment config status)
- [ ] TASK-017 **First deployment** to DigitalOcean App Platform with Aiven MySQL (health page only) to catch hosting problems early. The guide is written in `docs/DEPLOYMENT.md`. The deployment itself is pending.
- [x] TASK-018 Document the fallback deployment to Render and verify it boots with the same image. The guide is section 9 of `docs/DEPLOYMENT.md`, with `render.yaml` for the free Docker web service. A local boot with `PORT=10000` served `/up` and `/health`. The live Render service is still not created (TASK-007).

## Phase 2: Layout and shared UI

- [ ] TASK-019 Theme the Filament staff panel (brand colours, Inter, logo from settings, role-aware navigation groups)
- [ ] TASK-020 Blade form components: button variants, input, select, checkbox, textarea, field with error/help text
- [ ] TASK-021 Blade display components: card, badge (status map from DESIGN.md), stat card, dialog, toast, tabs
- [ ] TASK-022 Feedback components: skeleton, empty state, error state, confirm dialog
- [ ] TASK-023 Student layout: sidebar (desktop), bottom tab bar and top bar (mobile), page header, breadcrumbs
- [ ] TASK-024 Responsive table-to-card Blade component with server pagination
- [ ] TASK-025 `Money`, `Dates` (Africa/Lagos), `CsvSafe` helpers with Pest unit tests

## Phase 3: Database foundation

- [ ] TASK-026 Migrations: identity (`users`, `role_assignments`, `staff`), enums, base model traits
- [ ] TASK-027 Migrations: `institution_settings`, `faculties`, `departments`, `programmes`, `academic_sessions`, `semesters` (flag-column unique for current/active)
- [ ] TASK-028 Migrations: `courses`, `programme_courses`, `course_prerequisites`, `course_assignments`
- [ ] TASK-029 Migrations: `applicants`, `students`, `guardians`, `documents`, `import_batches`
- [ ] TASK-030 Migrations: `course_registrations`, `course_registration_items`
- [ ] TASK-031 Migrations: finance (`fee_categories`, `fee_structures`, `invoices`, `invoice_items`, `invoice_adjustments`, `payments`, `payment_events`, `receipts`) with CHECK constraints
- [ ] TASK-032 Migrations: grading (`grading_schemes`, `assessment_components`, `grade_bands`, `classification_bands`) and results (`results`, `result_scores`)
- [ ] TASK-033 Migrations: `attendance_sessions`, `attendance_records`, `exam_timetable`
- [ ] TASK-034 Migrations: `announcements`, `notifications`, `email_outbox`, `counters`, `audit_logs`
- [ ] TASK-035 Eloquent models, enum casts, relationships and factories for all tables
- [ ] TASK-036 Constraint tests (unique matric/email/reference, single current session, money CHECKs)

## Phase 4: Authentication and authorisation [AUTH-1..7]

- [ ] TASK-037 `Role` enum, `Permissions` map (PRD §5 matrix), `role_assignments` scopes, `Gate::before` for Super Admin
- [ ] TASK-038 Base policies and `visibleTo()` scopes for scoped models
- [ ] TASK-039 Login page and login action (matric number or email, generic errors, session regeneration)
- [ ] TASK-040 Login rate limiting (IP + identifier). `bootstrap/app.php` currently trusts every proxy (`at: '*'`). Revisit that when this throttle is built, because a client-supplied `X-Forwarded-For` is the address the limiter would count. See `docs/DEPLOYMENT.md`.
- [ ] TASK-041 Middleware: `EnsureActive`, `EnsurePasswordChanged`, role route groups, redirect by role
- [ ] TASK-042 Change-password page (forced first login and voluntary); clears flags; signs out other sessions
- [ ] TASK-043 Logout and session expiry handling
- [ ] TASK-044 `create-super-admin` Artisan command
- [ ] TASK-045 Auth tests: login by matric and email, invalid credentials, forced change, expired temporary password, deactivated user, route protection

## Phase 5: Audit, email and notifications [MAIL-1..4, ADMIN-2]

`/health` currently reports a scheduler heartbeat only. Once the email outbox exists (TASK-047), add a queue-backlog check to `/health`.

- [ ] TASK-046 `AuditService` and audit helper used by every service
- [ ] TASK-047 `MailService` with outbox recording, `SendOutboxEmail` job, retry rules; Brevo mailer configuration and `log` mailer
- [ ] TASK-048 Email templates: base layout plus welcome/credentials, password reset, admission, registration confirmation, payment confirmation/receipt, result published, announcement, timetable published
- [ ] TASK-049 Scheduler: `outbox:send` every minute with batch limit and daily-limit awareness
- [ ] TASK-050 Staff outbox page (Filament): list, filter, view, retry failed, send queued, **send test email**
- [ ] TASK-051 Password reset flow through `MailService`
- [ ] TASK-052 `NotificationService` and bell with unread count (staff and student layouts)
- [ ] TASK-053 Tests: template rendering (no unresolved placeholders), outbox status transitions, provider failure and retry, redaction of temporary passwords

## Phase 6: University setup [SETUP-1..8]

- [ ] TASK-054 Institution settings page (profile, logo upload, matric pattern, unit limits, approval toggle, deadlines, attendance threshold, withhold-results toggle)
- [ ] TASK-055 Academic sessions resource (single current session)
- [ ] TASK-056 Semesters resource (single active semester, registration and add/drop deadlines)
- [ ] TASK-057 Faculties, departments and programmes resources with scope rules
- [ ] TASK-058 Course catalogue resource with prerequisites and programme–course mapping
- [ ] TASK-059 `GradingService` pure functions: component totals, grade lookup, points, GPA, CGPA, classification, repeat-course policy, with Pest unit tests
- [ ] TASK-060 **Grading configuration** UI: assessment components with max scores, grade bands, pass mark, classification bands, standing thresholds, repeat policy; overlap/gap/total validation; default-scheme loader; scheme versioning
- [ ] TASK-061 Grading preview tool ("enter sample scores, see computed grade") to let the admin verify their configuration
- [ ] TASK-062 Fee categories and fee structures resources
- [ ] TASK-063 Setup tests (single current session, band validation, scoped edits)

## Phase 7: Student onboarding and records [PRD §6, ADM, STU]

- [ ] TASK-064 `StudentService`: create/update student with atomic matric generation (`counters` + `lockForUpdate`)
- [ ] TASK-065 `CredentialService`: temporary password, expiry, welcome email, **resend credentials**
- [ ] TASK-066 Single "Add student" form
- [ ] TASK-067 Student list: search, filters (faculty, department, programme, level, status), pagination
- [ ] TASK-068 Student record page with tabs (Profile, Courses, Fees and payments, Results, Attendance, Documents, Activity)
- [ ] TASK-069 Edit student; suspend/reactivate; level promotion (single and bulk)
- [ ] TASK-070 Guardians form and secure document upload (type, size, filename checks; private disk; authorised download)
- [ ] TASK-071 Import engine: CSV and Excel parsers with size/row limits
- [ ] TASK-072 Import engine: Google Sheet source (host allow-list, export URL builder, timeout, no cross-host redirects)
- [ ] TASK-073 Import wizard steps 1–2 (Livewire): choose source and preview
- [ ] TASK-074 Import wizard step 3: column mapping with auto-match
- [ ] TASK-075 Row validation engine: required fields, formats, programme/level existence, duplicate email/matric (in file and in database), per-row error table
- [ ] TASK-076 Chunked commit (25 rows per request) with progress bar, duplicate options (skip/update), send-emails now/later; `import_batches` records
- [ ] TASK-077 Import report page and downloadable failed-rows CSV; audit entry per batch
- [ ] TASK-078 Downloadable templates (CSV and Excel) and in-app Google Form guide page
- [ ] TASK-079 Applicants: manual add and import (reuse engine), list, status changes
- [ ] TASK-080 Applicants: admit and convert to student (single and bulk), admission email
- [ ] TASK-081 Auto-generate invoice for the current session when a student is created
- [ ] TASK-082 Onboarding tests: validation, duplicates, idempotent re-run, concurrent matric generation, Journey 1 browser smoke test

## Phase 8: Student portal core [PORTAL-1,2,8,9,10]

- [ ] TASK-083 Student dashboard (registration status, fees due, quick actions)
- [ ] TASK-084 Student profile page with limited self-edit
- [ ] TASK-085 Student documents page (view/upload own)
- [ ] TASK-086 Notifications page and announcements list
- [ ] TASK-087 Account settings page (change password)

## Phase 9: Course registration [REG-1..10]

- [ ] TASK-088 `RegistrationService`: eligible courses, unit limits, prerequisites, deadline rules
- [ ] TASK-089 Student course registration UI (core pre-selected, electives, live unit total, sticky summary)
- [ ] TASK-090 Submit, status handling, confirmation email and notification
- [ ] TASK-091 Approval queues for department and faculty (approve/reject with reason); auto-approve when approval is disabled
- [ ] TASK-092 Add/drop within window; admin reopen for an individual student
- [ ] TASK-093 Registration slip PDF
- [ ] TASK-094 Optional "minimum payment before registration" setting
- [ ] TASK-095 Registration tests (units, prerequisites, deadline lock, approval flow, isolation, Journey 2)

## Phase 10: Fees, invoices and payments [FIN, PAY]

- [ ] TASK-096 Invoice generation: single and bulk by programme/level/session, with preview and confirm
- [ ] TASK-097 Adjustments: scholarship, discount, waiver with reason and audit
- [ ] TASK-098 Student fees page and invoice detail
- [ ] TASK-099 `PaymentGateway` interface (`initialize`, `verify`, `parseNotification`) and **Demo Gateway** (checkout page and signed event)
- [ ] TASK-100 `PaymentService::initiate`: authorise, amount ≤ outstanding, pending payment with unique reference and expiry, store provider reference
- [ ] TASK-101 `verifyAndSettle`: re-query, compare status/amount/currency/reference, idempotent transactional settlement with `lockForUpdate`, invoice status update, expiry handling
- [ ] TASK-102 Payment return/status page (verifying → success / pending / failed / cancelled / expired) with "Check status" button
- [ ] TASK-103 Notification endpoint (outside CSRF, rate-limited, reference only, triggers re-query) and `payments:poll-pending` scheduled job with back-off
- [ ] TASK-104 Adapter for the **institution's chosen provider**: Interswitch Web Checkout (redirect/inline, requery) **or** Remita (RRR invoice, status by RRR); verify against the provider's sandbox/demo
- [ ] TASK-105 *(Optional)* Second provider adapter (the one not chosen first)
- [ ] TASK-106 Receipts: numbering, viewer and PDF
- [ ] TASK-107 Payment confirmation email and notification
- [ ] TASK-108 Admin transactions list and detail; re-verify; receipt download
- [ ] TASK-109 Reconciliation view and finance dashboard
- [ ] TASK-110 Payment tests (success, failure, cancel, expired, still-pending then confirmed by poller, duplicate notification, forged callback, amount mismatch, partial payments, concurrent settle, Journeys 3 and 4)

**GATE (Milestone A):** deploy to the demo host with Aiven MySQL; run Journeys 1–4 on the live URL with the Demo Gateway (and the provider sandbox if available); confirm emails appear in the outbox and in a real inbox; fix all blockers. Do not start Phase 11 until this passes.

## Phase 11: Results and grading [RES-1..11]

- [ ] TASK-111 `ResultService`: draft results with component scores validated against configured maximums
- [ ] TASK-112 Lecturer results entry UI per assigned course
- [ ] TASK-113 Results CSV upload with validation and row errors
- [ ] TASK-114 Workflow: submit → approve → publish, with role checks and locking on publish
- [ ] TASK-115 Amendment process for published results (audited, re-publish)
- [ ] TASK-116 GPA, CGPA, total units, classification and academic standing (via `GradingService`)
- [ ] TASK-117 Student results page by session/semester and result statement PDF
- [ ] TASK-118 Result publication emails and notifications
- [ ] TASK-119 Admin result views (by course, department) and approval queue
- [ ] TASK-120 Optional "withhold results for outstanding fees" setting
- [ ] TASK-121 Results tests (grading boundaries with custom schemes, GPA/CGPA, workflow permissions, lock, isolation, Journey 5)

## Phase 12: Attendance [ATT-1..5]

- [ ] TASK-122 `AttendanceService` (sessions, records, percentages, threshold)
- [ ] TASK-123 Lecturer UI: create session, mark all/individual, edit within allowed window
- [ ] TASK-124 Student attendance view per course
- [ ] TASK-125 Summaries, low-attendance warnings and CSV export
- [ ] TASK-126 Attendance tests

## Phase 13: Examination timetable [EXAM-1..5]

- [ ] TASK-127 `ExamService` with venue and level/programme clash detection
- [ ] TASK-128 Exam officer timetable management UI
- [ ] TASK-129 Student timetable (registered courses only)
- [ ] TASK-130 Publish notification/email and timetable PDF
- [ ] TASK-131 Exam timetable tests

## Phase 14: Staff management [STAFF-1..5]

- [ ] TASK-132 `StaffService`: create/update with credentials (reuse `CredentialService`)
- [ ] TASK-133 Staff list and profile pages
- [ ] TASK-134 Staff CSV import (reuse import engine)
- [ ] TASK-135 Role assignment with faculty/department scope
- [ ] TASK-136 Lecturer–course assignments per semester
- [ ] TASK-137 Activate/deactivate staff
- [ ] TASK-138 Staff tests

**GATE (Milestone B):** Journeys 5 and 6 pass on the deployed URL.

## Phase 15: Announcements [MAIL-5]

- [ ] TASK-139 `AnnouncementService` with audience targeting (all, faculty, department, programme, level)
- [ ] TASK-140 Compose and list UI with optional email
- [ ] TASK-141 Student announcement display and notifications
- [ ] TASK-142 Announcement tests (targeting, scoped authors)

## Phase 16: Dashboards and reports [RPT, dashboards]

- [ ] TASK-143 Staff dashboard widgets per role (stats, payments chart, approvals, recent activity, outbox status)
- [ ] TASK-144 `ReportService` for the eight reports
- [ ] TASK-145 Reports UI with filters
- [ ] TASK-146 CSV export with formula-injection protection
- [ ] TASK-147 PDF export for key reports
- [ ] TASK-148 Reports tests

## Phase 17: Administration and audit [ADMIN-1..5]

- [ ] TASK-149 Audit log viewer: filter by actor, entity, action, date; export
- [ ] TASK-150 User management page and role/permission view
- [ ] TASK-151 Backup and export (ZIP of CSV/JSON of core tables)
- [ ] TASK-152 Settings status panel (database, queue, mail, payment configuration)
- [ ] TASK-153 Admin tests

**GATE (Milestone C):** Journey 7 passes on the deployed URL.

## Phase 18: Demo data

- [ ] TASK-154 Seeder: institution, faculties, departments, programmes, courses, sessions, semesters, fee structures, grading scheme
- [ ] TASK-155 Seeder: staff, 150+ students, applicants, guardians
- [ ] TASK-156 Seeder: invoices (mixed states), payments and receipts, registrations
- [ ] TASK-157 Seeder: results (published and draft), attendance, exam timetable, announcements, notifications
- [ ] TASK-158 Sample import files (clean CSV, CSV with deliberate errors) and a prepared public Google Sheet
- [ ] TASK-159 Reset and re-seed command (refuses to run outside local/demo)

## Phase 19: Hardening

- [ ] TASK-160 Responsive pass at 375px, 768px and 1440px across every screen
- [ ] TASK-161 Accessibility pass (keyboard, focus, labels, contrast, axe checks)
- [ ] TASK-162 Security review against SECURITY.md (headers/CSP, policies, uploads, import SSRF, Livewire property tampering)
- [ ] TASK-163 Cross-student and cross-scope isolation test sweep for every model and download route
- [ ] TASK-164 Performance pass (indexes, pagination, N+1, PDF generation time)
- [ ] TASK-165 Browser smoke suite for Journeys 1–7
- [ ] TASK-166 Copy review; confirm every view has loading, empty and error states
- [ ] TASK-167 Confirm nothing references the CBT engine (search code, config, docs)
- [ ] TASK-168 *(Stretch)* Optional TOTP two-factor for staff accounts [AUTH-8]
- [ ] TASK-169 Agree capacity targets with the institution (PRD §13.1); **load test** registration, fee-payment and login paths at 1.5× the peak assumption (k6 or similar) on a staging copy sized like Tier 1; record results in MEMORY.md
- [ ] TASK-170 Set up monitoring and alerts for the upgrade triggers in ARCHITECTURE.md §12 (CPU, memory, p95 response, database load, queue backlog)

## Phase 20: Deployment and demo readiness

- [ ] TASK-171 Set production environment variables on the host (no secrets in repo)
- [ ] TASK-172 Run migrations and seeder on the Aiven database; run `create-super-admin`
- [ ] TASK-173 Configure the payment provider's callback (and notification, if used) URLs to the deployed domain, or confirm `PAYMENT_PROVIDER=demo`
- [ ] TASK-174 Verify Brevo sender and send a test email from Settings
- [ ] TASK-175 Run the TEST_PLAN smoke test on the deployed URL
- [ ] TASK-176 Confirm the Aiven free service is running (not powered off) and the app is awake the day before and the morning of the demo
- [ ] TASK-177 Rehearse the demo script twice; capture screenshots/video as a fallback
- [ ] TASK-178 Prepare fallbacks: `PAYMENT_PROVIDER=demo`, `MAIL_MAILER=log`, Render fallback deployment

**GATE (Milestone D):** full TEST_PLAN checklist passes.

## Post-demo backlog (production readiness)

- [ ] TASK-179 Choose production region and host with the institution (SYSTEM_OVERVIEW.md §6); obtain data-residency advice
- [ ] TASK-180 Provision Tier 1 production (ARCHITECTURE.md §12): paid, always-on host and managed MySQL (HA recommended); enable and test backups and restore
- [ ] TASK-181 Switch file storage to S3-compatible object storage (DigitalOcean Spaces)
- [ ] TASK-182 Custom domain, verified sending domain (SPF/DKIM/DMARC), live payment-provider credentials and callback/notification URLs
- [ ] TASK-183 Error tracking, uptime monitoring and alerting
- [ ] TASK-184 Review capacity at 1,000 students and plan Tier 2/3 expansion before passing 2,000
- [ ] TASK-185 Standalone public application form (separate deployment)
- [ ] TASK-186 Email template editor, advanced permission matrix, additional payment gateways
