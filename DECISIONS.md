# Architecture Decisions

> Permanent technical decisions. If a decision changes, add a **new** ADR that supersedes the old one; do not silently edit or ignore existing ADRs. Current project state belongs in MEMORY.md.

**History note.** An earlier draft of these documents used Next.js + Supabase + Vercel. The owner decided against Supabase (prior experience with breakages and debugging effort) and asked for a lighter stack. ADR-001 to ADR-003 replace that draft; the Next.js/Supabase decisions are not in force.

## ADR-001: Build the portal as a Laravel monolith

**Status:** Accepted.

**Decision:** One Laravel application (PHP 8.4+) with Filament for the staff/admin interface and Blade + Livewire for the student portal.

**Reason:** Laravel includes authentication, validation, migrations, mail, queues, scheduling, policies and testing out of the box, so less code and fewer moving parts than a JavaScript front end plus a hosted backend service. Filament generates most staff-side CRUD screens. The owner has found Supabase costly in debugging time; a conventional framework with a plain database is simpler to reason about and to build with AI assistance.

**Consequences:** PHP hosting is required (Docker). `composer.lock` requires PHP 8.4.1 or newer. Business logic stays in `app/Services`.

## ADR-002: Use managed MySQL 8 (Aiven); no Supabase

**Status:** Accepted.

**Decision:** MySQL 8 as the database, provided by Aiven for MySQL (free plan for the demo, paid plans for production). The app talks to it as a normal database over TLS.

**Reason:** Matches PRD v1.0's original MySQL direction; Aiven's free plan has no time limit; paid Aiven plans offer production-grade managed MySQL; no vendor-specific auth or row-level policies to debug.

**Consequences:** Aiven free plan is for demo only (capped resources, may power off if unused). No partial unique indexes in MySQL, so "single current session" uses a flag-column pattern (ARCHITECTURE §10). App and database must be in the same cloud region.

## ADR-003: Demo on DigitalOcean + Aiven; production path on paid tiers

**Status:** Accepted. Replaces the Vercel/Cloudflare hosting plan.

**Decision:** Demo/test: DigitalOcean App Platform (Docker) with Aiven free MySQL on DigitalOcean in the same region. Fallback host: Render free web service. Production: paid, always-on hosting with a high-availability managed database (recommended: DigitalOcean for app and database, see SYSTEM_OVERVIEW.md).

**Reason:** Vercel and Cloudflare do not run Laravel natively. DigitalOcean was the owner's preferred provider and is available on both the app side and the Aiven free tier.

**Consequences:** DigitalOcean has no Africa region (as last confirmed by DigitalOcean staff in 2022; re-check), so Europe is the nearest. Data-residency and latency implications are covered in SYSTEM_OVERVIEW.md. Free hosts sleep or reset; wake the app before demos.

## ADR-004: The CBT engine is fully separate from the portal

**Status:** Accepted.

**Decision:** No integration of any kind: no links, APIs, SSO, shared database, result import, candidate/attempt entities or environment variables.

**Reason:** Owner requirement; keeps the CBT engine independently deployable.

**Consequences:** Results enter by lecturer entry or CSV upload. Exam timetables are informational only.

## ADR-005: Student accounts are created by the institution; no public self-registration

**Status:** Accepted.

**Decision:** Registrars create students singly or by import; the portal has no public sign-up page. Applications are collected outside the portal and imported.

**Reason:** Owner requirement; every account must map to an admitted student.

## ADR-006: Import sources are CSV, Excel and Google Sheet link (Google Forms via their response sheet)

**Status:** Accepted.

**Decision:** CSV and `.xlsx` uploads and public-view Google Sheet URLs; Google Forms through their linked response sheet.

**Reason:** Covers the sources the owner named without Google OAuth or API keys.

**Consequences:** Server downloads the sheet's CSV export with a host allow-list, size and time limits (SECURITY.md). Private sheets are unsupported in this version.

## ADR-007: Students log in with matric number; resolved server-side

**Status:** Accepted.

**Decision:** Login accepts matric number or email. The server finds the user by matric number or email and authenticates with Laravel's session guard.

**Reason:** Satisfies "matric number + generated password" without a custom auth system.

**Consequences:** Matric numbers are unique; attempts are throttled; failure messages are generic to prevent enumeration.

## ADR-008: Emailed temporary password with forced change and expiry

**Status:** Accepted.

**Decision:** Random 12-character temporary password emailed to the user, valid 7 days, must be changed at first login, re-issuable by admins.

**Reason:** Owner requirement for emailed matric number and generated password.

**Consequences:** The password exists in plaintext only in the single welcome email; the stored outbox copy is redacted after sending. An activation-link flow is a future improvement.

## ADR-009: Money is stored as unsigned integer kobo

**Status:** Accepted.

**Decision:** All amounts are unsigned bigint kobo in the database and integers in code; formatting to Naira happens only in `App\Support\Money`.

**Reason:** Avoids floating-point errors; matches the smallest unit used by Nigerian payment providers.

## ADR-010: Payment gateway abstraction; server-side verification only

**Status:** Accepted. The provider choice in this ADR is superseded by ADR-023.

**Decision:** A `PaymentGateway` interface with swappable providers chosen by `PAYMENT_PROVIDER`. Settlement only after server-side verification, inside a database transaction with a row lock.

**Reason:** Providers differ; a stable interface keeps business logic unchanged and lets a Demo Gateway protect the demo while using the same settlement path.

**Consequences:** Redirects, callbacks and notifications are never trusted alone. Replays are no-ops. Partial payments are supported.

## ADR-011: Email through Laravel Mail with Brevo and an outbox table

**Status:** Accepted (revised for Laravel).

**Decision:** All emails go through `MailService`, which records them in `email_outbox` and sends them through a queued job using Laravel's mailer (Brevo; `log` mailer in development). Password-reset emails use the same path.

**Reason:** Gives branded templates, retry, and a visible record for the demo even if the provider is rate-limited.

**Consequences:** Free-tier daily limits apply to Brevo; bulk sends are batched by the queue. Sender verification (domain in production) is required.

## ADR-012: Thin entry points; business logic in services

**Status:** Accepted.

**Decision:** Controllers, Livewire components and Filament actions validate, authorise, then call a Service. Filament resources may declare forms, tables and simple scoped reads, but never contain business rules.

**Reason:** Keeps rules unit-testable and prevents AI-generated UI code from embedding logic.

## ADR-013: Authorisation by Policies and scoped queries (no database RLS)

**Status:** Accepted. Replaces the RLS decision from the earlier draft.

**Decision:** Role enum + permission map + one Policy per model + `visibleTo()` query scopes. Student-facing code always derives the student from the authenticated user.

**Reason:** MySQL has no row-level security; Laravel's policy system is the standard mechanism and integrates with Filament.

**Consequences:** Isolation depends on application code, so cross-student access tests are mandatory (TEST_PLAN §2) and are a release gate.

## ADR-014: Grading is data-driven and configured by the school

**Status:** Accepted.

**Decision:** Assessment components (name and max score each), grade bands, pass mark, classification bands, repeat-course policy, and academic-standing thresholds are stored in tables and edited by the Super Administrator; `GradingService` performs all calculations. Published results record the scheme version.

**Reason:** Institutions differ in policy; the admin enters rules, the system calculates.

## ADR-015: Full-scope prototype with milestone gating

**Status:** Accepted.

**Decision:** All modules are in scope, built in four milestones; each milestone must be stable and deployed before the next starts.

## ADR-016: Generate PDFs with DomPDF

**Status:** Accepted.

**Decision:** Receipts, registration slips, result statements, timetables and key reports are rendered from Blade views by `barryvdh/laravel-dompdf`.

**Reason:** Pure PHP, no headless browser, works in a small container.

**Consequences:** Keep PDF layouts simple; chunk or queue large reports.

## ADR-017: Test with Pest; Playwright for a few smoke journeys

**Status:** Accepted.

**Decision:** Pest unit/feature/Livewire tests cover rules, policies and journeys; Playwright runs a small set of browser smoke tests against a running instance. GitHub Actions runs Pint, Larastan and Pest.

## ADR-018: Long operations are chunked; background work uses the database queue

**Status:** Accepted (revised).

**Decision:** Imports commit 25 rows per request; emails and other deferred work run on the `database` queue; the scheduler retries failed emails.

**Reason:** Keeps requests short on any host and works without Redis at demo scale.

## ADR-019: Filament for staff, Blade + Livewire for students

**Status:** Accepted.

**Decision:** Staff and admin screens use a Filament panel themed to DESIGN.md. The student portal is custom Blade + Livewire.

**Reason:** Staff screens are CRUD-heavy, where Filament saves the most effort. The student portal is small and benefits from full design control and a mobile-first layout.

**Consequences:** Staff screens follow DESIGN.md through theming but will not match it pixel for pixel.

## ADR-020: One Docker image runs web, queue worker and scheduler

**Status:** Accepted.

**Decision:** nginx + php-fpm + supervisor (queue worker, scheduler) in a single container.

**Reason:** Free and low-cost hosts offer one process per service; this keeps the demo on a single component. Production may split roles later.

## ADR-021: File storage is local for the demo and S3-compatible in production

**Status:** Accepted.

**Decision:** Laravel's filesystem abstraction; local disk in the demo (files may be lost on restart or redeploy), DigitalOcean Spaces (S3-compatible) or equivalent in production.

**Consequences:** Seed data contains no uploaded files. Production needs shared object storage before running more than one app server.

## ADR-022: Production hosting principle: app and database in the same region, high-availability database

**Status:** Accepted.

**Decision:** Production runs the application and the database in the same cloud region, with a high-availability database plan, automated backups and an always-on host. Initial recommendation is DigitalOcean (Europe region); alternatives (AWS Cape Town, Lagos hosting) are evaluated in SYSTEM_OVERVIEW.md and decided with the institution before go-live, taking data-residency advice into account.

## ADR-023: Use Remita and/or Interswitch (Quickteller) instead of Paystack

**Status:** Accepted. Supersedes the Paystack choice in ADR-010.

**Decision:** Payments go through adapters for **Remita** (invoice/RRR flow) and **Interswitch Web Checkout** (reference + requery flow), plus the Demo Gateway. The live provider is chosen with the institution's bursary; the Demo Gateway is built first and one real adapter second.

**Reason:** The owner cannot access Paystack. Both providers are established with Nigerian institutions. Remita suits structured fee collection (RRR, split payments, payment at banks); Interswitch is simple to integrate and has a sandbox.

**Consequences:** Payments can stay Pending for long periods (offline RRR payment), so the system includes a scheduled poller, a re-verify action and expiry handling. Notifications and redirects are triggers to re-query only, because notification formats and signing differ by provider. Live onboarding needs business verification and may take time; the demo uses the Demo Gateway or provider sandboxes. Provider fee schedules must be obtained in writing.

## ADR-024: Capacity target of 1,000 students with a defined expansion point

**Status:** Accepted.

**Decision:** Launch infrastructure is sized for up to 1,000 students (institution has about 150 now). Beyond about 2,000 students an expansion is planned. The claim "supports 1,000 students" is only made after a load test at 1.5 times the proposed peak (300 concurrent users) passes. Sizing tiers and upgrade triggers are in ARCHITECTURE.md §12.

**Reason:** Matches the institution's commitment while keeping cost proportionate. Treating the promise as a measured result protects the owner from an untestable guarantee.

**Consequences:** Tier 1 uses a single application server with a managed database. Monitoring thresholds must be set up from launch. Moving to Tier 2 or 3 is an infrastructure change, not a rewrite.

## ADR-025: Application timezone is Africa/Lagos

**Status:** Accepted.

**Decision:** `config('app.timezone')` is `Africa/Lagos` (`APP_TIMEZONE` in `.env.example`). `App\Support\Dates` always displays that zone. A value that already carries a timezone, including UTC, is converted. A naive string is interpreted in an explicit source timezone, or in the application timezone when none is given, and is not shifted a second time.

**Reason:** The portal serves a Nigerian university. West Africa Time has no daylight saving, so one zone keeps stored clock times and displayed clock times the same. UTC instants still display as Lagos local time.

**Consequences:** Datetimes written by Laravel are Lagos wall time. Callers that hold a UTC clock time must pass a UTC `DateTimeInterface` or set the source timezone. Do not change `APP_TIMEZONE` to UTC without a new ADR.

## ADR-026: Database conventions

**Status:** Accepted.

**Decision:** Phase 3 schema follows one set of conventions.

- **Primary keys.** Every new table uses an unsigned bigint auto-increment primary key. `users.id` is already that type. ULIDs are not used.
- **Datetimes and timezone.** Every new instant is a `DATETIME`, including `created_at` and `updated_at`. Laravel writes Africa/Lagos wall time (ADR-025). `DATETIME` stores that clock time as written. The local MySQL session zone is UTC, and a `TIMESTAMP` converts by the session zone, which would shift a Lagos wall time by one hour. Calendar values, such as a date of birth, use `DATE`. Frozen framework columns stay `TIMESTAMP`: `users.email_verified_at`, `password_reset_tokens.created_at`, and `failed_jobs.failed_at`.
- **Delete rules.** Every foreign key is `ON DELETE RESTRICT` and is named in the migration. `CASCADE` and `SET NULL` are not used. An exception needs a later ADR.
- **Soft deletes.** There is no `deleted_at` column and no soft-delete trait. A row's lifecycle is a status column.
- **Enum storage.** Statuses and roles are `varchar(32)` strings, cast to the backed enums in `app/Enums`. The stored text is the label from DESIGN.md (`Part-paid`, `Under review`, and the rest). MySQL `ENUM` columns are not used.
- **Naming.** Migrations set the name of every unique index, secondary index, foreign key, and `CHECK`. The forms are `{table}_{columns}_unique`, `{table}_{columns}_index`, `{table}_{column}_foreign`, and `{table}_{rule}_check`, within MySQL's 64-character limit.
- **Payment events.** `payment_events` has one unique index, `payment_events_provider_event_key_unique`, on `(provider, event_key)`. `event_key` is the provider idempotency key. The name `event_id` in the ARCHITECTURE.md unique-list bullet is not used.

**Reason:** Section 10 left the primary-key type open, and it described the one payment-event unique key under two names. The frozen `users` table already uses unsigned bigint. Local MySQL 8.0.46 enforces `CHECK` and stores `TIMESTAMP` in UTC, so new clock columns have to be `DATETIME` if Lagos wall time is to survive a round trip.

**Consequences:** Later migrations follow this ADR where ARCHITECTURE.md disagrees, including `payment_events(provider, event_key)`. One-current rows still use the nullable flag column from ADR-002. Changing a delete rule or introducing soft deletes needs a new ADR.

## ADR-027: Guardian rows cascade when a student is deleted

**Status:** Accepted.

**Decision:** `guardians.student_id` is `ON DELETE CASCADE`. Every other foreign key stays `ON DELETE RESTRICT`, including registration items and every people-table key other than that one guardian key (ADR-026).

**Reason:** A guardian row exists only as a child of one student. It holds no money, results, or registrations. Phase 3 approved this single exception.

**Consequences:** Deleting a student deletes that student's guardians. Documents have no foreign key, so a service must refuse that delete or remove the documents first. No other table may use cascade without a new ADR.
