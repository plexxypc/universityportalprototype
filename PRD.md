# University Management Portal — Product Requirements Document

**Version:** 2.2 (supersedes v1.0 "Demo Delivery Specification", v2.0 and v2.1; Oct 2026)
**Status:** Approved for build
**Demo deadline:** Wednesday, 07 October 2026
**Companion docs:** ARCHITECTURE.md · DESIGN.md · RULES.md · TASKS.md · DECISIONS.md · MEMORY.md · TEST_PLAN.md · SECURITY.md · SYSTEM_OVERVIEW.md (features, hosting options, production costs)

> PRD = **WHAT** we are building and **WHY**. How it is built lives in ARCHITECTURE.md.

---

## 0. What changed from v1.0

| # | Change requested | How it is reflected in this PRD |
|---|---|---|
| 1 | The portal must **not** integrate with the existing CBT engine; the two stay separate. | All CBT links, APIs, result imports, candidate/attempt entities, and the CBT demo step are removed (§3.3, §7.L, §12, §17). Results enter the portal only through manual entry or CSV upload. |
| 2 | Admin uploads students via CSV, Google Form, Google Sheet or other suitable option. No student self-registration inside the portal. Students log in with an emailed **matric number + generated password**. | New onboarding module (§6). Public sign-up is removed. Applicant intake happens outside the portal (e.g. Google Form) and is imported. |
| 3 | Grading configuration: the school enters its own rules and the system calculates. | Specified in §8, including configurable score components, grade bands, pass mark, classification, repeat-course policy and a preview tool. |
| 4 | Demo should be as complete as possible, with no area left out. | Every module is in scope (§7). The "only build the vertical slice" rule is replaced by milestone gating (§19) so the core journey is protected while everything else is built. |
| 5 | Test the prototype on a free hosting service, without Supabase and with a lighter stack. | Stack is **Laravel + Filament + MySQL** (ADR-001, 002). Demo hosting on DigitalOcean + Aiven free MySQL, fallback Render (§14, ADR-003). Production options and costs are in SYSTEM_OVERVIEW.md. |
| 6 | **Scale target:** the institution has about 150 students today. The system must run up to **1,000 students** without problems; beyond **2,000** an infrastructure expansion is expected. | Capacity targets in §13.1, sizing tiers in SYSTEM_OVERVIEW.md §7 and ARCHITECTURE.md §12, a load-test gate in TASKS.md and TEST_PLAN.md. |
| 7 | **Paystack is not available to the owner.** Use **Remita** or **Interswitch (Quickteller)** for payments. | Payments redesigned around a provider-neutral gateway interface that supports invoice/reference-based providers (§9, ARCHITECTURE.md §8.2). Remita and Interswitch adapters plus the Demo Gateway; final provider chosen with the institution's bursary. |

---

## 1. Purpose

This PRD defines the University Management Portal: a staff/admin system and a student portal covering student onboarding, academic structure, course registration, fees, online payments, results, attendance, announcements, email notifications, reporting and auditing.

The October 2026 demo should be a **complete, working prototype** of every module, deployed to a public test URL.

## 2. Product vision

A single, role-based, mobile-responsive university portal where administrators and academic staff run core academic and student operations, and students receive accounts by email, register courses, pay fees, see results and receive notifications, all in one place.

## 3. Product boundaries

### 3.1 Principles
- **Build workflows, not menus.** Every module exists to complete a real university workflow.
- **Server decides.** Authorisation, validation, payment status and grade calculation are always computed server-side.
- **Admin-led onboarding.** Student accounts are created by the institution, never by self-sign-up.
- **Configurable, not hard-coded.** Grading, fee structures, matric format, credit limits and deadlines are settings.

### 3.2 Student registration is separate from the portal
There is **no public registration page** in the portal. Reasons: the portal handles money and academic records, so every account must be traceable to an admitted student; self-sign-up would require identity verification and abuse protection; and universities already collect applications elsewhere.

Instead:
1. Applications/admission data are collected **outside** the portal (Google Form, spreadsheet, or existing admissions system).
2. The registrar imports or enters them in the portal (§6).
3. The portal creates the account and emails the student their matric number and a generated password.

An optional standalone public application form can be added later as a separate deployment without changing the portal.

### 3.3 CBT engine is separate
The existing offline-first CBT engine is **a different product**. The portal:
- has no link, button, iframe, SSO, API or database connection to the CBT engine;
- does not import CBT results;
- does not model exam candidates or exam attempts;
- does not run, proctor or deliver exams.

Result data enters the portal only by **lecturer entry in the portal** or **CSV upload** (§7.K). If the university later wants to bridge the two systems, that is a separate project with its own PRD.

Exam **timetables** (course, date, time, venue) are informational and remain in scope (§7.L). They are not CBT integration.

## 4. Demo objective and delivery approach

The demo shows the full student lifecycle: **import → email → login → course registration → fees → payment → receipt → results**, plus every supporting module (attendance, announcements, reports, audit, staff, settings).

Delivery approach: all modules are in scope, built in an order that keeps the core journey demo-safe first (§19). Milestone A (core journey working and deployed) must be stable before later modules are started.

## 5. Users and roles

| Role | Primary responsibilities |
|---|---|
| Super Administrator | Full configuration, users and roles, university setup, grading, finance and system controls, audit logs. |
| Registrar / Admin Officer | Student onboarding and records, applicants, academic sessions, course registration oversight, documents, communications. |
| Bursary / Finance Officer | Fee structures, invoices, discounts/waivers, payments, reconciliation, receipts, finance reports. |
| Faculty Administrator | Faculty-level students, departments, programmes and approvals (scoped to own faculty). |
| Department Officer | Department students, courses, registration approval and result approval (scoped to own department). |
| Lecturer | Assigned courses, attendance, continuous assessment and exam scores (scoped to assigned courses). |
| Exam Officer | Exam timetable, result review and publication. |
| Student | Own profile, course registration, fees, payments, receipts, results, timetable, attendance, announcements, documents. |

### Permission matrix
● manage (within scope) · ○ view only · — no access. SA = Super Admin, REG = Registrar, BUR = Bursary, FAC = Faculty Admin, DEP = Department Officer, LEC = Lecturer, EXO = Exam Officer, STU = Student.

| Area | SA | REG | BUR | FAC | DEP | LEC | EXO | STU |
|---|---|---|---|---|---|---|---|---|
| Users, roles, system settings | ● | — | — | — | — | — | — | — |
| Grading configuration | ● | ○ | — | — | — | — | ○ | — |
| Academic structure and course catalogue | ● | ● | ○ | ● scoped | ● scoped | ○ | ○ | — |
| Student onboarding, import, records | ● | ● | ○ | ○ scoped | ○ scoped | ○ own courses | ○ | ○ own |
| Course registration (submit) | — | — | — | — | — | — | — | ● own |
| Course registration (approve) | ● | ● | — | ● scoped | ● scoped | — | — | — |
| Fee structures, invoices, discounts | ● | ○ | ● | — | — | — | — | ○ own |
| Make payments | — | — | — | — | — | — | — | ● own |
| Results: enter / upload | ● | — | — | — | — | ● own courses | — | — |
| Results: approve | ● | — | — | ○ | ● scoped | — | ● | — |
| Results: publish | ● | — | — | — | — | — | ● | — |
| Results: view | ● | ○ | — | ○ scoped | ○ scoped | ○ own courses | ○ | ○ own, published only |
| Attendance | ● | ○ | — | ○ scoped | ○ scoped | ● own courses | — | ○ own |
| Exam timetable | ● | ○ | — | ○ | ○ | ○ | ● | ○ |
| Announcements | ● | ● | ● finance | ● scoped | ● scoped | — | ○ | ○ |
| Reports and exports | ● | ● | ● finance | ○ scoped | ○ scoped | ○ own | ○ results | — |
| Audit logs | ● | — | — | — | — | — | — | — |

---

## 6. Student onboarding (new)

### 6.1 Ways to add students
| ID | Method | Details |
|---|---|---|
| ON-1 | **Single add form** | Registrar enters one student manually. |
| ON-2 | **CSV upload** | Downloadable template; UTF-8 CSV; max 5,000 rows and 5 MB. |
| ON-3 | **Excel upload (.xlsx)** | Same columns as CSV; first sheet only. |
| ON-4 | **Google Sheet link** | Registrar pastes a Google Sheet URL. The sheet must be shared as "Anyone with the link can view". The server downloads it as CSV. No Google login or API key is required. |
| ON-5 | **Google Form** | Google Forms save responses into a linked Google Sheet. The registrar imports that sheet via ON-4. The PRD provides the exact question list to build the form (§6.3). |
| ON-6 | **Applicant promotion** | Applicants (imported or entered) are reviewed, admitted, and converted to students in one action. |

Imports can target **applicants** (intake) or **students** (direct account creation).

### 6.2 Import columns
| Column | Required | Notes |
|---|---|---|
| first_name | Yes | |
| last_name | Yes | |
| other_names | No | |
| email | Yes | Must be unique. Used for delivery and as the auth identity. |
| phone | No | Nigerian formats accepted and normalised to +234. |
| gender | No | Male / Female / Other. |
| date_of_birth | No | YYYY-MM-DD or DD/MM/YYYY. |
| state_of_origin | No | |
| address | No | |
| programme_code | Yes | Must match an existing programme (e.g. CSC). |
| level | Yes | 100, 200, 300, 400, 500, 600. |
| entry_session | No | Defaults to current session. Format 2026/2027. |
| matric_number | No | If blank, the system generates one (§6.5). |
| guardian_name, guardian_phone, guardian_email, guardian_relationship | No | Next of kin. |

### 6.3 Google Form question list
Create a form with these questions, in this order, to match the import columns: First name, Last name, Other names, Email, Phone, Gender, Date of birth, State of origin, Address, Programme (dropdown containing programme codes), Level, Guardian name, Guardian phone, Guardian email, Relationship. The portal's mapping step lets the registrar match Google's column headers to portal fields, so exact header wording is not required.

### 6.4 Import workflow
1. Choose source (CSV, Excel, Google Sheet link).
2. Parse and **preview**. Show the first rows and detected columns.
3. **Map columns** to portal fields (auto-matched where names are similar).
4. **Validate** every row. Show per-row errors (missing field, invalid email, unknown programme, duplicate email or matric, invalid level).
5. Choose options: *skip duplicates* or *update existing*; *send credentials emails now* or *later*.
6. **Commit** in chunks with a progress bar. Valid rows are created even if some rows fail.
7. **Import report**: created, updated, skipped, failed counts, with a downloadable CSV of failed rows and reasons.
8. Every import is stored as an **import batch** and written to the audit log.

### 6.5 Accounts and credentials
- Matric numbers follow a configurable pattern; default `{DEPT}/{YEAR}/{SEQ4}`, e.g. `CSC/2026/0001`. Sequences are generated atomically so two admins importing at once never get duplicates.
- For each student the system creates an auth account and a **generated temporary password** (random, 12 characters).
- The student receives a **Welcome email** containing: matric number, temporary password, login URL, and instructions to change the password.
- Students log in with **matric number + password** (email also accepted).
- On first login the student **must change the password** before using the portal. Temporary passwords expire after 7 days; the registrar can re-issue credentials.
- Registrars can **resend credentials** (generates a new temporary password) for one student or a selection.
- Passwords are never stored or shown after generation, except inside the one welcome email.

### 6.6 Applicants and admissions
- Applicant statuses: Applied → Under Review → Admitted / Rejected → Converted to Student.
- Registrar can import applicants, edit them, attach documents, and bulk-admit.
- "Admit and create account" converts an applicant into a student, generating the matric number and sending the welcome email.
- Applicant records keep their source (manual, CSV, Excel, Google Sheet) and import batch.

---

## 7. System modules

Requirement IDs are used by TASKS.md and TEST_PLAN.md.

### A. Authentication and access control (AUTH)
- AUTH-1 Login by **matric number or email** plus password; logout.
- AUTH-2 Role-based permissions (§5) enforced server-side.
- AUTH-3 Forced password change on first login (students and newly created staff).
- AUTH-4 Password reset by email (link emailed through the portal's mail service).
- AUTH-5 Account activation/deactivation by authorised admins; deactivated users cannot sign in.
- AUTH-6 Session management: expiry, sign out everywhere, redirect to login on expiry.
- AUTH-7 Login throttling after repeated failures; generic error messages.
- AUTH-8 Optional TOTP two-factor for staff (stretch, last phase).

### B. University setup and configuration (SETUP)
- SETUP-1 University profile: name, short code, logo, address, contacts, motto.
- SETUP-2 Academic sessions (e.g. 2026/2027) with exactly one current session.
- SETUP-3 Semesters per session with exactly one active semester, registration deadline, add/drop deadline.
- SETUP-4 Faculties, departments, programmes (code, degree type, duration), levels.
- SETUP-5 Course catalogue: code, title, units, department, level, semester, core/elective, prerequisites.
- SETUP-6 **Grading configuration** (§8).
- SETUP-7 Fee configuration (see H).
- SETUP-8 Matric number pattern and credit-unit limits.

### C. Admissions and onboarding (ADM)
- ADM-1 Everything in §6.
- ADM-2 Applicant list with filters, status changes, and bulk actions.
- ADM-3 Document upload per applicant/student (PDF, JPG, PNG; max 5 MB).
- ADM-4 Registration confirmation and welcome emails.

### D. Student information management (STU)
- STU-1 Student profile: personal, contact, next of kin, programme, department, faculty, level, session, status.
- STU-2 Student statuses: Active, Suspended, Graduated, Withdrawn, Deferred.
- STU-3 Student list with search (name, matric, email), filters (faculty, department, programme, level, status), and pagination.
- STU-4 Student record page with tabs: Profile, Courses, Fees and payments, Results, Attendance, Documents, Activity.
- STU-5 Academic history (past sessions, registrations, results).
- STU-6 Level promotion (bulk move students to next level at session rollover).
- STU-7 Edit, suspend/reactivate, resend credentials.

### E. Student portal (PORTAL)
- PORTAL-1 Dashboard (§11).
- PORTAL-2 Profile (view; limited self-edit of phone, address, guardian contact).
- PORTAL-3 Course registration and registered courses.
- PORTAL-4 Fees/invoices, online payment, payment history, receipts.
- PORTAL-5 Results (published only) and result statement download.
- PORTAL-6 Exam timetable.
- PORTAL-7 Attendance summary.
- PORTAL-8 Notifications (in-app bell with unread count) and announcements.
- PORTAL-9 Documents (view and upload own).
- PORTAL-10 Account settings: change password.

### F. Academic management (ACAD)
- ACAD-1 CRUD for faculties, departments, programmes, courses with scope rules by role.
- ACAD-2 Course units/credits and prerequisites.
- ACAD-3 Programme-course mapping by level and semester (core/elective).
- ACAD-4 Session and semester management including rollover (new session creation, promote students).
- ACAD-5 Course registration approval queues (by department/faculty).

### G. Course registration (REG)
- REG-1 Student sees eligible courses for programme, level and active semester.
- REG-2 Core courses pre-selected; electives selectable.
- REG-3 Live credit-unit total with configured minimum and maximum enforced.
- REG-4 Prerequisite check blocks ineligible courses with a clear message.
- REG-5 Submit registration; status Draft → Submitted → Approved / Rejected (approval can be switched off in settings, in which case submission auto-approves).
- REG-6 Confirmation email and in-app notification.
- REG-7 Downloadable/printable **registration slip** (PDF).
- REG-8 Registration lock after the deadline; admins can reopen for individual students.
- REG-9 Add/drop within the add/drop window.
- REG-10 Optional setting: require minimum payment before registration (default off).

### H. Fees and finance (FIN)
- FIN-1 Fee categories (tuition, hostel levy placeholder, ICT, medical, etc.).
- FIN-2 Fee structures by programme, level and session.
- FIN-3 Invoice generation: per student, in bulk by programme/level, and automatically at student creation for the current session.
- FIN-4 Invoice items and totals.
- FIN-5 Scholarships, discounts and waivers applied as invoice adjustments, with reason and audit trail.
- FIN-6 Outstanding balance per student and per invoice.
- FIN-7 Payment history, references, receipts.
- FIN-8 Reconciliation view: gateway transactions vs ledger; flag mismatches; manual verify action.
- FIN-9 Finance dashboard: collected, outstanding, collection rate, recent transactions, by programme/level.
- FIN-10 Partial (instalment) payments allowed.

### I. Payment system (PAY)
See §9 for detailed requirements.
- PAY-1 Student picks an invoice and amount (full or partial).
- PAY-2 Server creates a pending payment with a unique internal reference and asks the gateway to start it (a checkout session, or an invoice reference such as a Remita RRR).
- PAY-3 Checkout through the chosen provider (Remita or Interswitch/Quickteller) or the built-in Demo Gateway. Remita-style references can also be paid outside the portal (bank, USSD), so a payment can stay **Pending** for hours or days.
- PAY-4 The portal learns the outcome by **re-querying the provider**: on the customer's return, on a provider notification if one is configured, by a scheduled poller for pending payments, and by an admin "re-verify" action. A notification or redirect is only a hint and is never trusted on its own.
- PAY-5 Server-side verification with the provider; amount, currency and reference must match before anything is credited.
- PAY-6 Ledger update and receipt generation are **idempotent**.
- PAY-7 Statuses: Pending, Successful, Failed, Cancelled, Expired, Reversed.
- PAY-8 Payment confirmation and receipt email.
- PAY-9 Admin transaction list, details, re-verify, receipt download.
- PAY-10 Provider is selected by configuration; adding a provider means adding one adapter class, not changing business logic.

### J. Email and notifications (MAIL)
See §10.
- MAIL-1 Transactional emails for each event listed in §10.
- MAIL-2 Provider abstraction; default provider configurable by environment.
- MAIL-3 Email outbox with status (Queued, Sent, Failed), attempts and error text; admin view with retry.
- MAIL-4 In-app notifications.
- MAIL-5 Announcements with audience targeting (all, faculty, department, programme, level) and optional email.
- MAIL-6 Admin "send test email".

### K. Results and academic records (RES)
- RES-1 Lecturer enters CA and exam scores per student per course, in the portal or by CSV upload.
- RES-2 Scores validated against configured maximums.
- RES-3 Total, grade, and grade points computed by the configured grading scheme (§8).
- RES-4 Workflow: Draft → Submitted (lecturer) → Approved (department/exam officer) → Published (exam officer).
- RES-5 Published results are locked; changes require an amendment that is audited and re-published.
- RES-6 Semester GPA, cumulative CGPA, total credit units, class of degree.
- RES-7 Student views published results by session and semester, with GPA/CGPA.
- RES-8 Printable **result statement** (PDF).
- RES-9 Result audit trail (who entered, changed, approved, published, and when).
- RES-10 Notification email when results are published.
- RES-11 Optional setting: withhold results from students with outstanding fees (default off).

### L. Examination timetable (EXAM)
*(This module is informational. It is not connected to the CBT engine.)*
- EXAM-1 Exam officer creates timetable entries: course, date, start/end time, venue, notes.
- EXAM-2 Clash detection: same venue and time, and same level/programme overlap warnings.
- EXAM-3 Students see only exams for courses they registered.
- EXAM-4 Timetable publication triggers a notification.
- EXAM-5 Timetable PDF export.

### M. Attendance (ATT)
- ATT-1 Lecturer creates class sessions for assigned courses (date, topic).
- ATT-2 Marks each registered student Present, Absent, Late or Excused.
- ATT-3 Student attendance history and percentage per course.
- ATT-4 Configurable minimum attendance threshold (default 75%) with warning indicators.
- ATT-5 Attendance summaries and CSV export.

### N. Staff and lecturer management (STAFF)
- STAFF-1 Staff profiles: staff number, title, faculty, department, status.
- STAFF-2 Create staff by form or CSV import; accounts created with temporary password (same mechanism as students).
- STAFF-3 Assign roles with scope (faculty or department).
- STAFF-4 Assign lecturers to courses per semester.
- STAFF-5 Activate/deactivate staff.

### O. Reports (RPT)
- RPT-1 Student list; admissions report; course registration report; fee/payment report; outstanding fees; results report; attendance report; transaction report.
- RPT-2 Filters (session, semester, faculty, department, programme, level, status, date range).
- RPT-3 Export to **CSV** (all reports) and **PDF** (key reports). CSV exports neutralise spreadsheet formula injection.

### P. Administration and audit (ADMIN)
- ADMIN-1 User management and role/permission management.
- ADMIN-2 Audit log (who, what, when, before/after) for admin, financial, import and result actions; filterable and exportable.
- ADMIN-3 System settings: university profile, matric format, credit limits, deadlines, approval toggles, payment provider/mode, email provider status and test.
- ADMIN-4 **Backup and export**: one-click export of core tables as a ZIP of CSV/JSON files (supplementing the database provider's own backups).
- ADMIN-5 Health/status page showing database, mail and payment configuration status.

---

## 8. Grading configuration

**What it means:** Grading configuration is the set of rules that turns raw scores into grades, grade points and an overall classification. Instead of hard-coding these rules, the Super Administrator defines them in settings, so the same engine works for any institution's grading policy.

It defines:

1. **Score components.** A list of components, each with a name and maximum score, totalling 100. Default: Continuous Assessment (CA) 30 and Exam 70. A school can instead define Test 1, Test 2, Assignment and Exam, each with its own maximum.
2. **Grade bands.** The score range for each letter grade, its grade points, and a remark.
3. **Pass mark.** The minimum total score to pass a course (e.g. 40).
4. **GPA/CGPA calculation.** GPA = Σ(grade points × course units) ÷ Σ(course units). CGPA is the same calculation across all semesters.
5. **Degree classification.** CGPA ranges mapped to classes of degree.
6. **Academic standing.** CGPA thresholds for warnings or probation (optional).
7. **Repeat-course policy.** Which attempt counts when a course is repeated (latest or best) and an optional cap on the grade awarded for resits.

### Default scheme (Nigerian 5-point scale; editable)

| Score | Grade | Points | Remark |
|---|---|---|---|
| 70–100 | A | 5.0 | Excellent |
| 60–69 | B | 4.0 | Very good |
| 50–59 | C | 3.0 | Good |
| 45–49 | D | 2.0 | Fair |
| 40–44 | E | 1.0 | Pass |
| 0–39 | F | 0.0 | Fail |

| CGPA | Classification |
|---|---|
| 4.50 – 5.00 | First Class |
| 3.50 – 4.49 | Second Class Upper |
| 2.40 – 3.49 | Second Class Lower |
| 1.50 – 2.39 | Third Class |
| 1.00 – 1.49 | Pass |
| Below 1.00 | Fail / Probation review |

Rules:
- Bands must not overlap and must cover 0–100 with no gaps; the settings page validates this before saving.
- Component maximum scores must total 100.
- Changing the scheme affects **unpublished** results immediately. Published results keep the scheme version used when they were published.
- Only the Super Administrator can edit the scheme; changes are audited.
- **The admin enters the rules; the system does all the calculation.** A preview tool lets the admin enter sample scores and see the resulting total, grade, points and GPA before saving.

---

## 9. Payment requirements

Payment is a first-class requirement. Payment **initiation** and payment **verification** are separate. A successful browser redirect, a callback parameter or a notification alone never marks a transaction as paid; the portal always confirms with the provider.

### 9.1 Providers

Paystack is **not** used (the owner cannot access it). The gateway sits behind an interface with these adapters:

| Adapter | How it works | Notes |
|---|---|---|
| **Demo Gateway** (built in) | Simulated checkout with success, failure, cancel buttons and a signed event | Used for the Wednesday demo and automated tests; follows the same initiate and verify path as real providers |
| **Remita** (SystemSpecs) | Portal requests an invoice and receives a **Remita Retrieval Reference (RRR)**; student pays through Remita's checkout, or by bank/USSD using the RRR; portal checks payment status by RRR | Supports a demo environment for testing. Supports split payments across accounts, useful for institutions that divide fees. Widely used by Nigerian universities; some institutions designate it as their only official fee platform |
| **Interswitch / Quickteller Business (Web Checkout)** | Portal sends the customer to (or opens inline) Interswitch's payment page with a unique transaction reference; the portal then re-queries the transaction by reference and amount | Sandbox test mode with test cards. Simple to integrate |

**Choosing the live provider:** ask the institution's bursary which provider they already have, or are required to use, because the settlement account, any split rules and reconciliation processes depend on it. Build the Demo Gateway first, then the adapter for the chosen provider; the second adapter is optional later work. Live onboarding with either provider requires business verification and can take time, so do not assume a live account exists for the Wednesday demo.

### 9.2 Requirements

| Requirement | Description |
|---|---|
| Money | Stored as integers in **kobo**. Displayed as Naira (₦). |
| References | A unique internal reference per payment; the provider's reference (transaction reference or RRR) stored alongside. |
| Statuses | Pending, Successful, Failed, Cancelled, Expired, Reversed. |
| Verification | Server re-queries the provider and confirms status, amount, currency and reference before updating the ledger. |
| Pending payments | Payments that are not yet settled are re-checked on a schedule (for example every few minutes for recent payments, less often for older ones) until they succeed, fail or expire, and can be re-verified manually by finance staff. |
| Notifications | If the provider can notify the portal, the endpoint treats the message only as a trigger to re-query; it never credits from the message body. Where the provider signs messages, the signature is also checked. |
| Idempotency | Repeated callbacks, notifications, polls or admin re-verifications can never double-credit an invoice. |
| Partial payments | Allowed; each attempt is its own payment/reference for the chosen amount (a fixed-amount reference such as an RRR is not edited after creation). |
| Ledger | Successful payment increases invoice `paid` amount; invoice status moves Unpaid → Part-paid → Paid. |
| Receipt | Numbered receipt, viewable in student portal and admin, downloadable as PDF. |
| Notification | Payment success email with receipt. |
| Audit | Every payment state change and manual verification is logged. |
| Stored data | Only references, statuses and amounts; never card details. |
| Reconciliation | Admin view compares portal payments with provider status and flags mismatches; export for the bursary. |

## 10. Email requirements

| Email | Trigger |
|---|---|
| Welcome and credentials (matric number + temporary password) | Student/staff account created or credentials re-issued |
| Applicant received (optional) | Applicant imported or created |
| Admission confirmation | Applicant admitted |
| Password reset | User requests reset |
| Course registration confirmation | Registration submitted/approved |
| Payment confirmation and receipt | Payment verified successful |
| Payment failed (optional) | Payment verified failed |
| Result publication | Results published |
| Announcement | Announcement sent with email enabled |
| Exam timetable published | Timetable published |

Requirements:
- Provider abstraction so the provider can change without rewriting business logic.
- HTML and plain-text versions; consistent branded template.
- Every email is written to the **outbox** with status, so the demo can always show what was sent even if a provider is slow or rate-limited.
- Free-tier sending limits apply (§14); bulk imports queue emails and send in controlled batches.

## 11. Dashboards

| Staff dashboard (content adapts to role) | Student dashboard |
|---|---|
| Total students; active applicants | Profile summary and programme/level |
| Current session and semester | Course registration status and deadline |
| Outstanding fees; payments received (chart) | Outstanding fees and Pay now action |
| Recent registrations; recent transactions | Recent payments; receipts |
| Pending approvals (registrations, results) | Latest published result and CGPA |
| Upcoming exams | Upcoming exams |
| Recent imports and email outbox status | Attendance summary |
| Announcements | Notifications and announcements |

## 12. Data model: major entities

Institution settings · User/Profile · Role assignment (with scope) · Staff · Faculty · Department · Programme · Level · Academic Session · Semester · Course · Programme-Course map · Course Prerequisite · Applicant · Student · Guardian · Document · Import Batch · Student Enrollment · Course Registration (+ items) · Fee Category · Fee Structure · Invoice · Invoice Item · Invoice Adjustment · Payment · Payment Event · Receipt · Grading Scheme · Grade Band · Classification Band · Result · Attendance Session · Attendance Record · Exam Timetable Entry · Announcement · Notification · Email Outbox · Counter (sequences) · Audit Log.

*(Removed from v1.0: Exam Candidate/Attempt, CBT sync entities.)* Table-level design is in ARCHITECTURE.md.

## 13. Non-functional requirements

### 13.1 Capacity targets

| Item | Target |
|---|---|
| Today | About 150 students |
| **Supported without problems** | Up to **1,000 students** on the launch infrastructure |
| Expansion point | Beyond about **2,000 students**, plan infrastructure expansion (more or larger servers, larger database, shared cache/queue) |
| Peak load assumption (proposed) | Up to 300 users active at the same time during registration or fee deadlines |
| Responsiveness (proposed) | 95% of page requests complete in under 2 seconds under that load, excluding time spent on the payment provider's pages |
| Proof | A load test at 1.5 times the peak assumption must pass before the system is described to the institution as supporting 1,000 students (TASKS.md, TEST_PLAN.md §17) |

These numbers are proposals for you to agree with the institution; "without hitch" is only credible once it is expressed as a measured result.

### 13.2 Other requirements

- Responsive at 375px, 768px and 1440px.
- Role-based access control enforced server-side through policies and scoped queries, with automated cross-user access tests.
- Server-side validation of all inputs, including imports and uploads.
- Payment status never depends on client-side redirects.
- Audit important administrative, financial, import and result actions.
- Passwords hashed by the framework (bcrypt/argon2); temporary passwords are random and expire.
- No card or sensitive payment data stored; only gateway references.
- HTTPS everywhere (provided by hosting).
- Accessible: WCAG 2.1 AA targets for forms, contrast, focus and keyboard use.
- Data protection: treat student data as personal data under the Nigeria Data Protection Act 2023; the demo uses fictional data only.
- Performance: primary pages load in under 3 seconds on a typical mobile connection; lists are paginated.
- Backups: provider backups plus in-app export (ADMIN-4).
- The CBT engine is not part of this system and has no dependency on it.

## 14. Hosting and test deployment

Goal: a public URL, free or near-free, where the prototype can be tested end-to-end once ready, with a clear path to production.

| Layer | Demo / test | Notes |
|---|---|---|
| Application | **DigitalOcean App Platform** running the project's Docker image (fallback: **Render** free web service) | PHP/Laravel needs a container host; Vercel and Cloudflare are not used. New DigitalOcean accounts may receive promotional credit, so verify the current offer. Render's free service sleeps when idle and takes about a minute to wake; open it before the demo. |
| Database | **Aiven free MySQL** on DigitalOcean, same region as the app | Free plan has no time limit but capped resources and may be powered off if unused. Connect over TLS with Aiven's CA certificate. |
| Email | **Brevo** free tier (or log mailer) | 300 emails/day on the free plan, so bulk imports are sent in batches by the queue. |
| Payments | **Demo Gateway** for the demo; **Remita demo environment** or **Interswitch sandbox** for provider testing | No real money. Callback/notification URLs set to the deployed domain. Live accounts need business onboarding with the provider. |
| Files | Local disk | Ephemeral on these hosts; seed data has no uploads. Production uses object storage. |
| Source control and CI | GitHub + GitHub Actions | Pint, static analysis and Pest on every push. |

Production is **not** the free stack. Production uses an always-on host, a high-availability managed MySQL plan, object storage, a verified sending domain and monitoring. Options, regions, data-residency considerations and cost estimates are in SYSTEM_OVERVIEW.md.

Test deployment requirements:
- Seed script populates realistic fictional data.
- Health endpoints (`/up`, `/health`) for uptime checks.
- Deployment checklist and smoke test in TEST_PLAN.md.

## 15. Key end-to-end journeys

**Journey 1: Onboarding to login.** Registrar imports students from CSV or Google Sheet → validation preview → commit → each student receives welcome email with matric number and temporary password → student logs in with matric number → is forced to change password → lands on dashboard.

**Journey 2: Course registration.** Student opens Course Registration → eligible courses shown → selects electives → credit total validated → submits → confirmation email → department officer approves → slip PDF available.

**Journey 3: Payment.** Student opens Fees → sees invoice → pays (full or part) → gateway → webhook and server verification → balance and history update → receipt available → confirmation email.

**Journey 4: Admin verification.** Registrar/bursar opens student record → sees registration, invoice and payment status → opens transaction → re-verifies → downloads receipt.

**Journey 5: Results.** Lecturer enters or uploads scores → grades computed → department approves → exam officer publishes → student is notified → student views results, GPA/CGPA and downloads statement.

**Journey 6: Attendance and announcements.** Lecturer marks attendance → student sees percentage; registrar posts an announcement to a level → students see it and receive email.

**Journey 7: Reporting and audit.** Registrar and bursar run reports and export; super admin reviews audit log.

## 16. Demo data

Believable Nigerian university data (fictional institution).

| Dataset | Quantity |
|---|---|
| Faculties | 5–6 |
| Departments | 12–15 |
| Programmes | 15–20 |
| Courses | 80+ |
| Students | 150+ seeded |
| Applicants | 20+ |
| Staff (lecturers etc.) | 25+ |
| Invoices | 150+ (mix of unpaid, part-paid, paid) |
| Payments | 60+ |
| Published results | 40+ students across two semesters |
| Attendance sessions | Several per course for demo courses |
| Announcements and emails | 10+ examples |
| Sample import files | One clean CSV, one CSV with deliberate errors, one prepared Google Sheet |

## 17. Demo script

1. Open the deployed URL; log in as Registrar.
2. Show dashboard.
3. Import 20 students from a **Google Sheet link**; show validation with deliberate errors; fix and commit.
4. Show the import report and the email outbox with welcome emails; open a real welcome email.
5. Log in as one new student using the **matric number and temporary password**; force password change.
6. Student dashboard: profile, programme, level, fees due.
7. Register courses; see credit-unit total; submit.
8. Department officer approves (second browser/profile); student downloads slip.
9. Student pays through the Demo Gateway (or the provider sandbox if available); return, see verified status, balance updated; open receipt PDF; show payment email. Show a Pending payment being confirmed by the re-verify action.
10. Registrar views the student record, transaction and receipt.
11. Lecturer enters results; officer approves; exam officer publishes; student sees results and GPA; downloads statement.
12. Lecturer marks attendance; student sees percentage.
13. Registrar posts an announcement; student sees it.
14. Bursar report and CSV export; super admin audit log and grading configuration.
15. Show the staff list and settings.

## 18. Acceptance criteria

**Access**
- Admin, staff and student users can log in; students log in with matric number; first login forces password change.
- Role restrictions hold; a student cannot reach staff pages or another student's data.

**Onboarding**
- Registrar can add one student, and import by CSV, Excel and Google Sheet link.
- Import shows per-row errors and still creates valid rows.
- Each created student receives an email with matric number and temporary password.
- No public registration page exists.

**Academics**
- Faculty, department, programme, course, session and semester can be created and edited.
- Student has programme, department, faculty and level.
- Course registration enforces credit limits, prerequisites and deadline; slip PDF downloads.

**Finance**
- Fee structures generate invoices; student sees balance.
- Payment completes in test mode; server verifies; ledger and invoice status update; receipt PDF exists; confirmation email logged.
- Replayed or forged webhooks do not double-credit or mark paid.

**Results, attendance, others**
- Results follow the workflow; students see only published results with correct GPA/CGPA per the grading configuration.
- Attendance can be marked and viewed.
- Announcements reach targeted students.
- Reports export to CSV; key reports to PDF.
- Audit log records admin, import, payment and result actions.

**Platform**
- Works at 375px, 768px and 1440px.
- Deployed to a public free-tier URL; smoke test passes.
- No part of the system references or depends on the CBT engine.
- No critical demo flow depends on manual database edits.

## 19. Delivery plan: milestone gating

All modules are in scope. To protect the Wednesday deadline, they are built in this order and **each milestone must be stable and deployed before the next begins**.

| Milestone | Contents | Gate |
|---|---|---|
| A: Core journey | Setup, auth, academic structure, onboarding/import with emails, student portal, course registration, fees, payments, receipts | Journeys 1–4 pass on the deployed URL |
| B: Academic completeness | Results and grading, exam timetable, attendance, staff management | Journeys 5–6 pass |
| C: Operations | Announcements, dashboards, reports, audit, backup/export, settings | Journey 7 passes |
| D: Hardening | Seed data, tests, accessibility, responsive pass, security checks, optional MFA | Full TEST_PLAN checklist |

Honest scheduling note: milestone A is the Wednesday-critical set. If time runs short, Milestones B to D continue after the demo without rework, because the architecture and data model already include them.

## 20. Out of scope

Payroll · hostel management · library · transport · procurement · advanced accounting · native mobile apps · AI features · full ERP · multi-university tenancy · **any integration with the CBT engine** · public self-registration · payment providers beyond the Demo Gateway, Remita and Interswitch (Paystack is not used).

## 21. Assumptions and open items

- Fictional institution name, code and logo are configurable and set during seeding.
- A GitHub repository, DigitalOcean account, Aiven account (free MySQL), and optionally Remita/Interswitch sandbox credentials and a Brevo account are needed (see MEMORY.md). The Demo Gateway and log mailer work without the optional two.
- Staff accounts are created by the Super Administrator or Registrar; there is no staff self-registration.
- One university per deployment.

## 22. Product principle

**Build the workflows, not the menu.** Every module exists because it completes a real university workflow, and the first demo proves a university can onboard students by import, give them accounts, register courses, charge and collect fees, publish results and communicate, all on a system that stays independent of its CBT engine.
