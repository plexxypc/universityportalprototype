# Development Rules

These rules apply to every contributor and every AI coding session on this project.

## Session routine (for AI assistants)

**Start of every session**
1. Read `MEMORY.md` (current state) and the current task in `TASKS.md`.
2. Read the parts of `PRD.md`, `ARCHITECTURE.md`, `DESIGN.md` and `SECURITY.md` relevant to the task.
3. Check `DECISIONS.md` before proposing any change to technology or structure.

**End of every session**
1. Mark finished tasks in `TASKS.md`.
2. Update `MEMORY.md` (status, current task, known issues, next step).
3. If a significant technical decision was made, add an ADR to `DECISIONS.md`.

Work on **one task at a time**: `TASK-nnn → implement → test → review → mark complete → next`. Never build the whole application in one prompt.

## General

- Use PHP 8.4+ with `declare(strict_types=1);` in new files and full type declarations on parameters, returns and properties.
- Follow Laravel conventions and the structure in `ARCHITECTURE.md`; prefer framework features over new packages.
- Reuse existing classes, components and helpers; search before creating.
- Do not duplicate logic. Extract shared logic into Services or `app/Support`.
- Keep methods small and single-purpose; use pure functions for calculations (grading, GPA, money).
- Do not modify unrelated files. Keep each change focused on its task.
- Do not add a dependency without a clear reason and a note in `DECISIONS.md` if it is significant.
- Do not change decisions in `DECISIONS.md` without adding a new ADR that supersedes the old one.
- **Do not add any CBT-engine integration, link, client, route or environment variable** (ADR-004).
- Comments explain *why*, not *what*. Use descriptive names; avoid abbreviations except those in the PRD (matric, CA, GPA, CGPA).

## Before coding

- Read the relevant project documentation and inspect the existing implementation.
- Reuse existing functionality where possible.
- Write a short plan for any change touching more than three files or any migration.
- Confirm the task's acceptance criteria and the `TEST_PLAN.md` items it affects.

## Architecture

- Follow the layer rules in `ARCHITECTURE.md` §3 and §5.
- Views, Livewire components and Filament resources contain no business logic. Anything that creates, changes, approves, publishes, pays or imports goes through a **Service**.
- Controllers, Livewire actions and Filament actions are thin: validate → authorise → call service → return.
- Multi-step writes use `DB::transaction()` inside the service; use `lockForUpdate()` for money, matric numbers and result publication.
- Migrations only for schema changes; never edit a migration already applied to the demo or production database; every new table needs sensible indexes and foreign keys.
- Money is unsigned integer kobo; use `App\Support\Money` for display; never use floats for currency.
- Use enums for statuses and roles; no magic strings.
- Use Eloquent with eager loading; avoid N+1 queries; paginate lists.

## UI

- Follow `DESIGN.md` (colours, typography, components, tone).
- Reuse Blade components and shared Filament helpers; do not restyle primitives per page.
- Maintain responsive design (375px, 768px, 1440px).
- Every data view has a **loading state**, an **empty state** and an **error state**.
- Forms are accessible: labels, error association, keyboard operation, visible focus.
- Destructive or irreversible actions require a confirmation dialog.
- No hard-coded university name, logo or colours outside design tokens and institution settings.

## Security

- Never expose secrets; never commit `.env`; never log passwords, tokens or keys.
- Validate all input on the server (Form Requests / Livewire rules), including query parameters, uploaded files and imported rows.
- Authorise every action and every route that takes an id; never take a student id from request input in student-facing code.
- Do not use `{!! !!}` or raw SQL with user input.
- Never trust a payment redirect, callback or notification body. Always re-query the provider (Remita, Interswitch or Demo) through the `PaymentGateway` interface and check status, amount, currency and reference before crediting. Payments may stay Pending; the poller and re-verify action handle that.
- Follow `SECURITY.md` for uploads, imports, exports and headers.

## Data and imports

- Imports validate every row, report row-level errors, and are safe to re-run (idempotent on email and matric number).
- CSV/Excel exports use `CsvSafe` to neutralise formula injection.
- Seed and demo data are fictional only.

## Testing

- Add Pest tests for important functionality: grading, GPA/CGPA, credit limits, matric generation, import validation, payment settlement, policies and cross-student isolation.
- Run Pint, static analysis and the test suite after each task; fix failures before continuing.
- Bug fixes include a regression test where practical.
- Tick the relevant `TEST_PLAN.md` items before marking a task complete.

## Git

- Work on a branch per phase or task group; merge to `main` only when tests pass.
- Make small commits with descriptive messages: `type(scope): summary`, e.g. `feat(imports): add Google Sheet source`.
- Types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`. Reference the task id in the body, e.g. `TASK-042`.
- Never commit secrets, `vendor/`, `node_modules/` or build output.

## Cursor rules

The same rules are available as Cursor rule files in `cursor-rules/` (copy into `.cursor/rules/`):

```
.cursor/
└── rules/
    ├── general.mdc
    ├── frontend.mdc
    ├── backend.mdc
    └── testing.mdc
```
