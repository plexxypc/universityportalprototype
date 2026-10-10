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

## ADR-028: Authentication and authorisation

**Status:** Accepted.

**Decision:** Phase 4 sign-in and access control follow the rules below. ADR-007, ADR-008, and ADR-013 stay in force except where this ADR is more specific.

1. **One login page.** The portal login is a plain Blade form and a controller. It is not a Livewire component. `AuthService` is the only authenticator. The Filament staff panel does not call `->login()`. `/staff/login` redirects to the portal login. A small subclass of Filament's `Authenticate` middleware sends an unauthenticated panel request there. `User::canAccessPanel()` allows the staff panel only for an Active user who has a staff role. Filament registration, password reset, email verification, profile editing, and multi-factor authentication stay off.
2. **Student comes from `students`.** A user is a student when a `students` row exists. Student screens load that row from the authenticated user. `role_assignments` holds staff roles only. No code path inserts a `Student` role, and the role-scope validator rejects one. The database check that still lists `Student` stays as it is; this ADR does not authorise a migration to remove it. A person with any staff role and a `students` row is sent to `/staff` after login and may still open `/student`. A student-only user is sent to `/student` and cannot open the panel.
3. **Role scope.** Super Admin, Registrar, Bursar, Exam Officer, and Lecturer have no faculty and no department. Faculty Admin requires a faculty and no department. Department Officer requires a department and no faculty; the faculty is derived from that department. The later "department belongs to faculty" check is not needed.
4. **Login limits.** Failures only. Identifier plus client IP: 5 failures per minute. Identifier alone: 10 failures per 15 minutes, then that identifier is refused for the rest of the window. The limiter stores an HMAC of the normalised identifier, keyed with the app key, never the plaintext email or matric number. While the identifier is locked, every attempt is refused, including a correct password, with the same generic failure as a wrong password. Both counters clear after a successful login. The identifier lockout lets someone who knows an email or matric number freeze that account for 15 minutes. The IP key is weak while trusted proxies are `at: '*'`, because `X-Forwarded-For` can be forged, so the identifier key is the control.
5. **Sessions.** The session cookie stays as configured: Secure outside `local`, HttpOnly, SameSite `lax`. There is no remember-me checkbox. After a password change, one mechanism for the portal and the staff panel deletes that user's other `sessions` rows, rotates `remember_token`, and refreshes the current session's stored password hash so the current browser stays signed in. Tests cover the row delete, the token rotation, and the hash refresh on each surface.
6. **Livewire.** The staff panel registers `EnsureActive`, `EnsurePasswordChanged`, and the role checks with Filament's persistent flag. Student routes register those same classes with `Livewire::addPersistentMiddleware()`. The change-password page still accepts a submission while the forced-change flag is set, and a test proves that.
7. **Passwords.** A new password is 10 to 72 bytes, contains at least one letter and one digit, and does not contain the email or the matric number (case-insensitive). It is checked against a local common-password list. The confirmation must match, and the current password is required on both the forced and the voluntary form. There is no "Forgot password" link until Phase 5. No breach-check call. The temporary password from ADR-008 is still 7 days for issued credentials; only the bootstrap Super Admin below uses 24 hours.
8. **Permission keys.** Manage implies view. Scope ("own", "scoped", "finance", "published only") stays in policies and `visibleTo()`, not in extra keys. The keys are `users_roles_settings.manage`, `grading_configuration.view`, `grading_configuration.manage`, `academic_structure.view`, `academic_structure.manage`, `student_records.view`, `student_records.manage`, `course_registration.submit`, `course_registration.approve`, `fees.view`, `fees.manage`, `payments.make`, `results.enter`, `results.approve`, `results.publish`, `results.view`, `attendance.view`, `attendance.manage`, `exam_timetable.view`, `exam_timetable.manage`, `announcements.view`, `announcements.manage`, `reports.view`, `reports.manage`, and `audit_logs.view`. `Gate::before` grants Super Admin every ability except `course_registration.submit` and `payments.make`, which it denies. Those two denials apply to anyone who has the Super Admin role, including someone who also has a `students` row.
9. **First Super Admin.** A local Artisan command, separate from `create-super-admin`, asks for a password with a hidden prompt and prints the bcrypt hash. `create-super-admin` never prints a password or a hash. `docker/entrypoint.sh` runs it only when `BOOTSTRAP_SUPER_ADMIN_EMAIL` and `BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH` are both set, `APP_ENV` is `production`, and no Super Admin exists. The stored password is that hash. The user is Active, `must_change_password` is true, and `temp_password_expires_at` is 24 hours after creation. The owner deletes both variables after the first login. When a shell exists, `create-super-admin` can also run interactively with a hidden password prompt.
10. **Logout.** The portal login is the only authenticator. The panel logout calls the same `AuthService` logout as the portal. A test signs in, opens `/staff`, and logs out from there.

**Reason:** Filament's default login authenticates by email on its own, and trusted proxies make an IP-only limit forgeable. One service, generic failures, and an identifier limit keep those holes closed. Deriving the student from `students` means a student account cannot exist without a portal identity, and staff roles stay in `role_assignments`.

**Consequences:** Phase 4 implements this ADR. Password reset, email sending, and audit logging stay in Phase 5. Suspending or reactivating a student must update `users.status`, because login checks the user row. The entrypoint is the only approved change outside the auth work, and only for the guarded bootstrap.

## ADR-029: Audit, email and notifications

**Status:** Accepted. Supersedes the vendor sentence in ADR-011. The outbox and the Laravel mailer path in ADR-011 stay. ADR-008 stays; this ADR says where the secret is held until send.

**Decision:** Phase 5 audit, email, password reset, and in-app notifications follow the rules below. The provider is to be confirmed. Nothing in this ADR is built except the decision itself.

1. **Mail transport.** `App\Support\Mail\MailTransport` is the HTTPS adapter interface. `MailService` sends through Laravel's mailer. `MAIL_MAILER` selects the driver: `log` and `array` use Laravel's own transports; `brevo` uses `App\Support\Mail\BrevoTransport`, the only real adapter written in this phase. Any other value fails closed before a request is made. A later provider is a second class on the same interface. `BrevoTransport` is the first adapter, not a final vendor choice. The adapter reads `MAIL_API_URL`, `MAIL_API_KEY`, and `MAIL_API_TIMEOUT` from the environment. The scheduler reads `MAIL_DAILY_LIMIT` from the environment. Sender address and name stay `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. PHP hard-codes no vendor quota, host, or sender. The adapter adds the provider path and JSON shape; the host comes from `MAIL_API_URL`. The unused `BREVO_API_KEY` name is removed from `.env.example`, `render.yaml`, `docs/DEPLOYMENT.md`, and `ARCHITECTURE.md` when mail configuration is updated. The adapter reads `MAIL_API_KEY`.

2. **Secrets.** Outbox bodies are placeholders from insert. A new migration adds a nullable encrypted `secrets` column on `email_outbox`. That migration is approved. The message is rendered in memory at send time. `secrets` is cleared after a successful send, on a permanent failure of that row, when attempts are exhausted, and when a re-issue supersedes the row. An `APP_KEY` rotation makes an unsent secret unreadable; that row fails closed and is re-issued. The existing check that allows `redacted_at` only when the status is `Sent` stays as it is. A `Sent` row also sets `redacted_at`. A `Failed` or superseded row clears the secret and leaves `redacted_at` null.

3. **Queue payload.** `SendOutboxEmail` carries only the outbox id. Provider and rendering failures are caught, stored as a fixed error code, and are not rethrown. An unexpected failure is stored as the fixed string `outbox_send_failed`. `last_error` is one of the fixed codes, cut to 180 characters, with no address, URL, key, or secret.

4. **Duplicate sends.** The job and `outbox:send` take a per-row lock on the database cache store, `Cache::lock('outbox-send:{id}')`, and hold it across the provider call and the status update. The command only dispatches. The job is the only sender. Delivery is at least once: if the provider accepts the message and the `Sent` update then fails, a later run can send a second copy. The duplicate-send test uses the database cache store.

5. **Retry.** The outbox allows 5 attempts. Backoff from `updated_at` is 1 minute, 5 minutes, 15 minutes, then 1 hour. The job returns normally, so the worker's `--tries=3` does not multiply those attempts. HTTP 400 and 422 fail that row at once, clear its secret, and use `invalid_recipient` or `provider_rejected`. HTTP 401, 403, and a provider quota response leave the row `Queued`, do not increment `attempts`, do not clear `secrets`, and record `provider_auth` or `daily_limit`. After 3 permanent or auth failures in a row with no success between them, sending pauses for 15 minutes. The pause is a database-cache flag, not a new table. A provider quota response does not count toward those 3. While the pause holds, `/health` and the outbox banner report mail as `misconfigured`. Timeout, connection failure, 408, 429, and 5xx increment `attempts`.

6. **Daily limit.** PHP supplies no default for `MAIL_DAILY_LIMIT`. `.env.example` and `render.yaml` document `MAIL_DAILY_LIMIT=250`. When the variable is unset, the cap is off for the log mailer and for tests. A production send through the real adapter refuses to run when it is unset. The count is rows marked `Sent` whose `sent_at` falls on the current Africa/Lagos day. At the cap, further rows stay `Queued`. The staff banner says the limit was reached and names no key and no count of people.

7. **Log mailer.** In `production`, a credentials or password-reset template is never handed to the `log` mailer. The row is marked `Failed` with `secret_template_refused`, and its secret is cleared. That refusal does not count toward the circuit breaker. Local and `testing` may use the log mailer. Tests of those templates use the array mailer or `Mail::fake()`, and they assert with booleans. The rest of this phase runs without calling `BrevoTransport`.

8. **Audit.** `AuditService::record()` accepts `actor_id`, `action`, `entity`, `entity_id`, `before`, `after`, and `ip`. It strips secrets and inserts one row. It throws when it is called outside an open transaction, and it does not open one. The business write and the audit insert share the caller's transaction. Events recorded now: `auth.login`, `auth.lockout` (the attempt that starts the identifier lockout, once), `auth.logout`, `auth.password_changed`, `auth.password_forced_change`, `auth.bootstrap_created`, `auth.bootstrap_rearmed`, and `auth.status_changed`. `auth.password_reset` is recorded when that flow exists. There is no row per failed attempt. `entity_id` is the user id when the account was found, and null when it was not. Keys removed from `before` and `after`, case-insensitively, down to depth 8, are `password`, `password_confirmation`, `current_password`, `temporary_password`, `temp_password`, `token`, `reset_token`, `remember_token`, `password_hash`, `hash`, `secret`, `api_key`, `brevo_api_key`, `body_html`, `body_text`, and `authorization`. A matching key is dropped. Anything nested past depth 8 is dropped. `ip` stores `REMOTE_ADDR` only. `X-Forwarded-For` is not read. On Render that value is the platform proxy until an explicit proxy allow-list exists. Known accounts are stored as `entity_id`. The typed email, the matric number, and the rate-limiter HMAC are not written into the audit row.

9. **Password reset.** The form accepts an email or a matric number, normalised as login does. Every response is one generic sentence, including a missing account, an account that is not Active, and a throttle. The missing-account path does the same dummy hash work as login. Only an Active user is sent a link. The token is single-use and expires in 60 minutes. The throttle key is an HMAC of the identifier. Reset pages send `Cache-Control: no-store` and `Referrer-Policy: no-referrer`. `docs/DEPLOYMENT.md` gains a note to keep the reset path out of access logs. A successful reset clears `must_change_password` and `temp_password_expires_at`, deletes every session for that user, rotates `remember_token`, and checks the new password with `App\Rules\PortalPassword`. The login page shows "Forgot password". `User::sendPasswordResetNotification()` sends only through `MailService`. The user is resolved before the broker. Broker status codes do not reach the response. `createToken()` runs for an Active user. `Password::reset()` checks the token, and the callback ends every session.

10. **Notifications.** The unread count is rendered with the page from `notifications` for the signed-in user where `read_at` is null, in the student layout and in a Filament render hook. Opening the bell loads the list once. There is no polling, and Filament's database-notification channel stays off. `App\Enums\NotificationType` allows `admission`, `registration_confirmed`, `payment_confirmed`, `result_published`, `announcement`, and `timetable_published`. Any other string is rejected. Credentials and password reset are email only. Mark-as-read updates a row only when `user_id` is the signed-in user. A Super Admin does not mark another person's notification read.

11. **Templates.** HTML mail inlines the DESIGN.md colours. The institution name is styled text. Emails include no logo image and no CID attachment. Each template declares its required data keys. A missing key, or an unresolved `{{` or `:placeholder` left in the output, refuses the send. Subjects reject carriage returns and line breaks. Template variables are escaped.

12. **Outbox page.** Only a Super Admin may view the outbox, retry a row, send a queued row, or send a test email. Credentials and reset bodies are never shown. Retry is unavailable for those two templates. The page tells the operator to re-issue credentials or to request a new reset link.

13. **`/health`.** The public JSON adds `queue` as `ok`, `backlog`, or `failing`, and `mail` as `log`, `brevo`, `unconfigured`, or `misconfigured`. `backlog` means a `jobs` row whose `available_at` is more than 10 minutes ago. `failing` means a `failed_jobs` row in the last 24 hours by the clock, and it wins over `backlog`. `mail` is `log` or `brevo` from `MAIL_MAILER` when that adapter can run. An HTTPS mailer with no URL or no key is `unconfigured`. An open circuit breaker is `misconfigured`. `brevo` names the adapter; the vendor is still to be confirmed. The body has no counts and no values: no queue depth, no key, no key length, no from-address, and no error text. `failing`, `backlog`, `unconfigured`, and `misconfigured` make the snapshot degraded. The platform probe stays on `/up`.

14. **Admin recovery without email.** Until a provider can send mail on the live site, a Super Admin who has forgotten the password cannot reset it there. Production refuses the reset template on the log mailer, and the bootstrap does not create or re-arm an admin once a Super Admin exists and has changed the forced password. A 15-minute identifier lockout expires by itself and is not this case. This ADR builds none of the options below.

    - **Env-gated one-time reset.** A dedicated flag plus a bcrypt hash, both set in the host, re-arms that existing bootstrap account for one boot even after the forced change, writes an audit row, prints neither the hash nor the address, and does nothing on the next boot unless the owner sets the flag again. The owner deletes both variables after that boot. Risk: a flag left in place resets the admin on every deploy. The hash sits in the host dashboard. A flag that can target any user is a backdoor. The command has to stay limited to the existing bootstrap account.
    - **A second Super Admin, kept as a spare.** The spare's identifier is not the locked one, so a 15-minute lockout of the first account does not freeze the spare. Risk: two accounts have full access, and the spare password has to be stored offline. Today's bootstrap refuses a second Super Admin. A signed-in spare cannot replace the other account's password until user management exists, so a forgotten password is not fixed by the spare alone.
    - **Wait for a provider.** No new backdoor. Risk: the only admin who has forgotten the password cannot open the staff panel until mail works or someone uses a shell.

    Recommendation: the env-gated one-time reset, limited to the existing bootstrap account, audited, silent in the logs, and removed from the host after the boot. It is not built here.

**Reason:** Render's free plan cannot use SMTP, and no provider account is available yet. The outbox still has to hold a password and a reset token until send without putting them in the queue, the logs, or the staff screen. Audit and reset have to record the event without storing the identifier or the secret.

**Consequences:** Phase 5 implements this ADR. Tests pass with the log mailer and test fakes. A live send waits until a provider is confirmed and `MAIL_MAILER`, `MAIL_API_URL`, `MAIL_API_KEY`, and `MAIL_DAILY_LIMIT` are set. The approved schema change is the nullable `secrets` column only. `redacted_at` is unchanged. Admin recovery is a later decision if the recommendation is accepted.
