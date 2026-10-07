# Project Memory

> Current project state. Update at the end of every working session.
> Permanent decisions live in DECISIONS.md; this file changes often.

**Last updated:** Wednesday, 07 October 2026 (TASK-013)
**Demo date:** Wednesday, 07 October 2026

## Current Status

Laravel 13 skeleton is in the working tree (PHP 8.5.10, Laravel 13.35.0). Tailwind 4 is configured with design tokens and a local-only `/design-preview` page. TASK-010 added Livewire 4.4, Filament 5.10 (staff panel at `/staff`), Pest 4.7, Larastan 3.12, Pint, Excel 4.0, and Dompdf 3.1. TASK-011 added the empty folder skeleton (services, enums, support helpers, route files). TASK-012 points local development at MySQL 8 (dev-only Compose), with database sessions, cache, and queue, optional TLS, and a non-local boot check. TASK-013 tightened repository hygiene (ignore rules, EditorConfig, pull-request checklist, commit style in the README). No university features yet. Setup through TASK-013 is the initial commit on `main`. Day-to-day work continues on `phase-1-setup`. Remote: `https://github.com/plexxypc/universityportalprototype.git`. Protecting `main` is still open.

Two changes since the first Laravel rewrite: (1) **Paystack is out; payments use Remita and/or Interswitch (Quickteller)** behind a provider-neutral gateway with a Demo Gateway (ADR-023); (2) **capacity target: about 150 students now, up to 1,000 supported on launch infrastructure, expansion planned beyond about 2,000** (ADR-024).

The stack was changed from the earlier Next.js/Supabase draft to **Laravel + Filament + MySQL** (ADR-001 to ADR-003). All documents have been rewritten or patched to match. A new SYSTEM_OVERVIEW.md covers features, hosting options and production cost estimates.

## Completed

- PRD v2.1 (PRD.md): CBT separation, admin-led onboarding, configurable grading, full scope with milestones, Laravel hosting section
- ARCHITECTURE.md (Laravel)
- DESIGN.md (with Filament theming notes)
- RULES.md and Cursor rules in `cursor-rules/`
- TASKS.md (TASK-001 to TASK-186, including post-demo backlog)
- DECISIONS.md (ADR-001 to ADR-024)
- TEST_PLAN.md
- SECURITY.md
- SYSTEM_OVERVIEW.md

## Current Task

**TASK-013** hygiene is in the initial commit and awaiting review. `.gitignore` ignores env files (except `.env.example`), dependencies, build output, local storage and logs, IDE folders, and Docker data directories. The `.cursor` folder and documentation stay tracked. `.editorconfig` uses 4 spaces for PHP and 2 for JS, CSS, and YAML. `.github/pull_request_template.md` is the pull-request checklist. The README Contributing section points at `RULES.md` for `type(scope): summary` with the task id in the body. `.env` is ignored and holds the local `APP_KEY`; it is not in the index. Branch protection for `main` is not done. Work continues on `phase-1-setup`.

**TASK-012** is implemented and awaiting review. `.env.example` lists the ARCHITECTURE.md section 9 variables with placeholders. `compose.dev.yaml` runs MySQL 8.0 with a persistent volume, app database `university_portal`, and test database `university_portal_testing` (user `portal`, password `portal`). `DB_SSL_CA` is optional: a file path or certificate text (text is written to `storage/app/certs/mysql-ca.pem` on boot). Sessions, cache, and the queue use the database. Existing skeleton migrations created `sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, and `failed_jobs` on both databases. `config/portal.php` holds institution placeholders, upload limits, and capacity placeholders. Outside `local`, boot fails if `APP_KEY`, `APP_URL`, or MySQL settings are missing, if `DB_CONNECTION` is not `mysql`, or if `APP_DEBUG` is true. The session cookie is HttpOnly, SameSite=Lax, and Secure when `APP_ENV` is not `local`. `composer check` passed (16 tests) against `university_portal_testing`.

**TASK-011** is implemented and awaiting review. Empty service classes, status and role enums, and `Money`, `Dates`, and `CsvSafe` placeholders are in place. Student and staff route files use the `web` middleware group. `routes/webhooks.php` is loaded outside that group. The non-Filament staff placeholder is `GET /staff/downloads/ping`, so it does not take Filament's `/staff` panel. No models, migrations, or controller logic were added.

**TASK-010** is implemented and awaiting review. The staff Filament panel is the default panel at `/staff`, with Filament's own login at `/staff/login`. No Filament user was created. Theme stays at Filament's default amber until TASK-019. Resource discovery points at `app/Filament/Staff/`. Pest 4 runs the existing PHPUnit tests. Pint uses the Laravel preset. Larastan is level 5 in `phpstan.neon`. Commands: `composer lint`, `composer analyse`, `composer test`, `composer check`. PHPUnit is locked at 12.5.33 because Pest 4.7.8 conflicts with any newer 12.5 patch. TASK-009 remains uncommitted. Phase 0 account setup (TASK-001 to TASK-007) is still open. The TASK-008 skeleton commit has not been made.

## Key Decisions in Force

- Stack: Laravel (PHP 8.3+), Filament staff panel, Blade + Livewire student portal, Tailwind, MySQL 8, Pest.
- Demo hosting: DigitalOcean App Platform (Docker) + Aiven free MySQL on DigitalOcean, same region. Fallback: Render free.
- Production: paid, always-on host, HA database, same region; recommended start is DigitalOcean Europe; decide with the institution after data-residency advice (ADR-022).
- CBT engine: no integration of any kind (ADR-004).
- No public student registration; students are imported or added by staff; login by matric number + emailed temporary password (ADR-005, 007, 008).
- Money in unsigned integer kobo (ADR-009). Authorisation by policies and scoped queries; no RLS (ADR-013).
- Payments: Demo Gateway first, then the adapter for the provider the institution's bursary uses (Remita or Interswitch). Redirects, callbacks and notifications only trigger a re-query; payments can stay Pending and are polled until they settle or expire (ADR-023).
- Capacity: Tier 1 launch infrastructure for up to 1,000 students; "supports 1,000 students" is claimed only after the load test in TEST_PLAN §17 passes (ADR-024).

## Owner Inputs Still Needed

| Item | Needed for | Status |
|---|---|---|
| GitHub repository | TASK-001 | Remote set: `plexxypc/universityportalprototype`. Privacy and branch protection still to confirm. |
| DigitalOcean account | TASK-002 | Pending |
| Aiven account + free MySQL on DigitalOcean + CA certificate | TASK-003 | Pending |
| Which payment provider the institution uses or requires (Remita or Interswitch) and sandbox/demo credentials | TASK-004 | **Open question for the bursary**; Demo Gateway works without it |
| Agreement with the institution on capacity targets (300 concurrent users, 2-second p95) | PRD §13.1, load-test task | Proposed, needs agreement |
| Brevo account + verified sender | TASK-005 | Optional (log mailer works without it) |
| Demo institution name, code, logo | TASK-006 | Placeholder acceptable |
| Render account (fallback host) | TASK-007 | Optional |
| Confirm Aiven free tier region availability on DigitalOcean | TASK-003 | Check in Aiven console |

## Known Issues

- PHP 8.5.10 (winget `PHP.PHP.8.5`) is on the user `PATH`. `ext-intl`, `ext-gd`, and `ext-pdo_mysql` are enabled in that PHP's `php.ini`. `pdo_mysql` was commented out until TASK-012. Composer 2.10.3 is `%LOCALAPPDATA%\Composer\composer.bat`. A new terminal is required before `composer` is on `PATH`.
- Docker Desktop's engine was stopped. TASK-012 started Docker Desktop so `compose.dev.yaml` could run. The engine must be running before `php artisan migrate` or `composer test`.
- `.gitignore` no longer ignores `.cursor`. The whole folder, including the project documents, can be committed. `.env` stays ignored.

## Risks to Watch

- Two days to Wednesday is tight for the full scope. Milestone A (core journey) is the priority; do not start Phase 11 until the Milestone A gate passes.
- Aiven free MySQL has capped resources and may be powered off if unused; confirm it is running before the demo. App Platform credit offers and Render free behaviour (sleep, ephemeral disk) must be verified when accounts are created.
- Brevo free tier is 300 emails/day; bulk imports are sent in batches by the queue.
- Remita/Interswitch live onboarding needs business verification and may take time; fee terms were not confirmed (sources conflict). Do not promise a live payment date for Wednesday.
- The "1,000 students without hitch" promise should be stated as a measured result (load test) rather than an open-ended guarantee.
- Authorisation relies on application code (no database row security); isolation tests are a release gate.
- Production figures in SYSTEM_OVERVIEW.md are planning estimates from public pages; confirm before budgeting.
- Data-residency advice is needed before production region selection.

## Next Step

Continue on `phase-1-setup`. Confirm `.env` stays ignored. Protect `main` on GitHub when you want that rule (needs a GitHub login; `gh` is not installed on this machine). Next build task is TASK-014 (GitHub Actions). Phase 0 accounts can proceed in parallel.

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
