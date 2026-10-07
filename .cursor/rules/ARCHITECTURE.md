# Architecture

> Architecture = **HOW** the system is built. Product behaviour is in PRD.md; permanent technical choices and reasons are in DECISIONS.md; costs and hosting options are in SYSTEM_OVERVIEW.md.

## 1. Stack

| Concern | Choice |
|---|---|
| Language / framework | PHP 8.3+ and **Laravel** (current stable at project start; pin exact versions in `composer.json`) |
| Staff / admin interface | **Filament** admin panel (tables, forms, filters, actions, role-aware navigation) |
| Student portal and public pages | Blade + **Livewire** + Alpine.js |
| Styling | Tailwind CSS (Vite build), Inter font self-hosted, tokens from DESIGN.md |
| Database | **MySQL 8** (managed: Aiven for MySQL). InnoDB, utf8mb4 |
| Authentication | Laravel session authentication (hashed passwords), login by matric number or email |
| Authorisation | Role enum + permission map + Laravel **Policies** + scoped queries (no database row-level security) |
| Validation | Laravel Form Requests / Livewire validation rules |
| Queue and scheduler | Laravel queue (`database` driver) + scheduler, run by supervisor in the same container |
| Email | Laravel Mail; Brevo (SMTP relay or API transport) in demo/production, `log` mailer in development; every email recorded in `email_outbox` |
| Payments | Provider-neutral `PaymentGateway` interface with adapters for **Remita**, **Interswitch (Quickteller Web Checkout)** and a built-in **Demo Gateway**, using Laravel's HTTP client. Paystack is not used |
| File storage | Laravel filesystem: local disk (demo), S3-compatible object storage such as DigitalOcean Spaces (production) |
| PDF | `barryvdh/laravel-dompdf` |
| CSV / Excel import-export | `maatwebsite/excel` (CSV and XLSX) |
| Charts | Filament chart widgets (staff); Chart.js via Livewire (student, if needed) |
| Testing | Pest (unit + feature + Livewire tests); Playwright for a few browser smoke journeys |
| CI | GitHub Actions: Pint (style), static analysis (Larastan), Pest |
| Packaging | Docker image: nginx + php-fpm + supervisor (web, queue worker, scheduler) |
| Hosting | Demo: DigitalOcean App Platform + Aiven free MySQL on DigitalOcean (fallback: Render free). Production: see SYSTEM_OVERVIEW.md |

## 2. High-level flow

```
Browser (Staff or Student)
        ↓
Blade/Livewire page  or  Filament resource/page
        ↓
Controller / Livewire component / Filament action   ← validates input, authorises via Policy
        ↓
Service (app/Services)                               ← business rules, DB transactions, audit, email
        ↓
Eloquent models
        ↓
MySQL

Side channels (always called from services, never from views):
Service → MailService → email_outbox row → queue job → Brevo → status updated
Service → PaymentGateway interface → Remita / Interswitch API / Demo Gateway
Provider callback or notification, scheduler poll, admin re-verify → PaymentService::verifyAndSettle (re-query + settle, idempotent)
```

## 3. Layers and responsibilities

| Layer | Location | Allowed | Not allowed |
|---|---|---|---|
| Views / UI | `resources/views`, `app/Livewire`, `app/Filament` | Rendering, local UI state, simple read queries for display via scoped model methods, calling services | Business rules, multi-step writes, calling external APIs, secrets |
| Entry points | Controllers, Form Requests, Livewire actions, Filament actions | Validate, authorise, call a service, shape the response | Business logic, direct vendor calls |
| Services | `app/Services/*Service.php` | Business logic, DB transactions, audit logging, email enqueueing, provider calls through interfaces | Reading the HTTP request, returning views |
| Support | `app/Support` | Money/date helpers, provider adapters, RBAC helpers, import parsers | Business rules |
| Database | `database/migrations` | Schema, constraints, indexes | Application logic |

## 4. Folder structure

```
university-portal/
├── PRD.md  ARCHITECTURE.md  DESIGN.md  RULES.md  TASKS.md  DECISIONS.md
├── MEMORY.md  TEST_PLAN.md  SECURITY.md  SYSTEM_OVERVIEW.md
├── .cursor/rules/                 # general / frontend / backend / testing
├── .github/workflows/ci.yml
├── docker/                        # Dockerfile, nginx.conf, supervisord.conf, entrypoint.sh
├── app/
│   ├── Enums/                     # Role, InvoiceStatus, PaymentStatus, RegistrationStatus, ResultStatus ...
│   ├── Models/
│   ├── Http/
│   │   ├── Controllers/           # Student/, Staff/ (non-Filament), Pdf/, Webhooks/, Auth/
│   │   ├── Middleware/            # EnsurePasswordChanged, EnsureActive, SecurityHeaders, RequireRole
│   │   └── Requests/              # Form Requests (validation + authorize)
│   ├── Livewire/
│   │   ├── Student/               # Dashboard, CourseRegistration, Fees, Results, Attendance ...
│   │   └── Staff/Import/          # Import wizard components
│   ├── Filament/
│   │   └── Staff/                 # Panel: Resources/, Pages/, Widgets/
│   ├── Policies/                  # One per model
│   ├── Services/
│   │   ├── AuthService  StudentService  CredentialService  ImportService
│   │   ├── ApplicantService  AcademicService  CourseService  RegistrationService
│   │   ├── InvoiceService  PaymentService  ReceiptService
│   │   ├── GradingService  ResultService  AttendanceService  ExamService
│   │   ├── StaffService  AnnouncementService  NotificationService
│   │   ├── MailService  ReportService  AuditService  SettingsService  DocumentService
│   ├── Jobs/                      # SendOutboxEmail, ...
│   ├── Console/Commands/          # create-super-admin, outbox:send, seed helpers
│   ├── Support/
│   │   ├── Money.php  Dates.php  CsvSafe.php
│   │   ├── Rbac/                  # Permissions map, Scopes
│   │   ├── Payments/              # Contracts/PaymentGateway, RemitaGateway, InterswitchGateway, DemoGateway
│   │   └── Import/                # CsvParser, XlsxParser, GoogleSheetSource, RowValidator
│   └── Providers/
├── config/                        # portal.php (matric pattern defaults, limits), payments.php
├── database/{migrations,seeders,factories}/
├── public/templates/              # students-import-template.csv / .xlsx
├── resources/
│   ├── css/  js/
│   └── views/{layouts,components,student,staff,emails,pdf}/
├── routes/{web.php, student.php, staff.php, webhooks.php}
└── tests/{Unit,Feature,Browser}/
```

## 5. Architectural rules

1. Views, Livewire components and Filament resources must not contain business logic. They may declare forms, tables and simple scoped read queries; anything that creates, changes, approves, publishes, pays or imports goes through a **Service**.
2. Multi-step writes and anything touching money, matric numbers, results or credentials run inside `DB::transaction()` in a service.
3. Controllers, Livewire actions and Filament actions are thin: validate → authorise (Policy/Gate) → call service → return.
4. **Authorisation is checked server-side on every action and route.** Hiding a button is never a security control.
5. **Student data isolation:** student-facing code obtains the student from `auth()->user()->student` and never from request input. Staff queries use the shared `visibleTo($user)` scopes so faculty/department/course scoping is applied in one place.
6. Validate all input with Form Requests/Livewire rules; reuse rule sets between single add and import validators.
7. Reusable UI belongs in `resources/views/components` (Blade components) or shared Filament helpers.
8. Money is an unsigned integer in **kobo** everywhere except display. Use `App\Support\Money`.
9. External providers (payments, email) are used through their interface or Laravel's mailer; services never call vendor URLs directly outside the adapter classes.
10. Every state-changing service method writes to the audit log and, where the PRD requires it, enqueues the email, inside the same service so no code path can skip them.
11. Schema changes happen only through new migrations; never edit a migration that has been applied to the demo or production database.
12. Long operations are chunked (imports 25 rows per request; bulk emails sent by the queue in batches).
13. No code, config, environment variable or dependency related to the CBT engine exists in this repository.

## 6. Authentication design

**Identity.** One `users` row per person (email unique, `password` hashed by Laravel). Students have a `students` row; staff have a `staff` row. `users.status` (active/suspended/deactivated), `must_change_password`, `temp_password_expires_at`, `last_login_at`.

**Matric-number login.**
```
Login form (identifier + password)
  → LoginRequest: rate limit by IP + identifier (RateLimiter)
  → if identifier contains "@": find user by email
    else: find student by matric_no → its user
  → Auth::attempt only if user exists and status = active; generic "Invalid credentials" on any failure
  → regenerate session; set last_login_at
  → middleware EnsurePasswordChanged: if must_change_password (or temp password expired → login blocked)
        redirect to /change-password
  → redirect by role: student → /student, staff → /staff (Filament panel)
```

**Credentials issued by admins** (`CredentialService`): generate a 12-character random password with a cryptographically secure generator, store only the hash, set `must_change_password = true` and `temp_password_expires_at = now() + 7 days`, enqueue the welcome email containing the plaintext once, and redact the password from the stored outbox body after sending.

**Password reset.** Laravel's password broker, with the notification overridden to go through `MailService` so reset emails use the branded template and appear in the outbox. Tokens are single-use and expire in 60 minutes; the response never reveals whether an account exists.

**Sessions.** `database` session driver (works across multiple app servers). Session cookie: HttpOnly, Secure, SameSite=Lax. Changing the password invalidates other sessions.

## 7. Authorisation design

- `Role` enum: SuperAdmin, Registrar, Bursar, FacultyAdmin, DepartmentOfficer, Lecturer, ExamOfficer, Student.
- `role_assignments(user_id, role, faculty_id, department_id)`; a Faculty Admin has `faculty_id`, a Department Officer `department_id`. Lecturers are scoped by `course_assignments`.
- `App\Support\Rbac\Permissions` maps role → permission keys (matches the PRD §5 matrix). A `Gate::before` hook grants SuperAdmin everything except explicit denials.
- One **Policy per model** (Student, Invoice, Payment, CourseRegistration, Result, AttendanceSession, ...). Filament resources pick these up automatically; Livewire and controllers call `$this->authorize()`.
- Scoped queries: each scoped model has `scopeVisibleTo(Builder $q, User $user)`; Filament resources call it in `getEloquentQuery()`.
- Because there is no database-level row security, **tests are mandatory** for isolation: a student can never read another student's invoices, payments, results, registrations, documents, receipts or PDFs (TEST_PLAN §2).
- Any route taking an id (PDF, receipt, document download) must authorise the loaded model before returning data.

## 8. Key flows

### 8.1 Student import (chunked)
```
Upload CSV/XLSX or paste Google Sheet URL
  → parse server-side → preview + detected columns
  → user maps columns → RowValidator checks every row → per-row result table
  → create import_batches (status: processing)
  → browser loops: commitChunk(batch, rows 0–24), (25–49) ...
       per row, in a transaction: next matric (counters row, lockForUpdate)
         → create user + student + guardian → create invoice for current session
         → CredentialService → enqueue welcome email
  → finalise batch: counts + failed-row report
  → queue worker sends the emails in the background, respecting the provider's daily limit
```
Google Sheet source: accept only `https://docs.google.com/spreadsheets/d/<id>/...`; the server builds `https://docs.google.com/spreadsheets/d/<id>/export?format=csv&gid=<gid>`; timeout, size cap, no redirects to other hosts (SSRF guard).

### 8.2 Payment

**Gateway interface** (`App\Support\Payments\Contracts\PaymentGateway`):
```
initialize(Payment $payment, Student $payer): CheckoutInstruction
    // returns one of: redirect URL, auto-submitting form fields (POST), or inline-widget config,
    // plus the provider reference (e.g. Remita RRR) and an optional expiry
verify(Payment $payment): VerificationResult
    // status (successful | pending | failed | cancelled | expired), amount_kobo, currency, provider_reference, raw payload
parseNotification(Request $request): ?string
    // returns the internal reference if the request is a valid notification, or null; optional per provider
name(): string
```
Providers differ in how the customer pays, but **all of them settle through the same `verifyAndSettle()` path**.

| Provider | initialize() | verify() |
|---|---|---|
| Remita | Calls Remita's invoice endpoint (with the merchant id, service type id, order id, amount, payer details and request hash) to obtain an **RRR**; the checkout shows Remita's inline/redirect payment for that RRR, and the RRR can also be paid at a bank or by USSD | Status check by RRR (or order id) using the hash Remita requires |
| Interswitch | Builds the Web Checkout redirect form (merchant code, pay item id, unique `txn_ref`, amount in minor units, redirect URL) or inline config | Transaction requery by merchant code, transaction reference and amount (plus the hash header where the account requires it) |
| Demo | Local checkout page with Success/Fail/Cancel | Reads the simulated state, validated by a signed event |

Exact request fields, hashes and endpoints are taken from each provider's current documentation when the adapter is built; adapters keep them in one class and in configuration.

```
Student clicks Pay (invoice, amount)
  → PaymentService::initiate(): authorise own invoice; amount ≤ outstanding;
      create payments row (pending, unique reference, expires_at);
      gateway->initialize() → store provider reference (RRR / txn_ref) → show checkout

Outcome is learned in four ways, all calling the same method:
  1. Customer returns to /student/payments/{reference} (callback/redirect)
  2. Provider notification endpoint, if the provider offers one and it is enabled
        POST /payments/notify/{provider}  (outside CSRF) → parseNotification() → reference only
  3. Scheduler job payments:poll-pending (every few minutes, back-off for older payments, stop after expiry)
  4. Finance staff "Re-verify" button

PaymentService::verifyAndSettle(reference):
   gateway->verify(payment)                       ← the provider's answer is the only source of truth
   compare status, amount_kobo, currency, provider reference with the stored payment
   DB::transaction: lockForUpdate the payment row;
        if already settled → no-op
        if successful → mark successful, increase invoice.paid_kobo, update invoice status, create receipt
        if failed/cancelled/expired → record status; invoice unchanged
        if still pending → update last_checked_at only
   after commit: enqueue payment email, create notification, write audit
```
Because a notification or redirect is only a **trigger to re-query**, an attacker who forges a callback cannot mark anything paid. A payment can legitimately remain Pending for a long time (for example an RRR paid at a bank later), and the student page shows that state plainly with a "Check status" button.

Webhook and notification routes live in `routes/webhooks.php`, outside the CSRF group, are rate-limited, and never credit from the request body.

### 8.3 Results
```
Lecturer enters/uploads component scores → results rows (draft)
  → submit → department officer approves → exam officer publishes
  → GradingService computes total, grade, points from the active scheme version
  → publish locks rows, stores scheme version, enqueues notifications and emails
  → GPA/CGPA computed from published results (unit-tested edge cases)
```

### 8.4 Email
```
Service → MailService::send(template, to, data)
  → render HTML/text → insert email_outbox (queued) → dispatch SendOutboxEmail job
  → job sends via Laravel Mail (Brevo) → status sent/failed, attempts, error
  → scheduler runs `outbox:send` every minute for queued/failed (with retry limits); admin "retry" button as backup
```

## 9. Environments and deployment

| Environment | App | Database | Purpose |
|---|---|---|---|
| Local | `php artisan serve` or Herd/Docker | Local MySQL or SQLite for quick tests | Development |
| Demo / test | DigitalOcean App Platform (Docker image) | Aiven free MySQL on DigitalOcean, same region | Wednesday demo and user testing. Fallback host: Render free web service |
| Production | See SYSTEM_OVERVIEW.md (recommended: DigitalOcean, app and DB in the same region, HA database) | Aiven for MySQL (Business plan) or DigitalOcean Managed MySQL | Real use |

**Docker image.** One image runs nginx + php-fpm and, through supervisor, `php artisan queue:work` and `php artisan schedule:work`. The entrypoint caches config/routes/views and runs `php artisan migrate --force` only when `RUN_MIGRATIONS=true`.

**Aiven connection.** Aiven requires TLS: download the CA certificate and reference it with `MYSQL_ATTR_SSL_CA` (via `DB_SSL_CA` in `config/database.php`). Keep the app and database in the same cloud region to avoid per-query network latency.

**Demo deployment steps (summary; tasks in TASKS.md).**
1. Push to GitHub; create an App Platform app from the repo using the Dockerfile.
2. Create the Aiven free MySQL service on DigitalOcean in a region close to the app; allow the app's connection.
3. Set environment variables (below) in the host's dashboard.
4. Run migrations, `create-super-admin` and the seeder (one-off command or `RUN_MIGRATIONS=true`).
5. Set the payment provider's callback (and notification, if offered) URLs to the deployed domain, or keep `PAYMENT_PROVIDER=demo` for the demo.
6. Verify the Brevo sender; send a test email from Settings.
7. Run the smoke test in TEST_PLAN.md.

### Environment variables
```
APP_NAME= APP_ENV= APP_KEY= APP_URL= APP_DEBUG=false
DB_CONNECTION=mysql DB_HOST= DB_PORT= DB_DATABASE= DB_USERNAME= DB_PASSWORD= DB_SSL_CA=
SESSION_DRIVER=database  CACHE_STORE=database  QUEUE_CONNECTION=database
MAIL_MAILER=brevo|log  MAIL_FROM_ADDRESS=  MAIL_FROM_NAME=
BREVO_API_KEY=            # or SMTP host/user/password if using the SMTP relay
PAYMENT_PROVIDER=demo|remita|interswitch
REMITA_ENV=demo|live  REMITA_MERCHANT_ID=  REMITA_SERVICE_TYPE_ID=  REMITA_API_KEY=
INTERSWITCH_MODE=TEST|LIVE  INTERSWITCH_MERCHANT_CODE=  INTERSWITCH_PAY_ITEM_ID=  INTERSWITCH_CLIENT_ID=  INTERSWITCH_SECRET=
FILESYSTEM_DISK=local|s3  (+ S3/Spaces keys in production)
DEMO_SEED_PASSWORD=       # used only by the seeder
```
`.env` is never committed. `config/portal.php` validates required values on boot in non-local environments.

## 10. Database overview

Primary keys are unsigned big integers or ULIDs (choose one at project start and use it everywhere); `created_at`/`updated_at` on every table; money columns `*_kobo` unsigned bigint; enums stored as strings with application enum casting.

| Group | Tables (key columns) |
|---|---|
| Identity | `users` (email, password, name, phone, status, must_change_password, temp_password_expires_at, last_login_at) · `role_assignments` (user_id, role, faculty_id, department_id) · `staff` (user_id, staff_no, title, department_id, status) |
| Structure | `institution_settings` (singleton: name, code, logo_path, matric_pattern, min_units, max_units, approval_required, withhold_results_for_debt, attendance_threshold) · `faculties` · `departments` · `programmes` (code, department_id, degree, duration_years) · `academic_sessions` (name, is_current) · `semesters` (session_id, name, is_active, registration_deadline, add_drop_deadline) |
| Courses | `courses` · `programme_courses` (programme_id, course_id, level, semester_no, type) · `course_prerequisites` · `course_assignments` (staff_id, course_id, semester_id) |
| People | `applicants` · `students` (user_id, matric_no, programme_id, level, entry_session_id, status, …) · `guardians` · `documents` (owner_type, owner_id, kind, path, mime, size) · `import_batches` |
| Registration | `course_registrations` (student_id, semester_id, status, total_units, …) · `course_registration_items` |
| Finance | `fee_categories` · `fee_structures` · `invoices` (number, student_id, session_id, total_kobo, adjustments_kobo, paid_kobo, status) · `invoice_items` · `invoice_adjustments` · `payments` (reference, gateway, provider_reference (RRR or txn_ref), invoice_id, student_id, amount_kobo, status, expires_at, last_checked_at, paid_at) · `payment_events` (provider, event_key unique, payload, source: callback/notification/poll/manual) · `receipts` |
| Grading | `grading_schemes` (name, pass_mark, version, is_active, repeat_policy) · `assessment_components` (scheme_id, name, max_score, sort) · `grade_bands` (scheme_id, min, max, letter, points, remark) · `classification_bands` |
| Results | `results` (student_id, course_id, semester_id, total, grade, points, status, scheme_version, entered_by, approved_by, published_at) · `result_scores` (result_id, component_id, score) |
| Attendance / Exams | `attendance_sessions` · `attendance_records` · `exam_timetable` |
| Comms | `announcements` · `notifications` · `email_outbox` |
| System | `counters` (key, value) · `audit_logs` (actor_id, action, entity, entity_id, before, after, ip) · Laravel `jobs`, `sessions`, `cache`, `password_reset_tokens` |

MySQL specifics:
- No partial unique indexes: "exactly one current session / active semester" is enforced with a nullable unique flag column (`current_flag` = 1 or NULL) and set inside a transaction.
- `CHECK` constraints (MySQL 8.0.16+) on money columns: non-negative, `paid_kobo <= total_kobo - adjustments_kobo`.
- Unique: `students.matric_no`, `users.email`, `payments.reference`, `payment_events(provider, event_id)`.
- Atomic matric numbers: `counters` row per department+year read with `lockForUpdate()` inside the creating transaction.
- Index foreign keys and frequent filters (matric_no, email, status, session/semester, invoice status).

## 11. Errors and observability

- Services throw typed exceptions (`ValidationException`, `AuthorizationException`, domain exceptions); controllers/Livewire convert them to friendly messages.
- Unexpected errors are logged with context (never secrets or passwords) and shown as a friendly page; `APP_DEBUG=false` outside local.
- `/up` (framework health) and `/health` (database reachability, queue heartbeat, mail/payment config status) for uptime checks.
- Audit log is separate from application logs and is visible only to Super Admins.

## 12. Capacity, performance and scaling

**Targets (PRD §13.1):** about 150 students today; up to **1,000 students** supported on the launch infrastructure; beyond about **2,000 students** plan an expansion. Proposed peak assumption: 300 users active at once, 95% of pages under 2 seconds excluding provider pages. These are proven by a load test, not assumed.

### Sizing tiers

| Tier | Students | Application | Database | Supporting services |
|---|---|---|---|---|
| 1: Launch | 150 → 1,000 | One 2 vCPU / 4 GiB server running web, queue worker and scheduler in the same container | Managed MySQL, 2 GiB class (high availability recommended) | Object storage, Brevo paid plan, daily backups, uptime monitoring |
| 2: Growth | 1,000 → 2,000 | Same design, larger server (4 vCPU / 8 GiB) or a second server behind a load balancer | Managed MySQL, 4 GiB class, HA | Same |
| 3: Expansion | 2,000+ | 2+ app servers behind a load balancer; separate queue worker service | Larger HA MySQL plan; read replica if reports are heavy | Managed Redis/Valkey for cache, sessions and queue; CDN for static files |

The application is stateless, so moving between tiers changes infrastructure, not code, provided these are already true at Tier 1: sessions, cache and queue are configurable by environment; files are on S3-compatible storage; the scheduler and queue worker can be moved to their own service; and database queries are indexed and paginated.

### Upgrade triggers (watch from day one)
- App server CPU above 70% for 10+ minutes at peak, or memory above 80%
- p95 page response above 2 seconds during peak
- Database CPU above 70%, connections near the plan limit, or storage above 80%
- Email/payment-poll queue backlog older than 10 minutes
- Student count passing 1,000 (review) and 2,000 (expand)

### Rules that keep it fast
- Paginate all lists (default 25) with server-side filtering and sorting; eager-load relations; enable `Model::preventLazyLoading()` outside production.
- Index foreign keys and common filters (matric_no, email, status, session/semester, invoice status, payment status + created_at for the poller).
- Cache institution settings and the active grading scheme; invalidate on change.
- Heavy work (PDFs, bulk emails, reports, polling) runs on the queue or scheduler and in chunks.
- Stagger deadlines by level or faculty when possible; deadline-day peaks are the main risk.
- Load-test the registration and payment paths at 1.5 times the peak assumption before launch, and again before moving past 1,000 students.
