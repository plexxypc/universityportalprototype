# Phase rules (apply to every phase prompt)

## Safety
- Run migrations and tests ONLY against local Docker MySQL. Print the DB host and name before any artisan command that touches the database. NEVER connect to Aiven, never send a real email, never call a real payment provider.
- NEVER edit an existing migration. A schema change needs a NEW migration, shown in the plan and approved with it.
- Do not install packages without asking first.
- A password, token, API key, hash or remember_token must never appear in a log, exception message, queue payload, audit row, URL, error page, test failure message or admin screen. Secret checks in tests use boolean assertions.
- Do not edit Docker, CI, render.yaml or config files unless the task needs it and the plan says so.

## Security (every task)
- Authorisation is checked server-side on every route and every Filament/Livewire action. Hiding a button or a dropdown option is never a control.
- Scoped roles (Faculty Admin, Department Officer, Lecturer) are checked on the server for create, update and delete as well as read, using the Permissions map, policies and visibleTo(). A forged id in a request must be refused.
- A student is always derived from the authenticated user, never from request input.
- The portal login page is the only authenticator (ADR-028).
- Every state-changing service method writes its audit row inside the same database transaction (AuditService).
- Scores, money and dates: Money for kobo, Dates for Africa/Lagos; no floats for money or grades.

## Quality
- Thin entry points (ADR-012): controllers, Livewire components and Filament resources validate, authorise and call a service. Business rules live in app/Services and are unit-tested.
- Follow DESIGN.md tokens exactly; no hard-coded colours, sizes, university name or logo.
- Escape all output; no {!! !!} with user data. Accessible, responsive at 375px, 768px and 1440px.
- Pest tests for every rule; never weaken, skip or delete an existing test. Pest runs without a front-end build (Vite is disabled in tests only).
- Implement and tick the docs/SERVICE_RULES.md rows that name the phase's tasks, with the test name.

## Workflow for a bundled phase prompt
1. Read the phase's tasks, the carry-over notes in TASKS.md and the SERVICE_RULES rows. Show ONE plan for the whole phase (files, routes, middleware, migrations if any, tests, decisions needed, risks) and STOP until I approve.
2. Build one task at a time, one commit per task, message form type(scope): summary (TASK-0xx). Run composer check before each commit.
3. Stop at every stop point the prompt names and wait for me to say "continue".
4. At the end report: files per task; routes with their middleware; config and environment variables added; migrations; command results with the test count; SERVICE_RULES rows implemented with test names; deviations; open questions; proposed TASKS.md ticks (only for verified tasks; show the diff first).
5. Do NOT push and do NOT open a pull request. Stop and wait.

## Starting a later chat
Paste the Session Starter, then: "RESUME: phase N is in progress on branch X. Follow docs/PHASE_RULES.md. Before planning, read DECISIONS.md, run git log --oneline -20 and list the existing migrations and tests. Continue from TASK-0xx."
