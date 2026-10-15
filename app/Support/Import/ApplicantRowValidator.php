<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\ApplicantStatus;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Programme;
use App\Models\User;
use App\Services\ApplicantService;
use App\Services\StudentService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Checks staged applicant rows with the add-student profile rules.
 *
 * Guardian and matric columns are ignored. An email already on users fails
 * the row. The first in-file copy is the one that can be imported.
 */
final class ApplicantRowValidator
{
    public const string MESSAGE_DUPLICATE = 'This row matches an existing applicant.';

    public const string MESSAGE_CONVERTED = 'This applicant is already a student.';

    /**
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
                    'field' => 'email',
                    'message' => self::MESSAGE_DUPLICATE,
                ];
            }
        }

        return [
            'errors' => array_slice($errors, 0, StudentRowValidator::ERROR_LIMIT),
            'error_count' => count($errors),
            'remaining_error_count' => max(0, count($errors) - StudentRowValidator::ERROR_LIMIT),
            'duplicates' => array_slice($duplicates, 0, StudentRowValidator::ERROR_LIMIT),
            'duplicate_count' => count($duplicates),
            'ready_count' => $ready,
            'total_rows' => count($staged->rows),
        ];
    }

    /**
     * @return list<array{
     *     row: int,
     *     action: string,
     *     applicant_id: ?int,
     *     errors: list<array{row: int, field: string, message: string}>,
     *     input: array<string, mixed>,
     *     cells: array<string, string>
     * }>
     */
    public function actions(StagedImport $staged, string $duplicate_mode): array
    {
        $map = $staged->column_map;

        if ($map === null) {
            throw ValidationException::withMessages(['column_map' => StudentRowValidator::MESSAGE_MAP]);
        }

        if (! in_array($duplicate_mode, ['skip', 'update'], true)) {
            throw ValidationException::withMessages(['duplicate_mode' => StudentRowValidator::MESSAGE_MODE]);
        }

        $programmes = $this->programmes($staged->rows, $map);
        $sessions = $this->sessions($staged->rows, $map);
        $has_current = AcademicSession::query()->current()->exists();
        $users = $this->users($staged->rows, $map);
        $applicants = $this->applicants($staged->rows, $map);
        $seen = [];
        $actions = [];

        foreach ($staged->rows as $offset => $row) {
            $number = $offset + 1;
            $values = $this->values($row, $map);
            $errors = $this->rowErrors($number, $values, $programmes, $sessions, $has_current, $users, $seen);
            $email = mb_strtolower($values['email']);
            $matched = $errors === [] ? ($applicants[$email] ?? null) : null;
            $action = 'create';
            $applicant_id = null;

            if ($errors !== []) {
                $action = 'fail';
            } elseif ($matched instanceof Applicant && $matched->status === ApplicantStatus::Converted) {
                $action = 'fail';
                $errors[] = $this->error($number, 'email', self::MESSAGE_CONVERTED);
            } elseif ($matched instanceof Applicant) {
                $action = $duplicate_mode === 'update' ? 'update' : 'skip';
                $applicant_id = (int) $matched->id;
            }

            $actions[] = [
                'row' => $number,
                'action' => $action,
                'applicant_id' => $applicant_id,
                'errors' => $errors,
                'input' => $this->input($values, $programmes, $sessions),
                'cells' => $values,
            ];
        }

        return $actions;
    }

    public function __construct(private readonly StudentService $students) {}

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
     * @param  array<string, User>  $users
     * @param  array<string, int>  $seen
     * @return list<array{row: int, field: string, message: string}>
     */
    private function rowErrors(
        int $number,
        array $values,
        Collection $programmes,
        Collection $sessions,
        bool $has_current,
        array $users,
        array &$seen,
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

        $email = mb_strtolower($values['email']);

        if ($email !== '') {
            if (isset($seen[$email])) {
                $errors[] = $this->error($number, 'email', sprintf(StudentRowValidator::MESSAGE_IN_FILE, $seen[$email]));
            } else {
                $seen[$email] = $number;
            }

            if (isset($users[$email])) {
                $errors[] = $this->error($number, 'email', ApplicantService::MESSAGE_EMAIL_TAKEN);
            }
        }

        return $errors;
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
        ];
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
     * @return array<string, User>
     */
    private function users(array $rows, array $map): array
    {
        $emails = [];

        foreach ($this->columnValues($rows, $map['email'] ?? null) as $email) {
            $emails[] = mb_strtolower($email);
        }

        if ($emails === []) {
            return [];
        }

        $users = [];

        foreach (User::query()->whereRaw('lower(email) in ('.implode(',', array_fill(0, count($emails), '?')).')', $emails)->get() as $user) {
            $users[mb_strtolower((string) $user->email)] = $user;
        }

        return $users;
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int>  $map
     * @return array<string, Applicant>
     */
    private function applicants(array $rows, array $map): array
    {
        $emails = [];

        foreach ($this->columnValues($rows, $map['email'] ?? null) as $email) {
            $emails[] = mb_strtolower($email);
        }

        if ($emails === []) {
            return [];
        }

        $found = [];

        foreach (Applicant::query()->whereRaw('lower(email) in ('.implode(',', array_fill(0, count($emails), '?')).')', $emails)->get() as $applicant) {
            $found[mb_strtolower((string) $applicant->email)] = $applicant;
        }

        return $found;
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
