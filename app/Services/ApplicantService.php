<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApplicantStatus;
use App\Enums\EmailTemplate;
use App\Enums\Gender;
use App\Enums\ImportSource;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\EmailOutbox;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Policies\ApplicantPolicy;
use App\Support\Audit;
use App\Support\PersonalAudit;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds applicants, moves them through the status pipeline, and converts them.
 *
 * Profile audits list changed field names for email, phone, and date of
 * birth. A refused transition stores the applicant id and the two statuses.
 * Admit-and-convert copies the applicant onto one user and one student.
 */
final class ApplicantService
{
    public const string ACTION_CREATED = 'applicants.created';

    public const string ACTION_UPDATED = 'applicants.updated';

    public const string ACTION_STATUS = 'applicants.status_changed';

    public const string ACTION_REFUSED = 'applicants.transition_refused';

    public const string RELATED_TYPE = 'applicants';

    public const string MESSAGE_MISSING = 'That applicant was not found.';

    public const string MESSAGE_EMAIL_TAKEN = 'An account with this email already exists.';

    public const string MESSAGE_APPLICANT_EMAIL = 'An applicant with this email already exists.';

    public const string MESSAGE_TRANSITION = 'That status change is not allowed.';

    public const string MESSAGE_REASON = 'Enter a reason for this rejection.';

    public const string MESSAGE_CONVERTED = 'This applicant is already a student.';

    public const string MESSAGE_EMAIL_CHANGE = 'Update cannot change the email address.';

    public const string ACTION_CONVERTED = 'applicants.converted';

    public const string MESSAGE_LOG_MAILER = 'Bulk conversion cannot send welcome emails with the log mailer.';

    public const int CHUNK_SIZE = 25;

    /**
     * Statuses a manager may set from each current status.
     *
     * Converted is not in this map. Admit-and-convert is a separate action.
     *
     * @var array<string, list<string>>
     */
    private const array TRANSITIONS = [
        'Applied' => ['Under review', 'Admitted', 'Rejected'],
        'Under review' => ['Admitted', 'Rejected'],
        'Admitted' => ['Rejected'],
        'Rejected' => [],
        'Converted' => [],
    ];

    /**
     * Personal fields stored as names only.
     *
     * @var list<string>
     */
    private const array PERSONAL_FIELDS = [
        'first_name',
        'last_name',
        'other_names',
        'email',
        'phone',
        'gender',
        'date_of_birth',
        'state_of_origin',
        'address',
    ];

    /**
     * Fields whose values may be stored.
     *
     * @var list<string>
     */
    private const array VALUE_FIELDS = [
        'status',
        'level',
        'programme_id',
        'entry_session_id',
        'source',
    ];

    public function __construct(
        private readonly StudentService $students,
        private readonly MailService $mail,
        private readonly Audit $audit,
    ) {}

    /**
     * Create one applicant.
     *
     * A blank entry session uses the current academic session. An email that
     * already belongs to a user is refused.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input, Request $request, ?int $import_batch_id = null): Applicant
    {
        $this->authoriseManage($actor);

        return DB::transaction(function () use ($actor, $input, $request, $import_batch_id): Applicant {
            $this->authoriseManage($actor);
            $parsed = $this->parsed($input, null);
            $this->assertEmailFree($parsed['email'], null);
            $applicant = new Applicant;
            $this->fill($applicant, $parsed);
            $applicant->status = ApplicantStatus::Applied;
            $applicant->source = $parsed['source'];
            $applicant->import_batch_id = $import_batch_id;
            $this->saveApplicant($applicant);
            $diff = PersonalAudit::diff(
                array_fill_keys(array_keys($this->snapshot($applicant, $parsed)), null),
                $this->snapshot($applicant, $parsed),
                self::PERSONAL_FIELDS,
                self::VALUE_FIELDS,
            );
            $this->audit->record(
                $actor->id,
                self::ACTION_CREATED,
                'applicants',
                (int) $applicant->getKey(),
                $diff['before'],
                $diff['after'],
                $this->audit->ipFromRequest($request),
            );

            return $applicant->refresh();
        });
    }

    /**
     * Update profile fields on an applicant who has not been converted.
     *
     * The email cannot change. Status is ignored here.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, int $applicant_id, array $input, Request $request): Applicant
    {
        return DB::transaction(function () use ($actor, $applicant_id, $input, $request): Applicant {
            $applicant = $this->locked($actor, $applicant_id);

            if ($applicant->status === ApplicantStatus::Converted) {
                throw ValidationException::withMessages(['applicant' => self::MESSAGE_CONVERTED]);
            }

            $before = $this->snapshot($applicant, null);
            $parsed = $this->parsed($input, $applicant);

            if ($parsed['email'] !== mb_strtolower((string) $applicant->email)) {
                throw ValidationException::withMessages(['email' => self::MESSAGE_EMAIL_CHANGE]);
            }

            $this->fill($applicant, $parsed);
            $this->saveApplicant($applicant);
            $diff = PersonalAudit::diff($before, $this->snapshot($applicant, $parsed), self::PERSONAL_FIELDS, self::VALUE_FIELDS);
            $this->audit->record(
                $actor->id,
                self::ACTION_UPDATED,
                'applicants',
                (int) $applicant->getKey(),
                $diff['before'],
                $diff['after'],
                $this->audit->ipFromRequest($request),
            );

            return $applicant->refresh();
        });
    }

    /**
     * Move an applicant along the allowed pipeline.
     *
     * The first move to Admitted queues one admission email. A repeated or
     * invalid move is stored as a refusal and the row stays as it was.
     * Rejection stores the reason on the audit row.
     */
    public function changeStatus(User $actor, int $applicant_id, string $status, string $reason, Request $request): Applicant
    {
        $refused = false;

        $applicant = DB::transaction(function () use ($actor, $applicant_id, $status, $reason, $request, &$refused): Applicant {
            $locked = $this->locked($actor, $applicant_id);
            $next = ApplicantStatus::tryFrom(trim($status));
            $current = $locked->status;

            if (! $next instanceof ApplicantStatus
                || $next === ApplicantStatus::Converted
                || ! in_array($next->value, self::TRANSITIONS[$current->value], true)) {
                $this->auditRefusal($actor, $locked, $next instanceof ApplicantStatus ? $next : null, $request);
                $refused = true;

                return $locked;
            }

            $rejection = $next === ApplicantStatus::Rejected ? $this->reason($reason) : null;
            $before = ['status' => $current->value];
            $locked->status = $next;
            $locked->save();

            if ($next === ApplicantStatus::Admitted) {
                $this->queueAdmission($locked, null);
            }

            $after = ['status' => $next->value];

            if ($rejection !== null) {
                $after['reason'] = $rejection;
            }

            $this->audit->record(
                $actor->id,
                self::ACTION_STATUS,
                'applicants',
                (int) $locked->getKey(),
                $before,
                $after,
                $this->audit->ipFromRequest($request),
            );

            return $locked->refresh();
        });

        if ($refused) {
            throw ValidationException::withMessages(['status' => self::MESSAGE_TRANSITION]);
        }

        return $applicant;
    }

    /**
     * Whether an admission email already exists for this applicant.
     */
    public function admissionQueued(int $applicant_id): bool
    {
        return EmailOutbox::query()
            ->where('template', EmailTemplate::Admission->value)
            ->where('related_type', self::RELATED_TYPE)
            ->where('related_id', $applicant_id)
            ->exists();
    }

    /**
     * Queue the admission email once for this applicant.
     *
     * The body is the public admission template. It has no password.
     */
    public function queueAdmission(Applicant $applicant, ?int $user_id): void
    {
        if ($this->admissionQueued((int) $applicant->getKey())) {
            return;
        }

        $applicant->loadMissing('programme');
        $programme = $applicant->programme;
        $this->mail->send(
            EmailTemplate::Admission->value,
            (string) $applicant->email,
            [
                'name' => $this->displayName($applicant),
                'programme' => $programme instanceof Programme ? (string) $programme->name : '',
            ],
            $user_id,
            self::RELATED_TYPE,
            (int) $applicant->getKey(),
        );
    }

    /**
     * Admit an applicant and create the student account in one transaction.
     *
     * Applied, Under review, and Admitted can convert. The admission email
     * is queued only when this applicant has not already received one.
     * A second convert writes a refusal and does not create another account.
     */
    public function convert(User $actor, int $applicant_id, Request $request): CreatedStudent
    {
        $refused_message = null;

        $created = DB::transaction(function () use ($actor, $applicant_id, $request, &$refused_message): ?CreatedStudent {
            $locked = $this->locked($actor, $applicant_id);

            if (! $this->canConvert($locked->status)) {
                $this->auditRefusal($actor, $locked, ApplicantStatus::Converted, $request);
                $refused_message = $locked->status === ApplicantStatus::Converted
                    ? self::MESSAGE_CONVERTED
                    : self::MESSAGE_TRANSITION;

                return null;
            }

            $before_status = $locked->status->value;
            $created_student = $this->students->create(
                $actor,
                $this->studentInput($locked),
                $request,
                true,
                $locked->import_batch_id !== null ? (int) $locked->import_batch_id : null,
            );
            $student = $created_student->student;
            $this->linkApplicant($student, (int) $locked->getKey());
            $locked->status = ApplicantStatus::Converted;
            $locked->save();
            $this->queueAdmission($locked, (int) $student->user_id);
            $id = (int) $locked->getKey();
            $this->audit->record(
                $actor->id,
                self::ACTION_CONVERTED,
                'applicants',
                $id,
                [
                    'applicant_id' => $id,
                    'status' => $before_status,
                ],
                [
                    'applicant_id' => $id,
                    'status' => ApplicantStatus::Converted->value,
                    'student_id' => (int) $student->getKey(),
                ],
                $this->audit->ipFromRequest($request),
            );
            $fresh = $student->fresh(['user', 'programme.department']);

            return new CreatedStudent($fresh instanceof Student ? $fresh : $student, $created_student->delivery);
        });

        if (! $created instanceof CreatedStudent) {
            throw ValidationException::withMessages([
                'applicant' => $refused_message ?? self::MESSAGE_TRANSITION,
            ]);
        }

        return $created;
    }

    /**
     * Eligible applicant ids for a bulk conversion. This does not write.
     *
     * Ids that are missing, out of scope, or not in a convertible status
     * are counted as skipped. The caller cannot tell those cases apart.
     *
     * @param  array<int, mixed>  $applicant_ids
     * @return array{eligible: list<int>, skipped: int}
     */
    public function preview(User $actor, array $applicant_ids): array
    {
        $this->authoriseManage($actor);
        $ids = $this->idList($applicant_ids);

        if ($ids === []) {
            return ['eligible' => [], 'skipped' => 0];
        }

        $convertible = [];

        foreach (Applicant::query()->visibleTo($actor)->whereKey($ids)->get() as $applicant) {
            if ($this->canConvert($applicant->status)) {
                $convertible[(int) $applicant->getKey()] = true;
            }
        }

        $eligible = [];

        foreach ($ids as $id) {
            if (isset($convertible[$id])) {
                $eligible[] = $id;
            }
        }

        return [
            'eligible' => $eligible,
            'skipped' => count($ids) - count($eligible),
        ];
    }

    /**
     * The confirmation sentence for a bulk conversion.
     *
     * @param  array<int, mixed>  $applicant_ids
     */
    public function previewMessage(User $actor, array $applicant_ids): string
    {
        $plan = $this->preview($actor, $applicant_ids);
        $count = count($plan['eligible']);
        $noun = $count === 1 ? 'applicant' : 'applicants';

        return $count.' '.$noun.' will be admitted and converted. '.$plan['skipped'].' will be skipped.';
    }

    /**
     * Convert each eligible applicant in its own transaction, 25 at a time.
     *
     * Production with the log mailer is refused before any applicant is
     * locked or written. A forged id is dropped by preview.
     *
     * @param  array<int, mixed>  $applicant_ids
     */
    public function convertMany(User $actor, array $applicant_ids, Request $request): ConversionBatch
    {
        $this->authoriseManage($actor);

        if ($this->mail->refusesSecretTemplate(EmailTemplate::Credentials->value)) {
            throw ValidationException::withMessages(['credentials' => self::MESSAGE_LOG_MAILER]);
        }

        $plan = $this->preview($actor, $applicant_ids);
        $converted = 0;
        $failures = [];

        foreach (array_chunk($plan['eligible'], self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $id) {
                try {
                    $this->convert($actor, $id, $request);
                    $converted++;
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first();
                    $failures[] = is_string($message) ? $message : self::MESSAGE_TRANSITION;
                }
            }
        }

        return new ConversionBatch($converted, $plan['skipped'], $failures);
    }

    /**
     * The on-screen result for one conversion. It matches the add-student sentence.
     */
    public function createdMessage(CreatedStudent $created): string
    {
        $matric = $created->student->matric_no;
        $email = match ($created->delivery->state) {
            CredentialDelivery::SENT => 'The welcome email was sent.',
            CredentialDelivery::NOT_SENT => 'The welcome email was not sent.',
            default => 'The welcome email is queued.',
        };

        return 'Student '.$matric.' was created. '.$email;
    }

    /**
     * The on-screen result for a bulk conversion.
     */
    public function bulkMessage(ConversionBatch $batch): string
    {
        $converted_noun = $batch->converted === 1 ? 'applicant was converted' : 'applicants were converted';
        $message = $batch->converted.' '.$converted_noun.'. '.$batch->skipped.' skipped.';
        $failure_count = count($batch->failures);

        if ($failure_count > 0) {
            $message .= ' '.$failure_count.' could not be converted.';
        }

        return $message;
    }

    /**
     * Applied, Under review, and Admitted may become students.
     */
    private function canConvert(ApplicantStatus $status): bool
    {
        return in_array($status, [
            ApplicantStatus::Applied,
            ApplicantStatus::UnderReview,
            ApplicantStatus::Admitted,
        ], true);
    }

    /**
     * @param  array<int, mixed>  $applicant_ids
     * @return list<int>
     */
    private function idList(array $applicant_ids): array
    {
        $ids = [];

        foreach ($applicant_ids as $applicant_id) {
            if (is_numeric($applicant_id) && (int) $applicant_id > 0) {
                $ids[] = (int) $applicant_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, mixed>
     */
    private function studentInput(Applicant $applicant): array
    {
        return [
            'first_name' => $applicant->first_name,
            'last_name' => $applicant->last_name,
            'other_names' => $applicant->other_names,
            'email' => $applicant->email,
            'phone' => $applicant->phone,
            'gender' => $applicant->gender instanceof Gender ? $applicant->gender->value : null,
            'date_of_birth' => $applicant->date_of_birth?->toDateString(),
            'state_of_origin' => $applicant->state_of_origin,
            'address' => $applicant->address,
            'programme_id' => (int) $applicant->programme_id,
            'level' => (int) $applicant->level,
            'entry_session_id' => $applicant->entry_session_id,
        ];
    }

    /**
     * Point the new student at this applicant. A duplicate link rolls the account back.
     */
    private function linkApplicant(Student $student, int $applicant_id): void
    {
        try {
            $student->applicant_id = $applicant_id;
            $student->save();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23000' && str_contains($exception->getMessage(), 'students_applicant_id_unique')) {
                throw ValidationException::withMessages(['applicant' => self::MESSAGE_CONVERTED]);
            }

            throw $exception;
        }
    }

    /**
     * Refuse a caller who cannot manage applicants.
     */
    private function authoriseManage(User $actor): void
    {
        if (! app(ApplicantPolicy::class)->create($actor)) {
            throw new AuthorizationException;
        }
    }

    /**
     * A manageable applicant row, locked for the write.
     */
    private function locked(User $actor, int $applicant_id): Applicant
    {
        $this->authoriseManage($actor);
        $applicant = Applicant::query()->visibleTo($actor)->whereKey($applicant_id)->lockForUpdate()->first();

        if (! $applicant instanceof Applicant) {
            throw ValidationException::withMessages(['applicant' => self::MESSAGE_MISSING]);
        }

        if (! app(ApplicantPolicy::class)->update($actor, $applicant)) {
            throw new AuthorizationException;
        }

        return $applicant;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     first_name: string,
     *     last_name: string,
     *     other_names: ?string,
     *     email: string,
     *     phone: ?string,
     *     gender: ?Gender,
     *     date_of_birth: ?string,
     *     state_of_origin: ?string,
     *     address: ?string,
     *     programme_id: int,
     *     level: int,
     *     entry_session_id: int,
     *     source: ImportSource
     * }
     */
    private function parsed(array $input, ?Applicant $applicant): array
    {
        $profile = $this->students->normalisedProfile($input);
        $programme = $this->programme(isset($input['programme_id']) ? $input['programme_id'] : $applicant?->programme_id);
        $level = $input['level'] ?? $applicant?->level;
        $level_error = $this->students->importLevelError($programme, $level);

        if ($level_error !== null) {
            throw ValidationException::withMessages(['level' => $level_error]);
        }

        $session_id = $input['entry_session_id'] ?? null;

        if ($session_id === '' || $session_id === null) {
            $session_id = $applicant?->entry_session_id;
        }

        return [
            ...$profile,
            'programme_id' => (int) $programme->id,
            'level' => (int) $level,
            'entry_session_id' => $this->entrySession(is_numeric($session_id) ? (int) $session_id : null),
            'source' => $this->source($input['source'] ?? ($applicant instanceof Applicant ? $applicant->source->value : ImportSource::Manual->value)),
        ];
    }

    /**
     * @param  array{
     *     first_name: string,
     *     last_name: string,
     *     other_names: ?string,
     *     email: string,
     *     phone: ?string,
     *     gender: ?Gender,
     *     date_of_birth: ?string,
     *     state_of_origin: ?string,
     *     address: ?string,
     *     programme_id: int,
     *     level: int,
     *     entry_session_id: int,
     *     source: ImportSource
     * }  $parsed
     */
    private function fill(Applicant $applicant, array $parsed): void
    {
        $applicant->first_name = $parsed['first_name'];
        $applicant->last_name = $parsed['last_name'];
        $applicant->other_names = $parsed['other_names'];
        $applicant->email = $parsed['email'];
        $applicant->phone = $parsed['phone'];
        $applicant->gender = $parsed['gender'];
        $applicant->date_of_birth = $parsed['date_of_birth'] === null
            ? null
            : Carbon::parse($parsed['date_of_birth'], 'Africa/Lagos');
        $applicant->state_of_origin = $parsed['state_of_origin'];
        $applicant->address = $parsed['address'];
        $applicant->programme_id = $parsed['programme_id'];
        $applicant->level = $parsed['level'];
        $applicant->entry_session_id = $parsed['entry_session_id'];
    }

    private function saveApplicant(Applicant $applicant): void
    {
        try {
            $applicant->save();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23000' && str_contains($exception->getMessage(), 'applicants_email_unique')) {
                throw ValidationException::withMessages(['email' => self::MESSAGE_APPLICANT_EMAIL]);
            }

            throw $exception;
        }
    }

    private function assertEmailFree(string $email, ?int $ignore_applicant_id): void
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists();

        if ($user) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_EMAIL_TAKEN]);
        }

        $query = Applicant::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)]);

        if ($ignore_applicant_id !== null) {
            $query->whereKeyNot($ignore_applicant_id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_APPLICANT_EMAIL]);
        }
    }

    private function programme(mixed $programme_id): Programme
    {
        $id = is_numeric($programme_id) ? (int) $programme_id : 0;
        $programme = $id > 0 ? Programme::query()->find($id) : null;

        if (! $programme instanceof Programme) {
            throw ValidationException::withMessages(['programme_id' => StudentService::MESSAGE_PROGRAMME]);
        }

        return $programme;
    }

    private function entrySession(?int $session_id): int
    {
        if ($session_id === null || $session_id === 0) {
            $current = AcademicSession::query()->current()->first();

            if (! $current instanceof AcademicSession) {
                throw ValidationException::withMessages(['entry_session_id' => StudentService::MESSAGE_SESSION]);
            }

            return (int) $current->id;
        }

        $session = AcademicSession::query()->find($session_id);

        if (! $session instanceof AcademicSession) {
            throw ValidationException::withMessages(['entry_session_id' => StudentService::MESSAGE_SESSION_INVALID]);
        }

        return (int) $session->id;
    }

    private function source(mixed $value): ImportSource
    {
        $source = is_string($value) ? ImportSource::tryFrom($value) : ($value instanceof ImportSource ? $value : null);

        return $source ?? ImportSource::Manual;
    }

    private function reason(string $reason): string
    {
        $text = trim($reason);

        if ($text === '' || mb_strlen($text) > 500) {
            throw ValidationException::withMessages(['reason' => self::MESSAGE_REASON]);
        }

        return $text;
    }

    private function auditRefusal(User $actor, Applicant $applicant, ?ApplicantStatus $attempted, Request $request): void
    {
        $id = (int) $applicant->getKey();
        $this->audit->record(
            $actor->id,
            self::ACTION_REFUSED,
            'applicants',
            $id,
            [
                'applicant_id' => $id,
                'status' => $applicant->status->value,
            ],
            [
                'applicant_id' => $id,
                'status' => $attempted instanceof ApplicantStatus ? $attempted->value : $applicant->status->value,
            ],
            $this->audit->ipFromRequest($request),
        );
    }

    /**
     * @param  array<string, mixed>|null  $parsed
     * @return array<string, mixed>
     */
    private function snapshot(Applicant $applicant, ?array $parsed): array
    {
        if ($parsed !== null) {
            return [
                'first_name' => $parsed['first_name'],
                'last_name' => $parsed['last_name'],
                'other_names' => $parsed['other_names'],
                'email' => $parsed['email'],
                'phone' => $parsed['phone'],
                'gender' => $parsed['gender'] instanceof Gender ? $parsed['gender']->value : null,
                'date_of_birth' => $parsed['date_of_birth'],
                'state_of_origin' => $parsed['state_of_origin'],
                'address' => $parsed['address'],
                'status' => $applicant->status->value,
                'level' => $parsed['level'],
                'programme_id' => $parsed['programme_id'],
                'entry_session_id' => $parsed['entry_session_id'],
                'source' => $parsed['source']->value,
            ];
        }

        return [
            'first_name' => $applicant->first_name,
            'last_name' => $applicant->last_name,
            'other_names' => $applicant->other_names,
            'email' => $applicant->email,
            'phone' => $applicant->phone,
            'gender' => $applicant->gender instanceof Gender ? $applicant->gender->value : null,
            'date_of_birth' => $applicant->date_of_birth?->toDateString(),
            'state_of_origin' => $applicant->state_of_origin,
            'address' => $applicant->address,
            'status' => $applicant->status->value,
            'level' => (int) $applicant->level,
            'programme_id' => (int) $applicant->programme_id,
            'entry_session_id' => $applicant->entry_session_id !== null ? (int) $applicant->entry_session_id : null,
            'source' => $applicant->source->value,
        ];
    }

    private function displayName(Applicant $applicant): string
    {
        $parts = [$applicant->first_name];

        if (is_string($applicant->other_names) && $applicant->other_names !== '') {
            $parts[] = $applicant->other_names;
        }

        $parts[] = $applicant->last_name;

        return implode(' ', $parts);
    }
}
