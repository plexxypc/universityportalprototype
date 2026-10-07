# Project Memory

> Current project state. Update at the end of every working session.
> Permanent decisions live in DECISIONS.md; this file changes often.

**Last updated:** Wednesday, 07 October 2026 (local checks before the phase-1-setup commits)
**Demo date:** Wednesday, 07 October 2026

## Current Status

Laravel 13 skeleton is in the working tree (PHP 8.5.10, Laravel 13.35.0). Tailwind 4 is configured with design tokens and a local-only `/design-preview` page. TASK-010 added Livewire 4.4, Filament 5.10 (staff panel at `/staff`), Pest 4.7, Larastan 3.12, Pint, Excel 4.0, and Dompdf 3.1. TASK-011 added the empty folder skeleton (services, enums, support helpers, route files). TASK-012 points local development at MySQL 8 (dev-only Compose), with database sessions, cache, and queue, optional TLS, and a non-local boot check. TASK-013 tightened repository hygiene (ignore rules, EditorConfig, pull-request checklist, commit style in the README). TASK-014 adds `.github/workflows/ci.yml` (Pint, Larastan, Pest on MySQL 8.0, Composer and npm audit, frontend build) and a CI badge on `main`. TASK-015 adds the production image in `docker/` (nginx, php-fpm, queue worker, and scheduler). TASK-016 adds `/health` beside the built-in `/up` route. No university features yet. Setup through TASK-013 is the initial commit on `main`. Day-to-day work continues on `phase-1-setup`. Remote: `https://github.com/plexxypc/universityportalprototype.git`. Protecting `main` is still open.

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

**TASK-017 (part 1)** is the deployment write-up in `docs/DEPLOYMENT.md`, plus a boot fix for flattened Aiven CA text. `docker/entrypoint.sh` rebuilds `DB_SSL_CA` when line breaks were removed, replaced with spaces, or stored as literal `\n`, writes a normal PEM, and refuses anything that is not a certificate without logging the value. `tests/Unit/MysqlCaEntrypointTest.php` covers that. The doc tells the first deploy to use `APP_URL=https://pending.example.com`, then the real hostname, and to check the instance price and set a spending alert first. The flattened-CA boot in the checks below is the local proof of the rebuild. The app has not been created in DigitalOcean or Aiven yet. TASK-017 stays open until that deploy is done.

Project markdown now lives at the repository root: `PRD.md`, `ARCHITECTURE.md`, `DESIGN.md`, `RULES.md`, `TASKS.md`, `DECISIONS.md`, `MEMORY.md`, `TEST_PLAN.md`, `SECURITY.md`, and `SYSTEM_OVERVIEW.md`. `docs/starter.md.txt` is the session starter. `.cursor/rules` has `backend.mdc`, `testing.mdc`, `general.mdc`, and `frontend.mdc`.

**Checked on this machine, 07 October 2026:**

- `composer check`: Pint passed, Larastan passed with 0 errors, Pest passed 32 tests and 189 assertions.
- `npm run build`: the Vite production build finished in 25.47s.
- `docker build -f docker/Dockerfile -t university-portal:local .` finished and tagged `university-portal:local`.
- Container `university-portal-check` joined `universityportalprototype_default` with `DB_HOST=mysql` and was published on host port 8082. `http://127.0.0.1:8082/up` returned HTTP 200. `/health` returned HTTP 200 with `"database":"ok"` and `"heartbeat":"ok"`. Docker reported the container healthy. The entrypoint log showed the config, route, and view caches, `Scheduler heartbeat recorded.`, and supervisord starting php-fpm, nginx, the queue worker, and the scheduler. It had no error, refusal, or exception line.
- A second boot used a one-day throwaway certificate whose line breaks had been replaced with spaces. The log line was `Database CA certificate written (1 block(s)).` The log did not contain the certificate. The private key and the certificate file were deleted. No real CA was used.

**TASK-016** is implemented and awaiting review. `/up` stays Laravel's built-in health route. `/health` is registered outside the web middleware group, allows 60 requests per minute per forwarded client IP using the file cache (so a database failure cannot turn the probe into an error page), and returns coarse JSON with `Cache-Control: no-store, private`. The database probe opens its own PDO with a 3-second connect timeout and does not change the shared MySQL connection. `portal:heartbeat` stores the current time every minute, and the container entrypoint runs it once at startup. A database failure or a heartbeat older than 2 minutes returns HTTP 503 with status `degraded`. That heartbeat shows the scheduler ran recently. It does not show that the queue worker is consuming jobs. Phase 5 notes a queue-backlog check once the email outbox exists. `APP_VERSION` is optional. The checks in this section are the verification for this task.

**TASK-015** is implemented and awaiting review. `docker/Dockerfile` is a three-stage image: Node builds Vite assets, Composer installs production dependencies with optimised autoloading, and the final stage runs nginx, php-fpm, `queue:work`, and `schedule:work` under supervisor as `www-data`. The container listens on `PORT` (default 8080). The entrypoint writes `DB_SSL_CA` certificate text to `storage/app/certs/mysql-ca.pem` when provided, caches config, routes, and views, links storage, and runs `php artisan migrate --force` only when `RUN_MIGRATIONS=true`. Outside `local` it refuses to start when `APP_DEBUG` is not `false` or `APP_KEY`, `APP_URL`, or the MySQL settings are missing. Upload limits are 8 MB in nginx and PHP. Opcache is on for php-fpm, version headers are hidden, and the load balancer is trusted for HTTPS and client IP. The image is PHP 8.4 because `composer.lock` (Symfony 8.1) requires PHP 8.4.1 or newer. The checks in this section are the verification for this image. Host port 8080 was already taken by another project's `cbt-preview-1`, so the new container was published on host port 8082.

**TASK-014** is written and stays open until GitHub CI is green on `phase-1-setup`. The workflow name is CI. Job names for required checks are Pint, Larastan, Pest, Dependency audit, and Frontend build. Actions are pinned to `actions/checkout` v7.0.1 (`3d3c42e5aac5ba805825da76410c181273ba90b1`), `actions/setup-node` v7.0.0 (`820762786026740c76f36085b0efc47a31fe5020`), and `shivammathur/setup-php` 2.37.2 (`f3e473d116dcccaddc5834248c87452386958240`). PHP 8.3 and Composer 2.10.3. Node 22. Pest uses throwaway MySQL user `portal` / password `portal` on `university_portal_testing`, matching `phpunit.xml`. No current Pest test renders a page that needs Vite: `tests/Feature/ExampleTest.php` hits `/`, and `welcome.blade.php` calls `@vite` only when `public/build/manifest.json` or `public/hot` exists (both gitignored). `tests/Feature/DesignPreviewTest.php` expects 404 because `/design-preview` is registered only when `APP_ENV=local`, so `layouts/app.blade.php` (unconditional `@vite`) is not rendered. `composer audit` on this lockfile exited 0. `npm audit --audit-level=high` exited 1.

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
| GitHub repository | TASK-001 | Private repo `plexxypc/universityportalprototype`. `gh` 2.102.0 is logged in as `plexxypc`. Branch protection and secret scanning need GitHub Pro on a private repo. |
| DigitalOcean account | TASK-002 | Pending |
| Aiven account + free MySQL on DigitalOcean + CA certificate | TASK-003 | Pending |
| Which payment provider the institution uses or requires (Remita or Interswitch) and sandbox/demo credentials | TASK-004 | **Open question for the bursary**; Demo Gateway works without it |
| Agreement with the institution on capacity targets (300 concurrent users, 2-second p95) | PRD §13.1, load-test task | Proposed, needs agreement |
| Brevo account + verified sender | TASK-005 | Optional (log mailer works without it) |
| Demo institution name, code, logo | TASK-006 | Placeholder acceptable |
| Render account (fallback host) | TASK-007 | Optional |
| Confirm Aiven free tier region availability on DigitalOcean | TASK-003 | Check in Aiven console |

## Known limitations

- Branch protection is unavailable on this private repo without a paid GitHub plan; until then, merges to main happen only through pull requests with all five checks green.

## Known Issues

- PHP 8.5.10 (winget `PHP.PHP.8.5`) is on the user `PATH`. `ext-intl`, `ext-gd`, and `ext-pdo_mysql` are enabled in that PHP's `php.ini`. `pdo_mysql` was commented out until TASK-012. Composer 2.10.3 is `%LOCALAPPDATA%\Composer\composer.bat`. A new terminal is required before `composer` is on `PATH`.
- Docker Desktop's engine was stopped. TASK-012 started Docker Desktop so `compose.dev.yaml` could run. The engine must be running before `php artisan migrate` or `composer test`.
- `.gitignore` no longer ignores `.cursor`. The whole folder, including the project documents, can be committed. `.env` stays ignored.
- `npm audit --audit-level=high` fails on this lockfile. `concurrently` 10 depends on `shell-quote` 1.8.4–1.10.0 (critical, GHSA-pqg4-j6r4-53mv). The suggested fix downgrades `concurrently` to 9.2.1. The Dependency audit job will be red until that advisory is resolved. Composer audit is clean.

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

Continue on `phase-1-setup`. TASK-015 and TASK-016 passed the local checks in this file. TASK-014 stays open until GitHub CI is green on this branch. TASK-017 stays open until the DigitalOcean and Aiven deploy. The Dependency audit job will fail on the current `shell-quote` advisory until that dependency is updated. CI still requests PHP 8.3 while `composer.lock` needs PHP 8.4.1 or newer. Phase 0 accounts can proceed in parallel.

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
