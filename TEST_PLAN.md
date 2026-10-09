# Test Plan

> Defines what "working" actually means. A task is complete only when the relevant items here pass. Tick items on the deployed demo URL before every milestone gate.

**Test levels:** U = unit (Pest) · I = integration / feature (Pest + Livewire tests against MySQL) · E = end-to-end journey (Pest feature flow or Playwright smoke) · M = manual.

## Test accounts (created by seed script; passwords come from `DEMO_SEED_PASSWORD`, never committed)

| Role | Email |
|---|---|
| Super Admin | superadmin@demo.example |
| Registrar | registrar@demo.example |
| Bursar | bursar@demo.example |
| Faculty Admin | faculty.admin@demo.example |
| Department Officer | dept.officer@demo.example |
| Lecturer | lecturer@demo.example |
| Exam Officer | exam.officer@demo.example |
| Students | Seeded matric numbers, e.g. CSC/2026/0001 |

## 1. Authentication [AUTH]

- [ ] (E) Student logs in with **matric number** and password
- [ ] (E) Student logs in with email and password
- [ ] (E) Staff logs in with email and password and lands on the staff dashboard
- [ ] (E) Invalid credentials show one generic error (no hint whether matric or password was wrong)
- [ ] (I) Repeated failed logins are throttled
- [ ] (E) First login with temporary password forces password change before any other page
- [ ] (I) Expired temporary password is rejected; registrar can re-issue
- [ ] (E) Password reset email is queued, link works once, new password works
- [ ] (E) Deactivated user cannot log in
- [ ] (E) Logged-out users are redirected to login from any private route
- [ ] (E) Student cannot open `/staff/*`; staff cannot open `/student/*` data of another role
- [ ] (E) Session expiry returns the user to login without data loss on public pages
- [ ] (E) No registration/sign-up page or link exists anywhere

## 2. Authorisation and data isolation (release gate)

- [x] (I) Student A cannot read Student B's profile, invoices, payments, results, registrations, documents
- [x] (I) Faculty Admin sees only own faculty's students; Department Officer only own department
- [x] (I) Lecturer can enter results only for assigned courses
- [ ] (I) Only Exam Officer / Super Admin can publish results; only Super Admin edits grading scheme
- [ ] (I) Only Super Admin can read audit logs
- [ ] (I) Direct API/action calls with a student session to staff actions return forbidden
- [x] (I) Every model with a Policy has a test proving denial for an unauthorised user
- [ ] (I) Every download/PDF/receipt/document route rejects another user's record id
- [ ] (I) Tampering with a Livewire public property (e.g. another student id) is rejected

## 3. University setup [SETUP]

- [ ] (E) Create session, semester, faculty, department, programme, course; edit and archive
- [ ] (I) Only one current session and one active semester can exist
- [ ] (E) Course prerequisites and programme-course mapping save correctly
- [ ] (U) Grading scheme validation rejects overlapping bands, gaps, and weights not totalling 100
- [ ] (U) Score-to-grade conversion at every boundary (39, 40, 44, 45, 49, 50, 59, 60, 69, 70, 100)
- [ ] (U) GPA and CGPA calculations with mixed units, repeated courses and zero-unit edge cases
- [ ] (U) Classification at every CGPA boundary
- [ ] (E) Settings changes (matric pattern, unit limits, toggles) take effect and are audited

## 4. Student onboarding and import [ON, ADM, STU]

**Single add**
- [ ] (E) Registrar adds a student; matric number generated; welcome email in outbox with matric and temporary password
- [ ] (E) Invoice for current session is created automatically

**Import: general**
- [ ] (E) CSV upload: preview, mapping, validation, commit, report
- [ ] (E) Excel (.xlsx) upload works identically
- [ ] (E) Google Sheet link (public view) imports; private/invalid link shows a clear error
- [ ] (E) A Google Form response sheet imports through the mapping step with non-standard headers
- [ ] (I) Rows with errors are reported per row with reasons; valid rows still import
- [ ] (I) Missing required columns, unknown programme code, invalid level, invalid email, duplicate email in file, duplicate email in database are each detected
- [ ] (I) Re-running the same import is safe (skip or update, per option); no duplicate accounts
- [ ] (I) Two concurrent imports never generate the same matric number
- [ ] (I) File above size/row limit is rejected with a clear message
- [ ] (U) Google Sheet URL parser accepts only `docs.google.com/spreadsheets/d/...`; rejects other hosts and redirect tricks
- [ ] (E) Failed-rows CSV downloads and re-imports after correction
- [ ] (E) Every import is recorded in import batches and the audit log
- [ ] (U) Phone numbers normalise to +234 format

**Credentials**
- [ ] (E) Student receives email containing matric number, temporary password and login link
- [ ] (E) "Resend credentials" generates a new password and invalidates the old one
- [ ] (M) Real inbox delivery verified on the deployed Brevo configuration

**Applicants**
- [ ] (E) Import/add applicants; change status; admit and convert to student; conversion sends welcome email
- [ ] (E) Bulk admit works and is audited

**Records**
- [ ] (E) Student list search and filters work; pagination works
- [ ] (E) Student record tabs load; edit, suspend, reactivate, level promotion work
- [ ] (E) Document upload accepts PDF/JPG/PNG up to 5 MB; rejects other types, oversize files and dangerous filenames
- [ ] (I) Another student's documents cannot be fetched by URL guessing

## 5. Student portal [PORTAL]

- [ ] (E) Dashboard shows programme, level, registration status, fees due, latest result
- [ ] (E) Profile view and limited self-edit; restricted fields cannot be changed by the student
- [ ] (E) Notifications bell shows unread count; marking read persists
- [ ] (E) Announcements visible only to targeted students
- [ ] (E) Password change from settings works and signs out other sessions where supported

## 6. Course registration [REG]

- [ ] (E) Student sees only eligible courses (programme, level, active semester)
- [ ] (E) Core courses pre-selected; electives selectable
- [ ] (U) Unit total updates live; below minimum and above maximum are blocked with clear messages
- [ ] (U) Prerequisite rule blocks courses until prerequisite passed
- [ ] (E) Submit creates registration; confirmation email and notification appear
- [ ] (E) Department officer approves/rejects; student status updates; rejection reason shown
- [ ] (I) With approval disabled, submission auto-approves
- [ ] (I) Registration is locked after the deadline; admin can reopen for one student
- [ ] (E) Add/drop works inside the window and is blocked outside it
- [ ] (E) Registration slip PDF downloads with correct courses and units
- [ ] (I) Student cannot register another student or a course outside their programme

## 7. Fees and payments [FIN, PAY]

**Invoices**
- [ ] (E) Fee structures generate correct invoices; bulk generation shows a preview and confirmation
- [ ] (E) Scholarship/discount/waiver adjusts balance and records a reason and audit entry
- [ ] (I) Paid amount can never exceed invoice total

**Payment flow (Demo Gateway; repeat with the Remita demo environment or Interswitch sandbox for the chosen provider)**
- [ ] (E) Student opens invoice, pays full amount, completes checkout, sees Successful status and updated balance
- [ ] (E) Partial payment leaves invoice Part-paid; a second payment (new reference) completes it
- [ ] (E) Failed and cancelled payments leave the invoice unpaid and show a clear message
- [ ] (E) A payment that is not completed stays **Pending**, shows "Check status", and is later confirmed by the poller or the re-verify button
- [ ] (I) Pending payments past their expiry become Expired, are no longer polled, and credit nothing
- [ ] (E) Receipt PDF and receipt number are available to student and admin
- [ ] (E) Payment confirmation email and notification are created
- [ ] (M) Remita only: pay an RRR outside the portal flow (or simulate it in the demo environment) and confirm the portal picks it up

**Security and integrity**
- [ ] (I) A forged callback or notification (claims success, provider says otherwise) does not mark the payment paid
- [ ] (I) Provider answer with mismatched amount, currency or reference does not settle the payment
- [ ] (I) Duplicate callbacks, notifications, polls and re-verifications never double-credit
- [ ] (I) Callback, poller and manual re-verify running at the same moment result in exactly one credit
- [ ] (I) Browser return to the callback URL without a verified provider status does not mark paid
- [ ] (I) Notification endpoint accepts only a reference, is rate-limited, and never credits from the request body
- [ ] (I) A student cannot pay another student's invoice or an amount above the outstanding balance
- [ ] (I) Provider request hashes are built correctly (unit tests with the provider's documented examples where available)
- [ ] (E) Admin sees the transaction, its source of each status change, can re-verify, and can download the receipt
- [ ] (E) Reconciliation view flags mismatches between provider status and ledger

## 8. Results and grading [RES]

- [ ] (E) Lecturer enters CA and exam scores for assigned course; scores above maximum are rejected
- [ ] (E) CSV results upload validates and reports row errors
- [ ] (U) Total, grade and points match the grading scheme for every boundary
- [ ] (E) Workflow: Draft → Submitted → Approved → Published; each role can only perform its step
- [ ] (I) Published results are locked; amendment requires permission and is audited
- [ ] (E) Student sees only published results; draft/approved results are invisible
- [ ] (U) Semester GPA, CGPA, total units and classification are correct on a prepared dataset
- [ ] (E) Result statement PDF matches on-screen values
- [ ] (E) Publication triggers notifications and emails
- [ ] (I) With "withhold results for debt" on, a student with outstanding fees cannot view results
- [ ] (I) Changing the grading scheme does not alter already-published results

## 9. Attendance [ATT]

- [ ] (E) Lecturer creates session and marks Present/Absent/Late/Excused
- [ ] (I) Lecturer cannot mark attendance for unassigned courses
- [ ] (E) Student sees attendance per course with percentage
- [ ] (U) Percentage calculation (excused handling) correct; below-threshold warning shown
- [ ] (E) Attendance CSV export works

## 10. Exam timetable [EXAM]

- [ ] (E) Exam officer creates, edits and publishes entries
- [ ] (U) Venue/time clash and level/programme overlap detected
- [ ] (E) Students see only exams for courses they registered
- [ ] (E) Publication notification/email sent; timetable PDF downloads
- [ ] (M) Confirm no page, link or code in the app references the CBT engine

## 11. Staff management [STAFF]

- [ ] (E) Create staff manually and by CSV; temporary password email sent
- [ ] (E) Assign roles with faculty/department scope and verify visibility changes
- [ ] (E) Assign lecturer to course; lecturer sees it
- [ ] (E) Deactivate staff blocks login immediately

## 12. Announcements and email [MAIL]

- [ ] (E) Announcement targeted to a level reaches only that level
- [ ] (E) Scoped authors (faculty/department) can only target their scope
- [ ] (E) Optional email sends and appears in the outbox
- [ ] (I) Provider failure marks email Failed with error; retry succeeds later
- [ ] (E) Outbox page filters, views and retries emails; "send test email" works
- [ ] (U) All templates render with no unresolved placeholders in HTML and text
- [ ] (M) Emails display correctly in a mobile mail client

## 13. Reports, dashboards, administration [RPT, ADMIN]

- [ ] (E) Each of the eight reports loads with filters and totals matching the database
- [ ] (E) CSV export opens correctly in Excel and cannot execute formulas (cells starting with `=`, `+`, `-`, `@` are neutralised)
- [ ] (E) Key reports export to PDF
- [ ] (E) Dashboards show correct role-specific figures
- [ ] (E) Audit log records imports, credential re-issues, payments, adjustments, result actions, setting changes; filters work
- [ ] (E) Backup export downloads a ZIP with expected files
- [ ] (E) Health/status panel shows accurate database, mail and payment status

## 14. Responsive design

Test every primary screen at **375px**, **768px** and **1440px**.

- [ ] No horizontal page scrolling
- [ ] Tables become cards below 768px
- [ ] Student bottom tab bar works at 375px; staff drawer works below 1024px
- [ ] Touch targets at least 44px on mobile
- [ ] Import wizard, course registration and payment flows are completable on a phone
- [ ] PDFs/printouts render correctly on A4

## 15. UX states and accessibility

- [ ] Every list/page has loading, empty and error states
- [ ] Buttons show progress and prevent double submission
- [ ] Destructive actions use confirm dialogs
- [ ] Forms have labels, error association, and keyboard operation
- [ ] Visible focus on all interactive elements; dialogs trap and restore focus; Escape closes
- [ ] Automated axe checks show no critical violations on main screens
- [ ] Colour contrast meets WCAG 2.1 AA; status is never colour-only

## 16. Security checks (see SECURITY.md)

- [ ] No secrets in repository or served assets (database, payment and mail keys absent from the repo and browser code); `APP_DEBUG=false`
- [ ] Security headers present (CSP, X-Content-Type-Options, Referrer-Policy, frame protection)
- [ ] File upload and import limits enforced server-side
- [ ] Error messages do not leak stack traces or internal details
- [ ] `composer audit` and `npm audit` show no high/critical vulnerabilities
- [ ] Webhook endpoints reject unsigned requests; scheduler/queue are not web-triggerable without a secret

## 17. Performance and capacity (release gate for the "up to 1,000 students" claim)

Targets are proposals to agree with the institution (PRD §13.1). Run on a staging copy sized like Tier 1 (ARCHITECTURE.md §12), seeded with 1,000 students, with real-looking invoices, registrations and results.

- [ ] Peak scenario (300 simultaneous active users): login, dashboard, course registration, fees page, payment initiation and status page, results page. 95% of page responses under 2 seconds (excluding provider pages); error rate under 1%
- [ ] Stress scenario at 1.5 times peak (450 users): no crashes, no data corruption, graceful slowdown only; record the breaking point
- [ ] Deadline-day scenario: many students submit course registration and start payments within a few minutes; no duplicate registrations, no double-credited payments, queue and poller keep up
- [ ] Bulk operations at scale: import of 1,000 students completes with visible progress; bulk invoice generation for 1,000 students completes; 1,000 emails drain through the queue within the provider's limits
- [ ] Reports and PDFs with 1,000 students complete within request limits (or run on the queue)
- [ ] Database: slow-query review shows no unindexed filters on main lists; connection count stays under the plan limit during the peak scenario
- [ ] Resource headroom: server CPU and memory stay below the upgrade triggers during the peak scenario
- [ ] Main pages load in under 3 seconds on a throttled mobile connection (Lighthouse or equivalent)
- [ ] Results recorded in MEMORY.md with the test date, tier and numbers; repeat before moving beyond 1,000 students

## 18. End-to-end demo journeys (Pest feature flows plus Playwright smoke, run on every release candidate)

- [ ] Journey 1: import → email → matric login → forced password change → dashboard
- [ ] Journey 2: course registration → approval → slip
- [ ] Journey 3: payment → verification → receipt → email
- [ ] Journey 4: admin verifies student record, transaction and receipt
- [ ] Journey 5: results entered → approved → published → student views and downloads statement
- [ ] Journey 6: attendance and announcement
- [ ] Journey 7: reports, export and audit log

## 19. Deployment smoke test (run on the live URL before each gate and the morning of the demo)

- [ ] Site loads over HTTPS; `/up` and `/health` report healthy; queue worker and scheduler are running
- [ ] Aiven MySQL service is running (not powered off) and the app is awake (not sleeping)
- [ ] Super admin login works
- [ ] Student login with matric number works
- [ ] Send test email from Settings succeeds and arrives
- [ ] Demo payment completes end to end
- [ ] Payment provider callback/notification URLs point at the live domain (or `PAYMENT_PROVIDER=demo` is set) and a test payment settles
- [ ] Seed data present (students, invoices, results, announcements)
- [ ] Fallback switches verified: `PAYMENT_PROVIDER=demo`, `MAIL_MAILER=log`
- [ ] Demo script run start to finish without manual database edits

## Definition of done (per task)

1. Acceptance criteria met and relevant items above ticked.
2. Lint, type-check and tests pass.
3. Loading, empty and error states present; responsive at the three widths.
4. Security rules followed; audit and email hooks present where required.
5. `TASKS.md` and `MEMORY.md` updated.
