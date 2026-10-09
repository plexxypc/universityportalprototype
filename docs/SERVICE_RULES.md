# Service rules

Each rule must be implemented, and tested, by the task named.

These rules cannot be a database constraint. They come from the Phase 3 reports. Step 0 listed none. Step 7 added none.

| Step | Rule | Task | Task description |
|---|---|---|---|
| 1 | Which roles require a faculty, which require a department, and which must leave both empty | TASK-037 | `Role` enum, `Permissions` map (PRD §5 matrix), `role_assignments` scopes, `Gate::before` for Super Admin |
| 1 | Lecturer scope is `course_assignments` | TASK-136 | Lecturer–course assignments per semester |
| 1, 2 | When a role assignment sets both scope ids, the department belongs to that faculty | TASK-135 | Role assignment with faculty/department scope |
| 1 | Phone normalised to `+234` | TASK-075 | Row validation engine: required fields, formats, programme/level existence, duplicate email/matric (in file and in database), per-row error table |
| 1 | `staff_no` pattern | TASK-132 | `StaffService`: create/update with credentials (reuse `CredentialService`) |
| 1 | `must_change_password` and `temp_password_expires_at` are set together | TASK-065 | `CredentialService`: temporary password, expiry, welcome email, resend credentials |
| 1 | Block login when the temporary password has expired | TASK-039 | Login page and login action (`it blocks login when the temporary password has expired`) |
| 1 | Deactivating a staff profile deactivates the user | TASK-137 | Activate/deactivate staff |
| 1 | Deactivating a staff profile revokes sessions | TASK-137 | Activate/deactivate staff |
| 2 | Switching the current session clears the old row first, inside a transaction | TASK-055 | Academic sessions resource (single current session) |
| 2 | Switching the active semester clears the old row first, inside a transaction | TASK-056 | Semesters resource (single active semester, registration and add/drop deadlines) |
| 2 | Reads fall back to `config/portal.php` while `institution_settings` is empty | TASK-054 | Institution settings page (profile, logo upload, matric pattern, unit limits, approval toggle, deadlines, attendance threshold, withhold-results toggle) |
| 2 | A non-empty session list contains a current session. The flag only guarantees at most one | TASK-055 | Academic sessions resource (single current session) |
| 2 | A non-empty semester list contains an active semester. The flag only guarantees at most one | TASK-056 | Semesters resource (single active semester, registration and add/drop deadlines) |
| 2 | The active semester belongs to the current session | TASK-056 | Semesters resource (single active semester, registration and add/drop deadlines) |
| 2 | Session names match `2026/2027` | TASK-055 | Academic sessions resource (single current session) |
| 2 | Prerequisite cycles. A two-course cycle is stored | TASK-058 | Course catalogue resource with prerequisites and programme–course mapping |
| 2 | A closed list of degree types | TASK-057 | Faculties, departments and programmes resources with scope rules |
| 2 | What payment satisfies `require_minimum_payment` | TASK-094 | Optional "minimum payment before registration" setting |
| 3 | `users.name` is set from the student's three name parts | TASK-064 | `StudentService`: create/update student with atomic matric generation (`counters` + `lockForUpdate`) |
| 3 | On conversion, copy the applicant onto the new user and student | TASK-080 | Applicants: admit and convert to student (single and bulk), admission email |
| 3 | Reject an applicant email that already exists on `users` | TASK-075 | Row validation engine: required fields, formats, programme/level existence, duplicate email/matric (in file and in database), per-row error table |
| 3 | Default a blank entry session to the current session | TASK-064 | `StudentService`: create/update student with atomic matric generation (`counters` + `lockForUpdate`) |
| 3, 6 | Generate `matric_no` from `counters` with `lockForUpdate` | TASK-064 | `StudentService`: create/update student with atomic matric generation (`counters` + `lockForUpdate`) |
| 3 | A document's owner row exists. Deleting an applicant or student is refused, or the documents are removed first | TASK-070 | Guardians form and secure document upload (type, size, filename checks; private disk; authorised download) |
| 3 | Document type and the 5 MB limit, on the local disk | TASK-070 | Guardians form and secure document upload (type, size, filename checks; private disk; authorised download) |
| 3 | Registration minimum and maximum units | TASK-088 | `RegistrationService`: eligible courses, unit limits, prerequisites, deadline rules |
| 3 | Registration prerequisites | TASK-088 | `RegistrationService`: eligible courses, unit limits, prerequisites, deadline rules |
| 3 | Registration deadline lock | TASK-088 | `RegistrationService`: eligible courses, unit limits, prerequisites, deadline rules |
| 3 | Auto-approve when approval is off | TASK-091 | Approval queues for department and faculty (approve/reject with reason); auto-approve when approval is disabled |
| 3 | Copy `credit_units` onto the item at registration time | TASK-090 | Submit, status handling, confirmation email and notification |
| 3 | `total_units` equals the sum of the items | TASK-090 | Submit, status handling, confirmation email and notification |
| 3 | Import commits in chunks of 25, with duplicate skip or update | TASK-076 | Chunked commit (25 rows per request) with progress bar, duplicate options (skip/update), send-emails now/later; `import_batches` records |
| 3 | Import failure report | TASK-077 | Import report page and downloadable failed-rows CSV; audit entry per batch |
| 4 | Item lines sum to `total_kobo` | TASK-096 | Invoice generation: single and bulk by programme/level/session, with preview and confirm |
| 4 | Adjustments sum to `adjustments_kobo` | TASK-097 | Adjustments: scholarship, discount, waiver with reason and audit |
| 4 | Successful payments sum to `paid_kobo` | TASK-101 | `verifyAndSettle`: re-query, compare status/amount/currency/reference, idempotent transactional settlement with `lockForUpdate`, invoice status update, expiry handling |
| 4 | Invoice status is derived from those totals | TASK-101 | `verifyAndSettle`: re-query, compare status/amount/currency/reference, idempotent transactional settlement with `lockForUpdate`, invoice status update, expiry handling |
| 4 | A payment amount is at most the outstanding balance | TASK-100 | `PaymentService::initiate`: authorise, amount ≤ outstanding, pending payment with unique reference and expiry, store provider reference |
| 4 | A pending payment does not change `paid_kobo` | TASK-101 | `verifyAndSettle`: re-query, compare status/amount/currency/reference, idempotent transactional settlement with `lockForUpdate`, invoice status update, expiry handling |
| 4 | The payment's `student_id` is the invoice's student | TASK-100 | `PaymentService::initiate`: authorise, amount ≤ outstanding, pending payment with unique reference and expiry, store provider reference |
| 4 | Currency is NGN. It is not a column | TASK-101 | `verifyAndSettle`: re-query, compare status/amount/currency/reference, idempotent transactional settlement with `lockForUpdate`, invoice status update, expiry handling |
| 4 | A receipt is created only for a Successful payment, once, inside the settlement transaction | TASK-106 | Receipts: numbering, viewer and PDF |
| 4 | Payment events are insert-only | TASK-103 | Notification endpoint (outside CSRF, rate-limited, reference only, triggers re-query) and `payments:poll-pending` scheduled job with back-off |
| 4 | At most one non-cancelled invoice per student per session | TASK-096 | Invoice generation: single and bulk by programme/level/session, with preview and confirm |
| 4 | A reversal reduces `paid_kobo` and does not delete the payment | TASK-101 | `verifyAndSettle`: re-query, compare status/amount/currency/reference, idempotent transactional settlement with `lockForUpdate`, invoice status update, expiry handling |
| 4 | Invoice numbers, receipt numbers, and payment references are allocated from `counters` inside the creating transaction | TASK-096, TASK-100, TASK-106 | Invoice generation; payment reference on initiate; receipt numbering |
| 5 | Assessment components of one scheme total 100 | TASK-060 | Grading configuration UI: assessment components with max scores, grade bands, pass mark, classification bands, standing thresholds, repeat policy; overlap/gap/total validation; default-scheme loader; scheme versioning. Add the academic-standing threshold table (warning, probation) in this task |
| 5 | Grade bands cover 0–100 with no gaps and no overlap | TASK-060 | Grading configuration UI, including overlap/gap/total validation |
| 5 | A score is not above that component's maximum | TASK-111 | `ResultService`: draft results with component scores validated against configured maximums |
| 5 | Published results are locked | TASK-114 | Workflow: submit → approve → publish, with role checks and locking on publish |
| 5 | Amendments are audited and re-published | TASK-115 | Amendment process for published results (audited, re-publish) |
| 5 | A result score's component belongs to the result's scheme | TASK-111 | `ResultService`: draft results with component scores validated against configured maximums |
| 5 | `scheme_version` matches the linked scheme row | TASK-111 | `ResultService`: draft results with component scores validated against configured maximums |
| 5 | Editing the active scheme inserts a new version and repoints unpublished results. Published rows keep their scheme id | TASK-060 | Grading configuration UI, including scheme versioning |
| 5 | When unpublished results move, `result_scores` are remapped to the new version's components in that transaction | TASK-060 | Grading configuration UI, including scheme versioning |
| 5 | GPA uses the latest or best attempt and applies `resit_points_cap` | TASK-059 | `GradingService` pure functions: component totals, grade lookup, points, GPA, CGPA, classification, repeat-course policy, with Pest unit tests |
| 5 | Classification bands do not overlap or leave gaps | TASK-060 | Grading configuration UI, including classification bands and overlap/gap/total validation |
| 5 | The pass mark agrees with the fail band | TASK-060 | Grading configuration UI: assessment components with max scores, grade bands, pass mark, classification bands, standing thresholds, repeat policy; overlap/gap/total validation |
| 5 | Stored total, grade, and points match the scores and the scheme | TASK-059 | `GradingService` pure functions: component totals, grade lookup, points, GPA, CGPA, classification, repeat-course policy, with Pest unit tests |
| 5 | Switching the active grading scheme clears the previous row inside a transaction | TASK-060 | Grading configuration UI, including scheme versioning |
| 5 | A decimal total is rounded before the grade lookup, or bands are lower-bound inclusive | TASK-059 | Decide here whether a decimal total is rounded to a whole number before the grade lookup, or bands are lower-bound inclusive |
| 5 | Academic standing thresholds (warning, probation) need their own table | TASK-060 | Add the academic-standing threshold table (warning, probation) in this task |
| 6 | The marked student is registered for that course in that semester | TASK-122 | `AttendanceService` (sessions, records, percentages, threshold) |
| 6 | Attendance percentage and `attendance_threshold` | TASK-122 | `AttendanceService` (sessions, records, percentages, threshold) |
| 6 | A lecturer marks only assigned courses | TASK-122 | `AttendanceService` (sessions, records, percentages, threshold) |
| 6 | Exam clashes for the same venue and overlapping time, and for the same level or programme | TASK-127 | `ExamService` with venue and level/programme clash detection |
| 6 | A student sees only exams for registered courses | TASK-129 | Student timetable (registered courses only) |
| 6 | Publishing a timetable creates the notification and the outbox row | TASK-130 | Publish notification/email and timetable PDF |
| 6 | Publishing an announcement creates the notification and, when `send_email` is true, the outbox row | TASK-139 | `AnnouncementService` with audience targeting (all, faculty, department, programme, level) |
| 6 | Which accounts match an audience, including an institution-wide level | TASK-139 | `AnnouncementService` with audience targeting (all, faculty, department, programme, level) |
| 6 | Allowed notification types | TASK-052 | `NotificationService` and bell with unread count (staff and student layouts) |
| 6 | Allowed email template names | TASK-048 | Email templates: base layout plus welcome/credentials, password reset, admission, registration confirmation, payment confirmation/receipt, result published, announcement, timetable published |
| 6 | Outbox retry limits | TASK-047 | `MailService` with outbox recording, `SendOutboxEmail` job, retry rules; Brevo mailer configuration and `log` mailer |
| 6 | Daily send limit | TASK-049 | Scheduler: `outbox:send` every minute with batch limit and daily-limit awareness |
| 6 | A credentials email is redacted when it is sent, when attempts are exhausted, or when credentials are re-issued | TASK-047 | `MailService` with outbox recording, `SendOutboxEmail` job, retry rules; Brevo mailer configuration and `log` mailer |
| 6 | The password stays out of subject, `last_error`, and application logs | TASK-047 | `MailService` with outbox recording, `SendOutboxEmail` job, retry rules; Brevo mailer configuration and `log` mailer |
| 6 | Only the audit service inserts. Nothing updates or deletes `audit_logs` | TASK-046 | `AuditService` and audit helper used by every service |
| 6 | Secrets are removed from audit `before` and `after` | TASK-046 | `AuditService` and audit helper used by every service |
| 6 | Only a Super Admin reads audit logs | TASK-149 | Audit log viewer: filter by actor, entity, action, date; export |
| 6 | Database-level append-only protection for `audit_logs` | Phase 19 intro (no task id) | Revisit database-level protection once the production host is chosen |
