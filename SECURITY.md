# Security Requirements

> Security is designed in from the first task, not added before deployment. This portal handles personal data, credentials and payments.

## 1. Principles

- Deny by default; grant each role the minimum access it needs.
- Verify on the server. Client-side checks are usability features, not controls.
- Never trust input, redirects, webhooks, imported files or uploaded files.
- Because there is no database-level row security, **application isolation is tested relentlessly** (TEST_PLAN §2).
- Treat all student data as personal data (Nigeria Data Protection Act 2023). Demo and test environments use **fictional data only**.

## 2. Authentication

- Private routes require an authenticated session (`auth` middleware) plus per-action policy checks.
- Passwords are hashed by Laravel (bcrypt/argon2); the portal never stores or logs plaintext passwords.
- **Temporary passwords:** 12 characters from a cryptographically secure generator; valid 7 days; must be changed at first login; the user cannot reach any other page until changed; re-issuing invalidates the previous one. The stored outbox copy of the welcome email is redacted after sending.
- Login accepts matric number or email; throttled by IP and identifier with `RateLimiter`; the failure message is always generic ("Invalid credentials").
- Password policy: minimum 10 characters, not equal to matric number or email, not equal to the temporary password; check against common-password list.
- Password reset: single-use tokens expiring in 60 minutes; the response never reveals whether an account exists.
- Suspended or deactivated accounts cannot sign in and their sessions are revoked.
- Session fixation: regenerate the session on login; invalidate on logout; changing the password signs out other sessions.
- Cookies: HttpOnly, Secure (production), SameSite=Lax.
- Optional TOTP two-factor for staff (stretch task).

**Known trade-off:** emailing a temporary password is a product-owner requirement. Forced change and expiry limit exposure. Admins must never be able to view a student's password after creation.

## 3. Authorisation

- Roles and scope are defined in PRD §5 and implemented with the Role enum, permission map and Policies.
- Every controller, Livewire action, Filament action and route authorises the specific record before doing work.
- Students: only their own profile, invoices, payments, registrations, results (published only), attendance, documents, receipts.
- Faculty Admin / Department Officer: own faculty / department. Lecturer: assigned courses only.
- **Insecure direct object references:** never accept an id from the client without authorising the loaded model, including PDF, receipt and document download routes.
- Livewire: public component properties are client-controlled. Never trust a public property holding an id; re-authorise in each action and lock sensitive properties.
- Role changes and credential re-issues are audited.

## 4. Database

- Use Eloquent / the query builder with bound parameters. Never concatenate user input into SQL; limit `DB::raw` to reviewed constant fragments.
- Use `$fillable` allow-lists (no unguarded mass assignment) and form-request validated data only.
- Constraints enforce integrity: unique matric number, email, payment reference, webhook event id; `CHECK` constraints on money; foreign keys with sensible delete rules.
- Atomic operations (payment settlement, matric generation, invoice generation) run in `DB::transaction()` with `lockForUpdate()` where needed.
- Connect over TLS (Aiven requires it); the application database user has only the privileges it needs (no DROP/ALTER at runtime in production where practical; run migrations with a separate credential).
- Restrict database network access to the application host(s) using the provider's allow-list.
- Backups: provider automated backups (verify retention on the chosen plan) plus the in-app export (ADMIN-4). Test a restore before go-live.
- Exports contain personal data and are Super Admin only.

## 5. Secrets and configuration

- Secrets live only in environment variables (host dashboard, local `.env`); never in the repository, logs, client-side code, screenshots or documentation.
- `.env` and credential files are git-ignored; secret scanning is enabled on the GitHub repository.
- `APP_DEBUG=false` and `APP_ENV=production` outside local development; debug pages never reach users.
- Separate keys and databases for local, demo and production; rotate any key that is ever exposed.
- Payment provider **demo/sandbox/test** credentials only until a deliberate go-live decision; live merchant credentials are stored only in the production host's environment.
- Config is validated on boot in non-local environments; missing required values fail fast.

## 6. Payments

- Payment status changes only after **server-side verification (re-query) with the provider**. A redirect parameter, callback value or notification body is never enough.
- Provider notification endpoints (outside the CSRF group, in `routes/webhooks.php`) accept only a reference, are rate-limited, and merely trigger a re-query. Where a provider signs its notifications, the signature is verified as well, on the raw body.
- Compute and verify provider request hashes (for example Remita's API hash, Interswitch's requery hash where required) exactly as the provider documents; keep API keys and secrets server-side only.
- Verify status, amount, currency and reference against the stored payment before settling.
- Idempotency: unique internal reference and unique provider reference; settlement is a no-op if already successful; a row lock prevents double-credit across callback, poller and manual re-verify running at the same time.
- Payments may remain Pending (offline payment of a Remita RRR). They expire after a defined period and the poller stops; expiry never credits anything.
- A student can only start payments for their own invoices and amounts ≤ the outstanding balance; fixed-amount references (RRR) are never edited after creation.
- Show students only the real provider checkout; warn against fake payment pages (counterfeit "Remita" portals are a documented risk), and never ask for card details inside the portal.
- Store only references, statuses and amounts; never card data.
- Demo Gateway routes are disabled unless `PAYMENT_PROVIDER=demo`.
- Every payment state change and manual verification is audited, including its source (callback, notification, poll, manual).

## 7. Student import and bulk operations

- Staff-only permission; every batch audited with actor, source and counts.
- **File limits:** CSV/XLSX up to 5 MB and 5,000 rows; reject others. Validate extension and MIME type; parse server-side only.
- **Google Sheet source (SSRF protection):** accept only `https://docs.google.com/spreadsheets/d/<id>` URLs; extract the id and build the export URL on the server; never fetch a user-supplied URL directly; block redirects to other hosts; enforce timeout and response size cap; public "anyone with the link can view" sheets only; no Google credentials stored.
- Validate every row (types, lengths, formats, existence of programme/level) before any write.
- **CSV/Excel injection:** values starting with `=`, `+`, `-`, `@`, tab or carriage return are neutralised on export (`CsvSafe`) and escaped on display.
- Imports are idempotent on email and matric number; duplicates handled per chosen option.
- Do not log row contents in application logs; failed-row CSVs and credential emails are sensitive.

## 8. File uploads (documents, logos)

Validate:
- **File type:** allow-list (PDF, JPG, PNG), checked by content, not only extension.
- **File size:** maximum 5 MB (logo 1 MB).
- **Filename:** discard the client filename; store under a generated random name; sanitise display names.
- Store outside the public web root (private disk); serve through an authorised controller or short-lived signed URLs.
- Rate-limit uploads per user. Optional malware-scan hook noted for production.

## 9. Input validation and output encoding

- Validate all request bodies, query parameters, route parameters and Livewire inputs on the server; reject unexpected fields.
- Blade escapes output by default; never use `{!! !!}` with user data. Email and PDF templates escape all variables.
- Limit string lengths and numeric ranges.
- Redirect targets after login are relative and allow-listed (no open redirects).

## 10. Transport, headers and endpoints

- HTTPS only (host-provided TLS); HSTS enabled in production; trusted proxy configuration set correctly so HTTPS and client IPs are detected.
- Security headers via middleware: `Content-Security-Policy`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, `X-Frame-Options`/`frame-ancestors 'none'`.
- CSRF protection on all web routes (Laravel default); webhooks use signature verification instead.
- Rate limiting on login, password reset, import preview/commit, payment initiation and uploads.
- Responses do not leak stack traces, SQL errors or internal ids beyond need.
- `/health` returns coarse status only; no secrets or personal-data counts. Admin panel path is not the only protection; it is also gated by role and the optional second factor.

## 11. Email security

- Send from a verified sender (verified domain with SPF, DKIM and DMARC in production); no user-controlled headers or `from`.
- Escape all template variables; no raw user HTML.
- Credentials emails contain the matric number, temporary password and login link only.
- Links point only to the configured `APP_URL`.

## 12. Logging, audit and monitoring

- Audit log (append-only through the application) records: credential re-issues, imports, role changes, grading/settings changes, invoice adjustments, payment status changes and manual verifications, result entry/approval/publication/amendment, student status changes, exports.
- Each entry: actor, action, entity, before/after (secrets redacted), IP, timestamp.
- Application logs never contain passwords, tokens, keys, full webhook bodies or document contents.
- Audit viewing is Super Admin only.
- Production: error tracking and uptime monitoring with alerts to a named owner.

## 13. Data protection (NDPA 2023)

- Collect only data needed for admissions, academics and finance; restrict by role and scope; log exports.
- Provide a way to correct a student's data (registrar edit) and to remove or anonymise fictional/test data.
- Do not use real student data in local, demo or test environments.
- Define retention and deletion with the institution before production.
- Identify the data controller and breach procedure before production.
- **Hosting location:** hosting student records outside Nigeria may have cross-border transfer implications. Obtain legal or data-protection-officer advice before choosing the production region (SYSTEM_OVERVIEW.md §6).

## 14. Dependencies and supply chain

- Commit `composer.lock` and `package-lock.json`; enable Dependabot or equivalent.
- Run `composer audit` and `npm audit` in CI; no high/critical issues at release.
- Keep Laravel, Filament and PHP on supported versions; review any package handling files, auth or payments.

## 15. Hosting-specific notes

- Free tiers (Aiven free MySQL, Render free, App Platform with credits) are acceptable for demo/testing with fictional data. They are **not** a production commitment: limited resources, no uptime guarantees, possible power-offs or sleep, ephemeral file storage.
- Move to paid, always-on hosting with a high-availability database, object storage, a custom domain and a verified email domain before any real student data is stored.

## 16. Incident response (prototype level)

1. Rotate affected keys (database, payment provider credentials, Brevo, `APP_KEY` if exposed).
2. Switch off affected features through environment settings (e.g. disable online payment or set `PAYMENT_PROVIDER=demo` in non-production, `MAIL_MAILER=log`).
3. Review audit logs and payment events for the affected window.
4. Record the cause and fix in DECISIONS.md or MEMORY.md.

## 17. Release checklist

- [ ] No secrets in repository history or deployed assets
- [ ] Cross-student and cross-scope access tests pass for every model
- [ ] Re-query verification and idempotency tests pass (callback, notification, poller, manual)
- [ ] Upload and import limits verified
- [ ] Security headers verified on the deployed URL
- [ ] Rate limits verified
- [ ] `APP_DEBUG=false`; database over TLS
- [ ] Demo data is fictional only
- [ ] `composer audit` / `npm audit` clean
- [ ] Payment provider in demo/sandbox mode; no live credentials deployed (until go-live decision)
- [ ] Forged callback/notification test passes (cannot mark a payment paid)
