<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Models\AcademicSession;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Checks staged student rows with the add-student and guardian rules.
 *
 * In-file copies are errors and name the first row. A database match is
 * reported separately so skip or update can still commit that row.
 */
final class StudentRowValidator
{
    public const int ERROR_LIMIT = 50;

    public const string MESSAGE_IN_FILE = 'This value is already used on row %d.';

    public const string MESSAGE_EMAIL_CHANGE = 'Update cannot change the email address.';

    public const string MESSAGE_MATRIC_OWNER = 'This matric number belongs to a different account.';

    public const string MESSAGE_MATRIC_LOCKED = 'Update cannot change the matric number.';

    public const string MESSAGE_DUPLICATE = 'This row matches an existing student.';

    public const string MESSAGE_MODE = 'Choose skip or update for existing students.';

    public const string MESSAGE_MAP = 'Save a column map before validation.';

    public function __construct(private readonly StudentService $students) {}

    /**
     * Errors and database matches for this staged sheet.
     *
     * @return array{
     *     errors: list<array{row: int, field: string, message: string}>,
     *     error_count: int,
     *     remaining_error_count: int,
     *     duplicates: list<array{row: int, field: string, message: string}>,
     *     duplicate_count: int,
     *     ready_count: int,
     *     total_rows: int
     * }
     */
    public function assess(StagedImport $staged, string $duplicate_mode): array
    {
        $errors = [];
        $duplicates = [];
        $ready = 0;

        foreach ($this->actions($staged, $duplicate_mode) as $action) {
            if ($action['action'] === 'fail') {
                foreach ($action['errors'] as $error) {
                    $errors[] = $error;
                }

                continue;
            }

            $ready++;

            if ($action['action'] === 'skip' || $action['action'] === 'update') {
                $duplicates[] = [
                    'row' => $action['row'],
                    'field' => $action['match_field'],
                    'message' => self::MESSAGE_DUPLICATE,
                ];
            }
        }

        return [
            'errors' => array_slice($errors, 0, self::ERROR_LIMIT),
            'error_count' => count($errors),
            'remaining_error_count' => max(0, count($errors) - self::ERROR_LIMIT),
            'duplicates' => array_slice($duplicates, 0, self::ERROR_LIMIT),
            'duplicate_count' => count($duplicates),
            'ready_count' => $ready,
            'total_rows' => count($staged->rows),
        ];
    }

    /**
     * One decision per data row. Commit reads this and ignores any browser offset.
     *
     * @return list<array{
     *     row: int,
     *     action: string,
     *     student_id: ?int,
     *     match_field: string,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     input: array<string, mixed>,
     *     guardian: ?array{name: string, phone: string, email: string, relationship: string},
     *     cells: array<string, string>
     * }>
     */
    public function actions(StagedImport $staged, string $duplicate_mode): array
    {
        $map = $staged->column_map;

        if ($map === null) {
            throw ValidationException::withMessages(['column_map' => self::MESSAGE_MAP]);
        }

        $programmes = $this->programmes($staged->rows, $map);
        $sessions = $this->sessions($staged->rows, $map);
        $has_current = AcademicSession::query()->current()->exists();
        $identity = $this->identity($staged->rows, $map);
        $seen_email = [];
        $seen_matric = [];
        $actions = [];

        foreach ($staged->rows as $offset => $row) {
            $number = $offset + 1;
            $values = $this->values($row, $map);
            $row_errors = $this->rowErrors(
                $number,
                $values,
                $duplicate_mode,
                $programmes,
                $sessions,
                $has_current,
                $identity,
                $seen_email,
                $seen_matric,
            );
            $matched = $row_errors === [] ? $this->matchedStudent($values, $identity) : null;
            $action = 'create';
            $student_id = null;
            $match_field = 'email';

            if ($row_errors !== []) {
                $action = 'fail';
            } elseif ($matched instanceof Student) {
                $action = $duplicate_mode === 'update' ? 'update' : 'skip';
                $student_id = (int) $matched->id;
                $match_field = $this->databaseMatch($values, $identity) ?? 'email';
            }

            $actions[] = [
                'row' => $number,
                'action' => $action,
                'student_id' => $student_id,
                'match_field' => $match_field,
                'errors' => $row_errors,
                'input' => $this->input($values, $programmes, $sessions),
                'guardian' => $this->guardianInput($values),
                'cells' => $values,
            ];
        }

        return $actions;
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $map
     * @return array<string, string>
     */
    private function values(array $row, array $map): array
    {
        $values = [];

        foreach ([
            'first_name',
            'last_name',
            'other_names',
            'email',
            'phone',
            'gender',
            'date_of_birth',
            'state_of_origin',
            'address',
            'programme_code',
            'level',
            'entry_session',
            'matric_number',
            'guardian_name',
            'guardian_phone',
            'guardian_email',
            'guardian_relationship',
        ] as $field) {
            $index = $map[$field] ?? null;
            $values[$field] = is_int($index) ? trim((string) ($row[$index] ?? '')) : '';
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $values
     * @param  Collection<string, Programme>  $programmes
     * @param  Collection<string, AcademicSession>  $sessions
     * @param  array{users: array<string, User>, students_by_user: array<int, Student>, students_by_matric: array<string, Student>}  $identity
     * @param  array<string, int>  $seen_email
     * @param  array<string, int>  $seen_matric
     * @return list<array{row: int, field: string, message: string}>
     */
    private function rowErrors(
        int $number,
        array $values,
        string $duplicate_mode,
        Collection $programmes,
        Collection $sessions,
        bool $has_current,
        array $identity,
        array &$seen_email,
        array &$seen_matric,
    ): array {
        $errors = [];
        $date = $this->isoDate($values['date_of_birth']);

        if (! $date['ok']) {
            $errors[] = $this->error($number, 'date_of_birth', StudentService::MESSAGE_DATE);
        }

        foreach ($this->students->importProfileErrors([
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'],
            'other_names' => $values['other_names'],
            'email' => $values['email'],
            'phone' => $values['phone'],
            'gender' => $values['gender'] === '' ? null : ucfirst(mb_strtolower($values['gender'])),
            'date_of_birth' => $date['ok'] ? $date['value'] : null,
            'state_of_origin' => $values['state_of_origin'],
            'address' => $values['address'],
        ]) as $error) {
            $errors[] = $this->error($number, $error['field'], $error['message']);
        }

        $programme = $programmes->get($values['programme_code']);

        if ($values['programme_code'] === '' || ! $programme instanceof Programme) {
            $errors[] = $this->error($number, 'programme_code', StudentService::MESSAGE_PROGRAMME);

            if (preg_match('/^\d+$/', $values['level']) !== 1) {
                $errors[] = $this->error($number, 'level', StudentService::MESSAGE_LEVEL_NUMBER);
            }
        } else {
            $level_error = $this->students->importLevelError($programme, $values['level']);

            if ($level_error !== null) {
                $errors[] = $this->error($number, 'level', $level_error);
            }
        }

        if ($values['entry_session'] === '') {
            if (! $has_current) {
                $errors[] = $this->error($number, 'entry_session', StudentService::MESSAGE_SESSION);
            }
        } elseif (preg_match('/^\d{4}\/\d{4}$/', $values['entry_session']) !== 1 || ! $sessions->has($values['entry_session'])) {
            $errors[] = $this->error($number, 'entry_session', StudentService::MESSAGE_SESSION_INVALID);
        }

        if (strlen($values['matric_number']) > 32) {
            $errors[] = $this->error($number, 'matric_number', StudentService::MESSAGE_MATRIC_LENGTH);
        }

        $errors = array_merge($errors, $this->fileDuplicates($number, $values, $seen_email, $seen_matric));
        $errors = array_merge($errors, $this->guardianErrors($number, $values));
        $errors = array_merge($errors, $this->identityErrors($number, $values, $duplicate_mode, $identity));

        return $errors;
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, int>  $seen_email
     * @param  array<string, int>  $seen_matric
     * @return list<array{row: int, field: string, message: string}>
     */
    private function fileDuplicates(int $number, array $values, array &$seen_email, array &$seen_matric): array
    {
        $errors = [];
        $email = mb_strtolower($values['email']);

        if ($email !== '') {
            if (isset($seen_email[$email])) {
                $errors[] = $this->error($number, 'email', sprintf(self::MESSAGE_IN_FILE, $seen_email[$email]));
            } else {
                $seen_email[$email] = $number;
            }
        }

        $matric = $values['matric_number'];

        if ($matric !== '') {
            if (isset($seen_matric[$matric])) {
                $errors[] = $this->error($number, 'matric_number', sprintf(self::MESSAGE_IN_FILE, $seen_matric[$matric]));
            } else {
                $seen_matric[$matric] = $number;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<array{row: int, field: string, message: string}>
     */
    private function guardianErrors(int $number, array $values): array
    {
        if ($values['guardian_name'] === '' && $values['guardian_phone'] === '' && $values['guardian_email'] === '' && $values['guardian_relationship'] === '') {
            return [];
        }

        try {
            $this->students->guardiansForImport([[
                'name' => $values['guardian_name'],
                'phone' => $values['guardian_phone'],
                'email' => $values['guardian_email'],
                'relationship' => $values['guardian_relationship'],
            ]]);
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $message = $messages[0] ?? '';

                if (! is_string($message) || $message === '') {
                    continue;
                }

                $errors[] = $this->error($number, $this->guardianField(is_string($field) ? $field : '', $values), $message);
            }

            return $errors;
        }

        return [];
    }

    /**
     * @param  array<string, string>  $values
     */
    private function guardianField(string $field, array $values): string
    {
        if ($field === 'phone') {
            return 'guardian_phone';
        }

        if ($field === 'email') {
            return 'guardian_email';
        }

        if ($field === 'name') {
            return 'guardian_name';
        }

        if ($field === 'relationship') {
            return 'guardian_relationship';
        }

        return $values['guardian_name'] === '' ? 'guardian_name' : 'guardian_relationship';
    }

    /**
     * @param  array<string, string>  $values
     * @param  array{users: array<string, User>, students_by_user: array<int, Student>, students_by_matric: array<string, Student>}  $identity
     * @return list<array{row: int, field: string, message: string}>
     */
    private function identityErrors(int $number, array $values, string $duplicate_mode, array $identity): array
    {
        $email = mb_strtolower($values['email']);
        $user = $email === '' ? null : ($identity['users'][$email] ?? null);
        $email_student = $user instanceof User ? ($identity['students_by_user'][(int) $user->id] ?? null) : null;
        $matric_student = $values['matric_number'] === '' ? null : ($identity['students_by_matric'][$values['matric_number']] ?? null);
        $errors = [];

        if ($user instanceof User && ! $email_student instanceof Student) {
            $errors[] = $this->error($number, 'email', StudentService::MESSAGE_EMAIL_TAKEN);
        }

        if ($email_student instanceof Student && $matric_student instanceof Student && (int) $email_student->id !== (int) $matric_student->id) {
            $errors[] = $this->error($number, 'matric_number', self::MESSAGE_MATRIC_OWNER);

            return $errors;
        }

        $matched = $email_student instanceof Student ? $email_student : ($matric_student instanceof Student ? $matric_student : null);

        if (! $matched instanceof Student || $duplicate_mode !== 'update') {
            return $errors;
        }

        $account = $matched->user;

        if (! $account instanceof User) {
            $errors[] = $this->error($number, 'email', StudentService::MESSAGE_MISSING);

            return $errors;
        }

        if ($email !== mb_strtolower((string) $account->email)) {
            $errors[] = $this->error($number, 'email', self::MESSAGE_EMAIL_CHANGE);
        }

        if ($values['matric_number'] !== '' && $values['matric_number'] !== $matched->matric_no) {
            $errors[] = $this->error($number, 'matric_number', self::MESSAGE_MATRIC_LOCKED);
        }

        return $errors;
    }

    /**
     * The student this row matches, when email and matric do not point at two people.
     *
     * @param  array<string, string>  $values
     * @param  array{users: array<string, User>, students_by_user: array<int, Student>, students_by_matric: array<string, Student>}  $identity
     */
    private function matchedStudent(array $values, array $identity): ?Student
    {
        $email = mb_strtolower($values['email']);
        $user = $email === '' ? null : ($identity['users'][$email] ?? null);
        $email_student = $user instanceof User ? ($identity['students_by_user'][(int) $user->id] ?? null) : null;
        $matric_student = $values['matric_number'] === '' ? null : ($identity['students_by_matric'][$values['matric_number']] ?? null);

        if ($user instanceof User && ! $email_student instanceof Student) {
            return null;
        }

        if ($email_student instanceof Student && $matric_student instanceof Student && (int) $email_student->id !== (int) $matric_student->id) {
            return null;
        }

        if ($email_student instanceof Student) {
            return $email_student;
        }

        return $matric_student instanceof Student ? $matric_student : null;
    }

    /**
     * @param  array<string, string>  $values
     * @param  Collection<string, Programme>  $programmes
     * @param  Collection<string, AcademicSession>  $sessions
     * @return array<string, mixed>
     */
    private function input(array $values, Collection $programmes, Collection $sessions): array
    {
        $date = $this->isoDate($values['date_of_birth']);
        $programme = $programmes->get($values['programme_code']);
        $session = $sessions->get($values['entry_session']);

        return [
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'],
            'other_names' => $values['other_names'],
            'email' => $values['email'],
            'phone' => $values['phone'],
            'gender' => $values['gender'] === '' ? null : ucfirst(mb_strtolower($values['gender'])),
            'date_of_birth' => $date['ok'] ? $date['value'] : null,
            'state_of_origin' => $values['state_of_origin'],
            'address' => $values['address'],
            'programme_id' => $programme instanceof Programme ? (int) $programme->id : 0,
            'level' => preg_match('/^\d+$/', $values['level']) === 1 ? (int) $values['level'] : 0,
            'entry_session_id' => $session instanceof AcademicSession ? (int) $session->id : null,
            'matric_number' => $values['matric_number'],
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @return ?array{name: string, phone: string, email: string, relationship: string}
     */
    private function guardianInput(array $values): ?array
    {
        if ($values['guardian_name'] === '' && $values['guardian_phone'] === '' && $values['guardian_email'] === '' && $values['guardian_relationship'] === '') {
            return null;
        }

        return [
            'name' => $values['guardian_name'],
            'phone' => $values['guardian_phone'],
            'email' => $values['guardian_email'],
            'relationship' => $values['guardian_relationship'],
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @param  array{users: array<string, User>, students_by_user: array<int, Student>, students_by_matric: array<string, Student>}  $identity
     */
    private function databaseMatch(array $values, array $identity): ?string
    {
        $email = mb_strtolower($values['email']);
        $user = $email === '' ? null : ($identity['users'][$email] ?? null);
        $email_student = $user instanceof User ? ($identity['students_by_user'][(int) $user->id] ?? null) : null;

        if ($email_student instanceof Student) {
            return 'email';
        }

        $matric_student = $values['matric_number'] === '' ? null : ($identity['students_by_matric'][$values['matric_number']] ?? null);

        return $matric_student instanceof Student ? 'matric_number' : null;
    }

    /**
     * @return array{ok: bool, value: ?string}
     */
    private function isoDate(string $value): array
    {
        if ($value === '') {
            return ['ok' => true, 'value' => null];
        }

        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            return checkdate($month, $day, $year)
                ? ['ok' => true, 'value' => sprintf('%04d-%02d-%02d', $year, $month, $day)]
                : ['ok' => false, 'value' => null];
        }

        if (preg_match('/\A(\d{2})\/(\d{2})\/(\d{4})\z/', $value, $matches) === 1) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            return checkdate($month, $day, $year)
                ? ['ok' => true, 'value' => sprintf('%04d-%02d-%02d', $year, $month, $day)]
                : ['ok' => false, 'value' => null];
        }

        return ['ok' => false, 'value' => null];
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int>  $map
     * @return Collection<string, Programme>
     */
    private function programmes(array $rows, array $map): Collection
    {
        $codes = $this->columnValues($rows, $map['programme_code'] ?? null);

        if ($codes === []) {
            return new Collection;
        }

        return Programme::query()->whereIn('code', $codes)->get()->keyBy('code');
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int>  $map
     * @return Collection<string, AcademicSession>
     */
    private function sessions(array $rows, array $map): Collection
    {
        $names = [];

        foreach ($this->columnValues($rows, $map['entry_session'] ?? null) as $name) {
            if (preg_match('/^\d{4}\/\d{4}$/', $name) === 1) {
                $names[] = $name;
            }
        }

        if ($names === []) {
            return new Collection;
        }

        return AcademicSession::query()->whereIn('name', $names)->get()->keyBy('name');
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int>  $map
     * @return array{users: array<string, User>, students_by_user: array<int, Student>, students_by_matric: array<string, Student>}
     */
    private function identity(array $rows, array $map): array
    {
        $emails = [];

        foreach ($this->columnValues($rows, $map['email'] ?? null) as $email) {
            $emails[] = mb_strtolower($email);
        }

        $matrics = $this->columnValues($rows, $map['matric_number'] ?? null);
        $users = [];

        if ($emails !== []) {
            $found = User::query()
                ->whereRaw('lower(email) in ('.implode(',', array_fill(0, count($emails), '?')).')', $emails)
                ->get();

            foreach ($found as $user) {
                $users[mb_strtolower((string) $user->email)] = $user;
            }
        }

        $students_by_user = [];
        $students_by_matric = [];
        $user_ids = array_map(fn (User $user): int => (int) $user->id, $users);

        if ($user_ids !== [] || $matrics !== []) {
            $students = Student::query()->with('user')->where(function ($query) use ($user_ids, $matrics): void {
                if ($user_ids !== []) {
                    $query->whereIn('user_id', $user_ids);
                }

                if ($matrics !== []) {
                    if ($user_ids === []) {
                        $query->whereIn('matric_no', $matrics);
                    } else {
                        $query->orWhereIn('matric_no', $matrics);
                    }
                }
            })->get();

            foreach ($students as $student) {
                $students_by_user[(int) $student->user_id] = $student;
                $students_by_matric[$student->matric_no] = $student;
            }
        }

        return [
            'users' => $users,
            'students_by_user' => $students_by_user,
            'students_by_matric' => $students_by_matric,
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private function columnValues(array $rows, ?int $index): array
    {
        if ($index === null) {
            return [];
        }

        $values = [];

        foreach ($rows as $row) {
            $value = trim((string) ($row[$index] ?? ''));

            if ($value !== '') {
                $values[$value] = $value;
            }
        }

        return array_values($values);
    }

    /**
     * @return array{row: int, field: string, message: string}
     */
    private function error(int $row, string $field, string $message): array
    {
        return [
            'row' => $row,
            'field' => $field,
            'message' => $message,
        ];
    }
}
