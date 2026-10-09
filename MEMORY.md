# Project Memory

> Current project state. Update at the end of every working session.
> Permanent decisions live in DECISIONS.md; this file changes often.

**Last updated:** Friday, 09 October 2026 (Phase 4, TASK-037)
**Demo date:** Wednesday, 07 October 2026

## Current Status

Phase 4 is in progress on `phase-4-auth`. TASK-037 is done: the PRD section 5 permission map, user role helpers, gates for every ADR-028 permission key, and the role-scope validator. An inactive user is granted nothing. Super Admin is allowed every ability except `course_registration.submit` and `payments.make`. Row-level access waits for policies in TASK-038. Phase 3 is merged to `main` as pull request #7 (`9aa094d`). ADR-028 is committed on this branch. A Published result requires `published_at`. Academic standing thresholds wait for a new table in TASK-060. Decimal totals and grade-band edges are decided in TASK-059.

**Live database:** The Phase 3 migrations have not run on the live Aiven database. Those files are not on `origin/main`. TASK-172, which runs migrations on Aiven, is still open. This machine's `.env` points at local Docker (`127.0.0.1`, database `university_portal`). That local database has 46 applied migrations, through `2026_10_09_230007_create_audit_logs_table`.

The staff panel uses the design tokens, self-hosted Inter, and the institution name and logo from `config/portal.php`. Navigation groups follow the PRD module names and stay hidden until a page is assigned. Role filtering is Phase 4. The staff theme imports Filament CSS from `vendor`, so the frontend CI job and the Docker asset stage install or copy that tree before `npm run build`. That copy is in `6c52a23` (`Fixed Frontend CI`).

**Design preview (local only):** `http://127.0.0.1:8000/design-preview` and `http://127.0.0.1:8000/design-preview/student`. Both routes exist only when `APP_ENV` is `local`. Staff login: `http://127.0.0.1:8000/staff/login`. `php artisan serve` is the current interface. The container on host port 8081 can be an older image.

**Filament theme:** Filament **v5.10.0**. `StaffPanelProvider` registers a Vite theme at `resources/css/filament/staff/theme.css`. That file imports `@fontsource/inter` and `vendor/filament/filament/resources/css/theme.css`, and forces badge radius to a pill. Inter is set with `Filament\FontProviders\LocalFontProvider` (no Bunny Fonts). The primary palette is `Filament\Support\Colors\Color::hex('#4F46E5')` from `App\Filament\StaffPanelTheme`, with shades 50, 500, 600, and 700 replaced by the design tokens. Filament stores the palette as OKLCH. Dark mode is off. The sidebar is 240px and collapsible. Brand name and logo come from `config/portal.php`.

**Checks:** On 09 October 2026, `composer check` passed on `phase-4-auth`: Pint passed, Larastan reported 0 errors, Pest passed **374 tests** and **1526 assertions**. Larastan reads `casts()` (`parseModelCastsMethod`) so a status or role column is the enum, not the varchar. GitHub CI on `d5fc5c0` is the last green run recorded here: https://github.com/plexxypc/universityportalprototype/actions/runs/37689062914. Phase 4 has not been through a recorded GitHub run.

**Live demo:** `https://universityportalprototype.onrender.com` (Render free web service, Aiven MySQL). On 07 October 2026 the home page and `/up` returned HTTP 200 with the title University Portal. `/health` returned HTTP 200 with `"status": "ok"`, `"database": "ok"`, `"heartbeat": "ok"`, `"mail_driver": "log"`, `"payment_provider": "sandbox"`, and `"environment": "production"`. DigitalOcean App Platform is not in use. A local container named `university-portal` is still running at `http://127.0.0.1:8081` (host 8081 to container 8080).

**Versions locked in `composer.lock`:** Laravel 13.35.0, Filament 5.10.0, Livewire 4.4.7. Also Pest 4.7.8, Larastan 3.12.3, Pint 1.32.1, Excel 4.0.3, Dompdf 3.1.2. `composer.json` requires PHP `^8.4`; the lockfile needs PHP 8.4.1 or newer. This machine's CLI is PHP 8.5.10. GitHub Actions and the production image use PHP 8.4.

**Check commands:** `composer lint` (Pint), `composer analyse` (Larastan), `composer test` (Pest), and `composer check` (all three).

Two changes since the first Laravel rewrite: (1) **Paystack is out; payments use Remita and/or Interswitch (Quickteller)** behind a provider-neutral gateway with a Demo Gateway (ADR-023); (2) **capacity target: about 150 students now, up to 1,000 supported on launch infrastructure, expansion planned beyond about 2,000** (ADR-024).

The stack was changed from the earlier Next.js/Supabase draft to **Laravel + Filament + MySQL** (ADR-001 to ADR-003). All documents have been rewritten or patched to match. A new SYSTEM_OVERVIEW.md covers features, hosting options and production cost estimates.

## Completed

- PRD v2.1 (PRD.md): CBT separation, admin-led onboarding, configurable grading, full scope with milestones, Laravel hosting section
- ARCHITECTURE.md (Laravel)
- DESIGN.md (with Filament theming notes)
- RULES.md and Cursor rules in `cursor-rules/`
- TASKS.md (TASK-001 to TASK-186, including post-demo backlog)
- DECISIONS.md (ADR-001 to ADR-028). ADR-027 cascades `guardians.student_id` only. ADR-028 is the Phase 4 authentication and authorisation decision.
- Phase 2 shared interface: TASK-019 through TASK-025, verified in the repository and merged to `origin/main` as pull request #5 (`d253fd1`)
- TEST_PLAN.md
- SECURITY.md
- SYSTEM_OVERVIEW.md
- Phase 1 in the repository: TASK-008 through TASK-018
- Phase 3 database foundation: TASK-026 through TASK-036, on `phase-3-database` (`1ab5e99`). Not merged to `origin/main`.
- Live demo on Render with Aiven MySQL: `https://universityportalprototype.onrender.com`

## Shared components

Blade pieces live in `resources/views/components/`. The student shell is `resources/views/layouts/student.blade.php`.

| Piece | Where |
|---|---|
| Form | `button`, `input`, `password-input`, `select`, `checkbox`, `textarea`, `field` |
| Display | `card`, `status-badge`, `stat-card`, `dialog`, `toast`, `toast-stack`, `tabs` |
| Feedback | `skeleton`, `empty-state`, `error-state`, `confirm-dialog` |
| Table | `table` and `partials/table-cell` (server pagination through `LengthAwarePaginator`) |
| Page chrome | `page-header`, `breadcrumbs`, `money`, `date`, `ui/icon` |
| Student shell | `layouts/student.blade.php`, `student/sidebar` (desktop), `student/top-bar` and `student/bottom-nav` (mobile) |
| Helpers | `app/Support/Money.php`, `Dates.php`, `CsvSafe.php`, plus `StatusTone`, `FieldId`, `ControlClasses`, `StudentNavigation`, `DesignPreviewTable` |
| Staff theme | `app/Providers/Filament/StaffPanelProvider.php`, `app/Filament/StaffPanelTheme.php`, `resources/css/filament/staff/theme.css` |
| Local preview | `routes/web.php` (local only), `resources/views/design-preview.blade.php`, `resources/views/design-preview-student.blade.php` |

## Tables by group

From ARCHITECTURE.md section 10, matched to the built schema. Laravel's `jobs`, `job_batches`, `failed_jobs`, `sessions`, `cache`, `cache_locks`, and `password_reset_tokens` stay as framework tables.

| Group | Tables |
|---|---|
| Identity | `users`, `role_assignments`, `staff` |
| Structure | `institution_settings`, `faculties`, `departments`, `programmes`, `academic_sessions`, `semesters` |
| Courses | `courses`, `programme_courses`, `course_prerequisites`, `course_assignments` |
| People | `applicants`, `students`, `guardians`, `documents`, `import_batches` |
| Registration | `course_registrations`, `course_registration_items` |
| Finance | `fee_categories`, `fee_structures`, `invoices`, `invoice_items`, `invoice_adjustments`, `payments`, `payment_events`, `receipts` |
| Grading | `grading_schemes`, `assessment_components`, `grade_bands`, `classification_bands` |
| Results | `results`, `result_scores` |
| Attendance / Exams | `attendance_sessions`, `attendance_records`, `exam_timetable` |
| Comms | `announcements`, `notifications`, `email_outbox` |
| System | `counters`, `audit_logs` |

## ADR-026 conventions

- **Primary keys.** Unsigned bigint auto-increment. `users.id` is already that type. ULIDs are not used.
- **Datetimes.** Every new instant is `DATETIME`, including `created_at` and `updated_at`. Laravel writes Africa/Lagos wall time (ADR-025). Calendar values use `DATE`. Frozen framework columns stay `TIMESTAMP`: `users.email_verified_at`, `password_reset_tokens.created_at`, and `failed_jobs.failed_at`.
- **Delete rules.** Every foreign key is `ON DELETE RESTRICT` and is named in the migration. ADR-027 is the one exception: `guardians.student_id` is `ON DELETE CASCADE`.
- **Soft deletes.** No `deleted_at` column and no soft-delete trait. A row's lifecycle is a status column.
- **Enum storage.** Statuses and roles are `varchar(32)` strings, cast to the backed enums in `app/Enums`. The stored text is the label from DESIGN.md. MySQL `ENUM` columns are not used.
- **Naming.** Every unique index, secondary index, foreign key, and `CHECK` is named: `{table}_{columns}_unique`, `{table}_{columns}_index`, `{table}_{column}_foreign`, and `{table}_{rule}_check`, within MySQL's 64-character limit.
- **One-current rows.** A nullable generated flag (`current_flag` or `active_flag` is 1 or NULL) on `academic_sessions`, `semesters`, and `grading_schemes` (ADR-002). The application writes the boolean and clears the previous row inside a transaction.
- **Payment events.** One unique index, `payment_events_provider_event_key_unique`, on `(provider, event_key)`.

## Enforce in a service later

The full list is [`docs/SERVICE_RULES.md`](docs/SERVICE_RULES.md). Each rule is implemented and tested by the task named there. These rules cannot be a database constraint. Step 0 listed none. Step 7 added none.

**Priority.** The schema only guarantees shape: types, unique keys, foreign keys, and CHECKs. The service that writes the row owns the rule, and that task's tests prove it. Phase 4 meets two of them immediately: which roles require a faculty, which require a department, and which must leave both empty (TASK-037), and blocking login when the temporary password has expired (TASK-041). `must_change_password` and `temp_password_expires_at` are set together later (TASK-065). Money totals, grade bands, publication locks, attendance scope, and audit append-only behaviour stay with the tasks below.

| Rule | Task |
|---|---|
| Which roles require a faculty, which require a department, and which must leave both empty | TASK-037 |
| Lecturer scope is `course_assignments` | TASK-136 |
| When a role assignment sets both scope ids, the department belongs to that faculty | TASK-135 |
| Phone normalised to `+234` | TASK-075 |
| `staff_no` pattern | TASK-132 |
| `must_change_password` and `temp_password_expires_at` are set together | TASK-065 |
| Block login when the temporary password has expired | TASK-041 |
| Deactivating a staff profile deactivates the user | TASK-137 |
| Deactivating a staff profile revokes sessions | TASK-137 |
| Switching the current session clears the old row first, inside a transaction | TASK-055 |
| Switching the active semester clears the old row first, inside a transaction | TASK-056 |
| Reads fall back to `config/portal.php` while `institution_settings` is empty | TASK-054 |
| A non-empty session list contains a current session. The flag only guarantees at most one | TASK-055 |
| A non-empty semester list contains an active semester. The flag only guarantees at most one | TASK-056 |
| The active semester belongs to the current session | TASK-056 |
| Session names match `2026/2027` | TASK-055 |
| Prerequisite cycles. A two-course cycle is stored | TASK-058 |
| A closed list of degree types | TASK-057 |
| What payment satisfies `require_minimum_payment` | TASK-094 |
| `users.name` is set from the student's three name parts | TASK-064 |
| On conversion, copy the applicant onto the new user and student | TASK-080 |
| Reject an applicant email that already exists on `users` | TASK-075 |
| Default a blank entry session to the current session | TASK-064 |
| Generate `matric_no` from `counters` with `lockForUpdate` | TASK-064 |
| A document's owner row exists. Deleting an applicant or student is refused, or the documents are removed first | TASK-070 |
| Document type and the 5 MB limit, on the local disk | TASK-070 |
| Registration minimum and maximum units | TASK-088 |
| Registration prerequisites | TASK-088 |
| Registration deadline lock | TASK-088 |
| Auto-approve when approval is off | TASK-091 |
| Copy `credit_units` onto the item at registration time | TASK-090 |
| `total_units` equals the sum of the items | TASK-090 |
| Import commits in chunks of 25, with duplicate skip or update | TASK-076 |
| Import failure report | TASK-077 |
| Item lines sum to `total_kobo` | TASK-096 |
| Adjustments sum to `adjustments_kobo` | TASK-097 |
| Successful payments sum to `paid_kobo` | TASK-101 |
| Invoice status is derived from those totals | TASK-101 |
| A payment amount is at most the outstanding balance | TASK-100 |
| A pending payment does not change `paid_kobo` | TASK-101 |
| The payment's `student_id` is the invoice's student | TASK-100 |
| Currency is NGN. It is not a column | TASK-101 |
| A receipt is created only for a Successful payment, once, inside the settlement transaction | TASK-106 |
| Payment events are insert-only | TASK-103 |
| At most one non-cancelled invoice per student per session | TASK-096 |
| A reversal reduces `paid_kobo` and does not delete the payment | TASK-101 |
| Invoice numbers, receipt numbers, and payment references are allocated from `counters` inside the creating transaction | TASK-096, TASK-100, TASK-106 |
| Assessment components of one scheme total 100 | TASK-060 |
| Grade bands cover 0–100 with no gaps and no overlap | TASK-060 |
| A score is not above that component's maximum | TASK-111 |
| Published results are locked | TASK-114 |
| Amendments are audited and re-published | TASK-115 |
| A result score's component belongs to the result's scheme | TASK-111 |
| `scheme_version` matches the linked scheme row | TASK-111 |
| Editing the active scheme inserts a new version and repoints unpublished results. Published rows keep their scheme id | TASK-060 |
| When unpublished results move, `result_scores` are remapped to the new version's components in that transaction | TASK-060 |
| GPA uses the latest or best attempt and applies `resit_points_cap` | TASK-059 |
| Classification bands do not overlap or leave gaps | TASK-060 |
| The pass mark agrees with the fail band | TASK-060 |
| Stored total, grade, and points match the scores and the scheme | TASK-059 |
| Switching the active grading scheme clears the previous row inside a transaction | TASK-060 |
| A decimal total is rounded before the grade lookup, or bands are lower-bound inclusive | TASK-059 |
| Academic standing thresholds (warning, probation) need their own table | TASK-060 |
| The marked student is registered for that course in that semester | TASK-122 |
| Attendance percentage and `attendance_threshold` | TASK-122 |
| A lecturer marks only assigned courses | TASK-122 |
| Exam clashes for the same venue and overlapping time, and for the same level or programme | TASK-127 |
| A student sees only exams for registered courses | TASK-129 |
| Publishing a timetable creates the notification and the outbox row | TASK-130 |
| Publishing an announcement creates the notification and, when `send_email` is true, the outbox row | TASK-139 |
| Which accounts match an audience, including an institution-wide level | TASK-139 |
| Allowed notification types | TASK-052 |
| Allowed email template names | TASK-048 |
| Outbox retry limits | TASK-047 |
| Daily send limit | TASK-049 |
| A credentials email is redacted when it is sent, when attempts are exhausted, or when credentials are re-issued | TASK-047 |
| The password stays out of subject, `last_error`, and application logs | TASK-047 |
| Only the audit service inserts. Nothing updates or deletes `audit_logs` | TASK-046 |
| Secrets are removed from audit `before` and `after` | TASK-046 |
| Only a Super Admin reads audit logs | TASK-149 |
| Database-level append-only protection for `audit_logs` | Phase 19 intro (no task id) |

## Current Task

**TASK-038** (Phase 4): base policies and `visibleTo()` scopes. TASK-037 is done. The role-scope rule (which roles require a faculty, a department, or neither) is `App\Support\Rbac\RoleScopeValidator`, tested by `it enforces which roles require a faculty, a department, or neither`. Authentication and authorisation continues with TASK-038 through TASK-045.

Still open from Phase 0: TASK-002 (DigitalOcean account; the owner said not to pursue that production host), TASK-004 (bursary payment provider), TASK-005 (Brevo, optional), and TASK-006 (demo institution name, code, and logo).

## Key Decisions in Force

- Stack: Laravel (PHP 8.4+), Filament staff panel, Blade + Livewire student portal, Tailwind, MySQL 8, Pest. `composer.lock` requires PHP 8.4.1 or newer.
- Demo hosting: Render free web service plus Aiven MySQL. DigitalOcean App Platform remains the documented alternative; the owner said not to pursue it for this demo.
- Production: paid, always-on host, HA database, same region; recommended start is DigitalOcean Europe; decide with the institution after data-residency advice (ADR-022).
- CBT engine: no integration of any kind (ADR-004).
- No public student registration; students are imported or added by staff; login by matric number + emailed temporary password (ADR-005, 007, 008).
- Money in unsigned integer kobo (ADR-009). Authorisation by policies and scoped queries; no RLS (ADR-013).
- Payments: Demo Gateway first, then the adapter for the provider the institution's bursary uses (Remita or Interswitch). Redirects, callbacks and notifications only trigger a re-query; payments can stay Pending and are polled until they settle or expire (ADR-023).
- Capacity: Tier 1 launch infrastructure for up to 1,000 students; "supports 1,000 students" is claimed only after the load test in TEST_PLAN §17 passes (ADR-024).

## Owner Inputs Still Needed

| Item | Needed for | Status |
|---|---|---|
| GitHub repository | TASK-001 | Public repo `plexxypc/universityportalprototype`. The owner said not to protect `main`. |
| DigitalOcean account | TASK-002 | Not in use. The owner said not to pursue this production host. |
| Aiven account + free MySQL + CA certificate | TASK-003 | Connected. The live `/health` check reports the database ok. Host, user, and password stay in the Render dashboard. |
| Which payment provider the institution uses or requires (Remita or Interswitch) and sandbox/demo credentials | TASK-004 | **Open question for the bursary**; Demo Gateway works without it |
| Agreement with the institution on capacity targets (300 concurrent users, 2-second p95) | PRD §13.1, load-test task | Proposed, needs agreement |
| Brevo account + verified sender | TASK-005 | Optional (log mailer works without it) |
| Demo institution name, code, logo | TASK-006 | Placeholder acceptable |
| Render account (demo host) | TASK-007 | Live at `https://universityportalprototype.onrender.com` |

## Known limitations

- `main` is not branch-protected. The owner made the repository public and said not to add that protection. Anyone can open the repository. Secrets stay in the Render dashboard and in the ignored `.env`.

## Known Issues

- The live `/health` payload reports `"payment_provider": "sandbox"`. The documented demo setting is `demo`.
- Day-to-day work is on `phase-3-database` (`1ab5e99`, same as `origin/phase-3-database`). `origin/main` is `79d654f`. Local `main` is still `c599ee0`. `phase-2-ui` is `d5904dd`. `phase-1-setup` is `c3f5fa5`. The live Render service does not contain the Phase 3 schema. The design preview routes are not registered outside `local`.
- PHP 8.5.10 is on the user `PATH`. CI and `docker/Dockerfile` use PHP 8.4 because `composer.lock` requires 8.4.1 or newer. `ext-intl`, `ext-gd`, and `ext-pdo_mysql` are enabled. Composer 2.10.3 is `%LOCALAPPDATA%\Composer\composer.bat`.
- Pest needs MySQL from `compose.dev.yaml` (`university_portal_testing`, user `portal`, password `portal`). Docker Desktop has to be running before `composer test` or `composer check`.
- `/health` reports a scheduler heartbeat. It does not show that the queue worker is consuming jobs. A queue-backlog check waits until the email outbox exists (Phase 5, TASK-047).
- The Pest job in `.github/workflows/ci.yml` still uploads a temporary JUnit artifact and widens `COLUMNS` so a past `file_get_contents` warning could be read. CI is green with that step in place.
- Host port 8080 is taken by another project's `cbt-preview-1`. The local portal container uses host port 8081. That image can be older than `php artisan serve` on port 8000.
- The logo placeholder SVG hard-codes `#4F46E5` and white because an image cannot read the CSS variables.
- Filament stores the primary palette as OKLCH. Shades 50, 500, 600, and 700 are the design tokens. The other shades are Filament's generated palette for `#4F46E5`.
- Staff navigation groups do not appear until a resource or page is added. Role filtering is Phase 4.
- The first request to `/staff/login` after a view-cache clear can hit PHP's 30-second limit while Blade compiles. The next request returns the login page.
- Filament's core scripts and its own Inter files are published under `public/js/filament` and `public/fonts/filament`. The staff theme also loads `@fontsource/inter`. Nothing is loaded from Bunny Fonts.
- `.gitignore` does not ignore `.cursor`. `.env` stays ignored.

## Risks to Watch

- The Wednesday 07 October 2026 demo date has passed. Milestone A (core journey) is still the priority. Do not start Phase 11 until the Milestone A gate passes.
- Aiven free MySQL has capped resources and may be powered off if unused; confirm it is running before the demo. App Platform credit offers still need a check when that account exists. Render’s free web service, as documented on 07 October 2026, sleeps after 15 minutes idle, takes about a minute to wake, and drops files on the container disk. The free instance is 512 MB, which is below the 1 GiB this container was sized for. Confirm those limits in the Render dashboard when the account exists.
- Brevo free tier is 300 emails/day; bulk imports are sent in batches by the queue.
- Remita/Interswitch live onboarding needs business verification and may take time; fee terms were not confirmed (sources conflict). Do not promise a live payment date for Wednesday.
- The "1,000 students without hitch" promise should be stated as a measured result (load test) rather than an open-ended guarantee.
- Authorisation relies on application code (no database row security); isolation tests are a release gate.
- Production figures in SYSTEM_OVERVIEW.md are planning estimates from public pages; confirm before budgeting.
- Data-residency advice is needed before production region selection.

## Next Step

Start Phase 4, authentication and authorisation, with TASK-037 on a new branch from `phase-3-database`. Run migrations only against local Docker MySQL (`127.0.0.1`, `university_portal` / `university_portal_testing`). Do not run Phase 3 migrations against the live Aiven database until TASK-172. The Render free service sleeps after about 15 minutes idle; open `https://universityportalprototype.onrender.com/up` and wait for HTTP 200 before a demo. Confirm Aiven is running at the same time.

## Session Log

| Date | Summary |
|---|---|
| 05 Oct 2026 | Reviewed PRD v1.0; applied five changes; produced the nine project documents (Next.js/Supabase draft). |
| 06 Oct 2026 (later) | Replaced Paystack with Remita/Interswitch adapters and a requery-and-poll payment design; added capacity targets, sizing tiers (0–3), upgrade triggers and a load-test gate; updated PRD, architecture, decisions, security, tasks, test plan and overview. |
| 06 Oct 2026 | Switched to Laravel + Filament + MySQL; rewrote architecture, decisions, security, rules, tasks, memory; patched PRD, design and test plan; added SYSTEM_OVERVIEW.md with hosting options and cost estimates. |
| 06 Oct 2026 (night) | TASK-008: created the Laravel 13.11 skeleton (framework 13.35.0) on PHP 8.5.10. App name University Portal, timezone Africa/Lagos, locale en. No starter kit and no MySQL. Default PHPUnit tests passed (2 tests). Commit not made. |
| 06 Oct 2026 (late) | TASK-009: kept Tailwind 4, replaced the Bunny font with self-hosted Inter, defined design tokens in `resources/css/app.css`, and added local-only `/design-preview`. PHPUnit passed (3 tests). Commit not made. |
| 07 Oct 2026 | TASK-010: installed Livewire 4.4.7, Filament 5.10.0 (staff panel at `/staff`), Pest 4.7.8 with the Laravel plugin 4.1.0, Larastan 3.12.3 at level 5, kept Pint 1.32.1 on the Laravel preset, Excel 4.0.3, Dompdf 3.1.2. `composer check` passed (3 tests). No Filament user. Commit not made. |
| 07 Oct 2026 | TASK-011: added empty services, enums, support placeholders, route files, and the empty folders from ARCHITECTURE.md section 4. Webhooks sit outside the web middleware group. Staff downloads use `/staff/downloads`. `composer check` passed (3 tests). Commit not made. |
| 07 Oct 2026 | TASK-012: `.env.example`, dev-only MySQL 8 Compose, optional `DB_SSL_CA`, database sessions/cache/queue, `config/portal.php`, and a non-local boot guard. Enabled `pdo_mysql`. Migrated `university_portal` and `university_portal_testing`. `composer check` passed (16 tests). Commit not made. |
| 07 Oct 2026 | TASK-013: repository hygiene. Broader `.gitignore` (env files except `.env.example`, storage, logs, IDE folders, Docker data dirs; `.cursor` and docs stay tracked). EditorConfig matches Pint (4 spaces PHP, 2 for JS/CSS/YAML). Pull-request checklist and README commit style. Local `APP_KEY` is only in ignored `.env`. |
| 07 Oct 2026 | Initial commit on `main` covering TASK-008 through TASK-013. Remote `origin` is `https://github.com/plexxypc/universityportalprototype.git`. Working branch is `phase-1-setup`. Branch protection for `main` is still open. |
| 07 Oct 2026 | `gh` 2.102.0 is logged in as `plexxypc`. The repository is private. Branch protection and a `main` ruleset both returned HTTP 403 (GitHub Pro required). Secret scanning returned HTTP 422. |
| 07 Oct 2026 | TASK-014: GitHub Actions workflow `.github/workflows/ci.yml` with Pint, Larastan, Pest (MySQL 8.0), Composer and npm audit, and the frontend build. README badge for `main`. Composer audit exited 0. npm audit exited 1 on `shell-quote` via `concurrently`. |
| 07 Oct 2026 | TASK-015: production Docker image. nginx, php-fpm, queue worker, and scheduler in one container. Entrypoint caches config, routes, and views, and migrates only when `RUN_MIGRATIONS=true`. Local run on the Compose network served `/up` with HTTP 200. PHP 8.4 because the lockfile requires it. |
| 07 Oct 2026 | TASK-016: `/health` JSON check beside `/up`. Database probe uses its own 3-second PDO. Scheduler heartbeat every minute and once at container startup. 60/minute per forwarded client IP. `composer check` passed (25 tests). |
| 07 Oct 2026 | TASK-017 part 1: `docs/DEPLOYMENT.md` for App Platform plus Aiven MySQL. No provider accounts were changed. `composer check` passed (25 tests). TASK-017 remains open until the first deploy. |
| 07 Oct 2026 | TASK-017 part 1 follow-up: entrypoint rebuilds a flattened or literal-`\n` Aiven CA and refuses non-certificates without logging the value. `MEMORY.md` moved from `.cursor/rules/` to the repository root. Other project docs are still only under `.cursor/rules/`. `composer check` passed (32 tests). |
| 07 Oct 2026 | Housekeeping: project documents moved from `.cursor/rules/` to the repository root with `git mv`. `starter.md.txt` moved to `docs/`. `.cursor/rules` kept `backend.mdc` and `testing.mdc`. |
| 07 Oct 2026 | Local checks before commit: `composer check` passed (32 tests, 189 assertions), `npm run build` finished, a fresh `university-portal:local` image served `/up` and `/health` (`database` ok, `heartbeat` ok) on port 8082 with no entrypoint errors, and a flattened throwaway CA logged `Database CA certificate written (1 block(s)).` |
| 07 Oct 2026 | CI run 37623461522 failed on PHP 8.3 versus the Symfony 8.1 lockfile, and on critical `shell-quote` 1.9.0. CI and `composer.json` now require PHP 8.4. `package.json` overrides `shell-quote` to 1.12.0. Local `npm audit --audit-level=high` exited 0. `composer check` passed (32 tests). Not pushed. |
| 07 Oct 2026 | TASK-018: Render fallback in `docs/DEPLOYMENT.md` section 9 and `render.yaml` (free Docker web service, secrets left for the dashboard, health check `/up`). Local image with `PORT=10000` served `/up` and `/health`. No application code changed. `composer check` passed (32 tests, 189 assertions). No Render account, so the live service was not created. |
| 07 Oct 2026 | Pest CI saw `APP_ENV=local` because Laravel reads `$_SERVER` before PHPUnit’s forced env. `phpunit.xml` now also sets those server variables to `testing` and `APP_DEBUG=false`. The CI job env stays `local`. Local run with `APP_ENV=local` and `APP_DEBUG=true` passed 32 tests; `/health` reported `testing`. `composer check` with no shell overrides passed. Docs now say PHP 8.4+ because `composer.lock` requires 8.4.1 or newer. TASK-014 stays open. |
| 07 Oct 2026 | Phase 1 wrap-up. Ticked TASK-008 to TASK-012, TASK-014, and left TASK-015, TASK-016, and TASK-018 ticked. Left TASK-013 and TASK-017 open. CI run 37689062914 on `d5fc5c0` is green. `composer check` passed (32 tests, 189 assertions). No application code changed. No public demo URL. Next task is TASK-019. |
| 07 Oct 2026 | Owner made the GitHub repository public and waived protection of `main` (TASK-013). The live demo is Render plus Aiven MySQL at `https://universityportalprototype.onrender.com` (TASK-017). Home, `/up`, and `/health` returned HTTP 200; database and heartbeat were ok. DigitalOcean App Platform was not used. |
| 08 Oct 2026 | Phase 2 shared interface (TASK-025, TASK-020, TASK-021, TASK-022, TASK-024, TASK-023, TASK-019). Helpers, form, display, feedback, table, and student layout are on the local design preview. The staff panel theme uses the design tokens, Inter through `LocalFontProvider`, and the institution name and logo. `composer check` passed (100 tests, 473 assertions) and `npm run build` finished. Not pushed. |
| 08 Oct 2026 | Frontend CI failed because the staff theme imports Filament CSS from `vendor`, and that job never installed Composer packages. The frontend job now runs `composer install` first. The Docker asset stage copies `vendor/filament` and `app` before `npm run build`. Committed as `6c52a23` and merged to `origin/main` in pull request #5 (`d253fd1`). |
| 08 Oct 2026 | Phase 2 wrap-up. TASK-019 through TASK-025 are present and already ticked. No application code changed. `composer check` passed (100 tests, 474 assertions). |
| 08 Oct 2026 | Phase 3 TASK-029 and TASK-030. People tables and course registrations, plus ADR-027 (guardian cascade only). `composer check` passed after TASK-030 (173 tests, 608 assertions). Local `university_portal` on `127.0.0.1`: `migrate:fresh`, rollback of the seven new migrations, then migrate again. Not pushed. |
| 09 Oct 2026 | Phase 3 TASK-031. Finance tables and money CHECK constraints. `composer check` passed (195 tests, 642 assertions). Local `university_portal` on `127.0.0.1`: `migrate:fresh`, rollback of the eight new migrations, then migrate again. Commit `d6366bc`. Not pushed. |
| 09 Oct 2026 | Phase 3 TASK-032. Grading and results tables. `composer check` passed (226 tests, 701 assertions). Local `university_portal` on `127.0.0.1`: `migrate:fresh`, rollback of the six new migrations, then migrate again. Commit `bc13b73`. |
| 09 Oct 2026 | TASK-032 follow-up. A Published result requires `published_at`. Commit `5e7e4b0`. Local `university_portal` on `127.0.0.1`: `migrate:fresh`, rollback of the six grading migrations, then migrate again. |
| 09 Oct 2026 | Phase 3 wrap-up. TASK-026 through TASK-036 are present and ticked. No application code changed. `composer check` passed (326 tests, 1128 assertions). Local `university_portal` has the schema through `audit_logs`. The live Aiven database does not. Next task is TASK-037. |
