<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Degree;
use App\Enums\Role;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\User;
use App\Policies\DepartmentPolicy;
use App\Policies\FacultyPolicy;
use App\Policies\ProgrammePolicy;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Faculties, departments, and programmes.
 *
 * Degree is a closed list. Faculty Admin and Department Officer are checked
 * on the server for create, update, and delete. A forged id is refused.
 */
class StructureService
{
    public const string FACULTY_CREATED = 'faculty.created';

    public const string FACULTY_UPDATED = 'faculty.updated';

    public const string FACULTY_DELETED = 'faculty.deleted';

    public const string DEPARTMENT_CREATED = 'department.created';

    public const string DEPARTMENT_UPDATED = 'department.updated';

    public const string DEPARTMENT_DELETED = 'department.deleted';

    public const string PROGRAMME_CREATED = 'programme.created';

    public const string PROGRAMME_UPDATED = 'programme.updated';

    public const string PROGRAMME_DELETED = 'programme.deleted';

    public const string MESSAGE_NAME = 'Enter a name of 1 to 255 characters.';

    public const string MESSAGE_CODE = 'Enter a code of 1 to 32 characters.';

    public const string MESSAGE_DUPLICATE = 'That code is already in use.';

    public const string MESSAGE_DEGREE = 'Choose a degree from the list.';

    public const string MESSAGE_DURATION = 'Enter a duration from 1 to 10 years.';

    public const string MESSAGE_FACULTY_MISSING = 'That faculty was not found.';

    public const string MESSAGE_DEPARTMENT_MISSING = 'That department was not found.';

    public const string MESSAGE_PROGRAMME_MISSING = 'That programme was not found.';

    public const string MESSAGE_FACULTY_IN_USE = 'This faculty has departments, staff roles, or announcements, so it cannot be deleted.';

    public const string MESSAGE_DEPARTMENT_IN_USE = 'This department has programmes, courses, staff, roles, or announcements, so it cannot be deleted.';

    public const string MESSAGE_PROGRAMME_IN_USE = 'This programme is used by a course mapping, applicant, student, fee, or announcement, so it cannot be deleted.';

    public function __construct(private readonly Audit $audit) {}

    /**
     * Create one faculty. Scoped roles cannot create faculties.
     */
    public function createFaculty(User $actor, string $name, string $code, Request $request): Faculty
    {
        $this->allowFaculty($actor, 'create');
        $name = $this->label($name, 'name', self::MESSAGE_NAME, 255);
        $code = $this->label($code, 'code', self::MESSAGE_CODE, 32);

        return DB::transaction(function () use ($actor, $name, $code, $request): Faculty {
            $this->uniqueCode(Faculty::class, $code, null);

            $faculty = new Faculty;
            $faculty->name = $name;
            $faculty->code = $code;
            $faculty->save();

            $this->audit->record(
                $actor->id,
                self::FACULTY_CREATED,
                'faculties',
                (int) $faculty->getKey(),
                null,
                $this->facultySnapshot($faculty),
                $this->audit->ipFromRequest($request),
            );

            return $faculty;
        });
    }

    /**
     * Rename a faculty the account is allowed to change.
     */
    public function updateFaculty(User $actor, int $faculty_id, string $name, string $code, Request $request): Faculty
    {
        $name = $this->label($name, 'name', self::MESSAGE_NAME, 255);
        $code = $this->label($code, 'code', self::MESSAGE_CODE, 32);

        return DB::transaction(function () use ($actor, $faculty_id, $name, $code, $request): Faculty {
            $faculty = $this->lockedFaculty($actor, $faculty_id);
            $before = $this->facultySnapshot($faculty);
            $this->uniqueCode(Faculty::class, $code, (int) $faculty->getKey());
            $faculty->name = $name;
            $faculty->code = $code;
            $faculty->save();

            $this->audit->record(
                $actor->id,
                self::FACULTY_UPDATED,
                'faculties',
                (int) $faculty->getKey(),
                $before,
                $this->facultySnapshot($faculty),
                $this->audit->ipFromRequest($request),
            );

            return $faculty;
        });
    }

    /**
     * Delete a faculty that nothing else references.
     */
    public function deleteFaculty(User $actor, int $faculty_id, Request $request): void
    {
        DB::transaction(function () use ($actor, $faculty_id, $request): void {
            $faculty = $this->lockedFaculty($actor, $faculty_id);

            if ($faculty->departments()->exists()
                || $faculty->roleAssignments()->exists()
                || $faculty->announcements()->exists()) {
                throw ValidationException::withMessages(['faculty' => self::MESSAGE_FACULTY_IN_USE]);
            }

            $before = $this->facultySnapshot($faculty);
            $faculty_key = (int) $faculty->getKey();
            $faculty->delete();

            $this->audit->record(
                $actor->id,
                self::FACULTY_DELETED,
                'faculties',
                $faculty_key,
                $before,
                null,
                $this->audit->ipFromRequest($request),
            );
        });
    }

    /**
     * Create a department in a faculty the account may write.
     */
    public function createDepartment(User $actor, int $faculty_id, string $name, string $code, Request $request): Department
    {
        if (! app(DepartmentPolicy::class)->create($actor)) {
            throw new AuthorizationException;
        }

        $name = $this->label($name, 'name', self::MESSAGE_NAME, 255);
        $code = $this->label($code, 'code', self::MESSAGE_CODE, 32);

        return DB::transaction(function () use ($actor, $faculty_id, $name, $code, $request): Department {
            $this->facultyForWrite($actor, $faculty_id);
            $this->uniqueCode(Department::class, $code, null);

            $department = new Department;
            $department->faculty_id = $faculty_id;
            $department->name = $name;
            $department->code = $code;
            $department->save();

            $this->audit->record(
                $actor->id,
                self::DEPARTMENT_CREATED,
                'departments',
                (int) $department->getKey(),
                null,
                $this->departmentSnapshot($department),
                $this->audit->ipFromRequest($request),
            );

            return $department;
        });
    }

    /**
     * Rename a department or move it, when the account may write both faculties.
     */
    public function updateDepartment(User $actor, int $department_id, int $faculty_id, string $name, string $code, Request $request): Department
    {
        $name = $this->label($name, 'name', self::MESSAGE_NAME, 255);
        $code = $this->label($code, 'code', self::MESSAGE_CODE, 32);

        return DB::transaction(function () use ($actor, $department_id, $faculty_id, $name, $code, $request): Department {
            $department = $this->lockedDepartment($actor, $department_id);
            $before = $this->departmentSnapshot($department);
            $this->facultyForWrite($actor, $faculty_id);

            if ($actor->hasRole(Role::DepartmentOfficer) && ! $this->institutionWide($actor) && ! $actor->hasRole(Role::FacultyAdmin)) {
                if ($faculty_id !== (int) $department->faculty_id) {
                    throw new AuthorizationException;
                }
            }

            $this->uniqueCode(Department::class, $code, (int) $department->getKey());
            $department->faculty_id = $faculty_id;
            $department->name = $name;
            $department->code = $code;
            $department->save();

            $this->audit->record(
                $actor->id,
                self::DEPARTMENT_UPDATED,
                'departments',
                (int) $department->getKey(),
                $before,
                $this->departmentSnapshot($department),
                $this->audit->ipFromRequest($request),
            );

            return $department;
        });
    }

    /**
     * Delete a department that nothing else references.
     */
    public function deleteDepartment(User $actor, int $department_id, Request $request): void
    {
        DB::transaction(function () use ($actor, $department_id, $request): void {
            $department = $this->lockedDepartment($actor, $department_id);

            if ($department->programmes()->exists()
                || $department->courses()->exists()
                || $department->roleAssignments()->exists()
                || $department->staff()->exists()
                || $department->announcements()->exists()) {
                throw ValidationException::withMessages(['department' => self::MESSAGE_DEPARTMENT_IN_USE]);
            }

            $before = $this->departmentSnapshot($department);
            $department_key = (int) $department->getKey();
            $department->delete();

            $this->audit->record(
                $actor->id,
                self::DEPARTMENT_DELETED,
                'departments',
                $department_key,
                $before,
                null,
                $this->audit->ipFromRequest($request),
            );
        });
    }

    /**
     * Create a programme in a department the account may write.
     */
    public function createProgramme(
        User $actor,
        int $department_id,
        string $name,
        string $code,
        string $degree,
        mixed $duration_years,
        Request $request,
    ): Programme {
        if (! app(ProgrammePolicy::class)->create($actor)) {
            throw new AuthorizationException;
        }

        $name = $this->label($name, 'name', self::MESSAGE_NAME, 255);
        $code = $this->label($code, 'code', self::MESSAGE_CODE, 32);
        $degree = $this->degree($degree);
        $duration = $this->duration($duration_years);

        return DB::transaction(function () use ($actor, $department_id, $name, $code, $degree, $duration, $request): Programme {
            $this->departmentForWrite($actor, $department_id);
            $this->uniqueCode(Programme::class, $code, null);

            $programme = new Programme;
            $programme->department_id = $department_id;
            $programme->name = $name;
            $programme->code = $code;
            $programme->degree = $degree;
            $programme->duration_years = $duration;
            $programme->save();

            $this->audit->record(
                $actor->id,
                self::PROGRAMME_CREATED,
                'programmes',
                (int) $programme->getKey(),
                null,
                $this->programmeSnapshot($programme),
                $this->audit->ipFromRequest($request),
            );

            return $programme;
        });
    }

    /**
     * Update a programme the account may write, including a move inside scope.
     */
    public function updateProgramme(
        User $actor,
        int $programme_id,
        int $department_id,
        string $name,
        string $code,
        string $degree,
        mixed $duration_years,
        Request $request,
    ): Programme {
        $name = $this->label($name, 'name', self::MESSAGE_NAME, 255);
        $code = $this->label($code, 'code', self::MESSAGE_CODE, 32);
        $degree = $this->degree($degree);
        $duration = $this->duration($duration_years);

        return DB::transaction(function () use ($actor, $programme_id, $department_id, $name, $code, $degree, $duration, $request): Programme {
            $programme = $this->lockedProgramme($actor, $programme_id);
            $before = $this->programmeSnapshot($programme);
            $this->departmentForWrite($actor, $department_id);
            $this->uniqueCode(Programme::class, $code, (int) $programme->getKey());
            $programme->department_id = $department_id;
            $programme->name = $name;
            $programme->code = $code;
            $programme->degree = $degree;
            $programme->duration_years = $duration;
            $programme->save();

            $this->audit->record(
                $actor->id,
                self::PROGRAMME_UPDATED,
                'programmes',
                (int) $programme->getKey(),
                $before,
                $this->programmeSnapshot($programme),
                $this->audit->ipFromRequest($request),
            );

            return $programme;
        });
    }

    /**
     * Delete a programme that nothing else references.
     */
    public function deleteProgramme(User $actor, int $programme_id, Request $request): void
    {
        DB::transaction(function () use ($actor, $programme_id, $request): void {
            $programme = $this->lockedProgramme($actor, $programme_id);

            if ($programme->programmeCourses()->exists()
                || $programme->applicants()->exists()
                || $programme->students()->exists()
                || $programme->feeStructures()->exists()
                || $programme->announcements()->exists()) {
                throw ValidationException::withMessages(['programme' => self::MESSAGE_PROGRAMME_IN_USE]);
            }

            $before = $this->programmeSnapshot($programme);
            $programme_key = (int) $programme->getKey();
            $programme->delete();

            $this->audit->record(
                $actor->id,
                self::PROGRAMME_DELETED,
                'programmes',
                $programme_key,
                $before,
                null,
                $this->audit->ipFromRequest($request),
            );
        });
    }

    /**
     * A faculty the account may attach a department to.
     */
    private function facultyForWrite(User $actor, int $faculty_id): Faculty
    {
        $faculty = Faculty::query()->whereKey($faculty_id)->first();

        if (! $faculty instanceof Faculty) {
            throw ValidationException::withMessages(['faculty_id' => self::MESSAGE_FACULTY_MISSING]);
        }

        if ($this->institutionWide($actor)) {
            return $faculty;
        }

        if ($actor->hasRole(Role::FacultyAdmin) && in_array($faculty_id, $actor->facultyIds(), true)) {
            return $faculty;
        }

        if ($actor->hasRole(Role::DepartmentOfficer)) {
            $owns_faculty = Department::query()
                ->whereKey($actor->departmentIds())
                ->where('faculty_id', $faculty_id)
                ->exists();

            if ($owns_faculty) {
                return $faculty;
            }
        }

        throw new AuthorizationException;
    }

    /**
     * A department the account may attach a programme to.
     */
    private function departmentForWrite(User $actor, int $department_id): Department
    {
        $department = Department::query()->whereKey($department_id)->first();

        if (! $department instanceof Department) {
            throw ValidationException::withMessages(['department_id' => self::MESSAGE_DEPARTMENT_MISSING]);
        }

        if ($this->institutionWide($actor)) {
            return $department;
        }

        if ($actor->hasRole(Role::FacultyAdmin) && in_array((int) $department->faculty_id, $actor->facultyIds(), true)) {
            return $department;
        }

        if ($actor->hasRole(Role::DepartmentOfficer) && in_array($department_id, $actor->departmentIds(), true)) {
            return $department;
        }

        throw new AuthorizationException;
    }

    /**
     * A visible faculty, locked for the write.
     */
    private function lockedFaculty(User $actor, int $faculty_id): Faculty
    {
        $faculty = Faculty::query()->visibleTo($actor)->whereKey($faculty_id)->lockForUpdate()->first();

        if (! $faculty instanceof Faculty) {
            $this->allowFaculty($actor, 'create');

            throw ValidationException::withMessages(['faculty' => self::MESSAGE_FACULTY_MISSING]);
        }

        if (! app(FacultyPolicy::class)->update($actor, $faculty)) {
            throw new AuthorizationException;
        }

        return $faculty;
    }

    /**
     * A visible department, locked for the write.
     */
    private function lockedDepartment(User $actor, int $department_id): Department
    {
        $department = Department::query()->visibleTo($actor)->whereKey($department_id)->lockForUpdate()->first();

        if (! $department instanceof Department) {
            if (! app(DepartmentPolicy::class)->create($actor)) {
                throw new AuthorizationException;
            }

            throw ValidationException::withMessages(['department' => self::MESSAGE_DEPARTMENT_MISSING]);
        }

        if (! app(DepartmentPolicy::class)->update($actor, $department)) {
            throw new AuthorizationException;
        }

        return $department;
    }

    /**
     * A visible programme, locked for the write.
     */
    private function lockedProgramme(User $actor, int $programme_id): Programme
    {
        $programme = Programme::query()->visibleTo($actor)->whereKey($programme_id)->lockForUpdate()->first();

        if (! $programme instanceof Programme) {
            if (! app(ProgrammePolicy::class)->create($actor)) {
                throw new AuthorizationException;
            }

            throw ValidationException::withMessages(['programme' => self::MESSAGE_PROGRAMME_MISSING]);
        }

        if (! app(ProgrammePolicy::class)->update($actor, $programme)) {
            throw new AuthorizationException;
        }

        return $programme;
    }

    /**
     * Refuse an account that may not create or otherwise write a faculty.
     */
    private function allowFaculty(User $actor, string $ability): void
    {
        $policy = app(FacultyPolicy::class);
        $allowed = $ability === 'create' && $policy->create($actor);

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function uniqueCode(string $model, string $code, ?int $ignore_id): void
    {
        $query = $model::query()->where('code', $code);

        if ($ignore_id !== null) {
            $query->whereKeyNot($ignore_id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['code' => self::MESSAGE_DUPLICATE]);
        }
    }

    /**
     * Trim a name or code and reject blanks, control characters, and over-long text.
     */
    private function label(string $value, string $field, string $message, int $max): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $value;
    }

    /**
     * Accept only a degree from the closed list.
     */
    private function degree(string $value): string
    {
        $degree = Degree::tryFrom(trim($value));

        if (! $degree instanceof Degree) {
            throw ValidationException::withMessages(['degree' => self::MESSAGE_DEGREE]);
        }

        return $degree->value;
    }

    /**
     * A whole number of years from 1 to 10.
     */
    private function duration(mixed $value): int
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');

        if (preg_match('/^(?:[1-9]|10)$/', $text) !== 1) {
            throw ValidationException::withMessages(['duration_years' => self::MESSAGE_DURATION]);
        }

        return (int) $text;
    }

    /**
     * Super Admin and Registrar act for the whole institution.
     */
    private function institutionWide(User $actor): bool
    {
        return $actor->hasRole(Role::SuperAdmin) || $actor->hasRole(Role::Registrar);
    }

    /**
     * @return array{name: string, code: string}
     */
    private function facultySnapshot(Faculty $faculty): array
    {
        return [
            'name' => $faculty->name,
            'code' => $faculty->code,
        ];
    }

    /**
     * @return array{faculty_id: int, name: string, code: string}
     */
    private function departmentSnapshot(Department $department): array
    {
        return [
            'faculty_id' => (int) $department->faculty_id,
            'name' => $department->name,
            'code' => $department->code,
        ];
    }

    /**
     * @return array{department_id: int, name: string, code: string, degree: string, duration_years: int}
     */
    private function programmeSnapshot(Programme $programme): array
    {
        return [
            'department_id' => (int) $programme->department_id,
            'name' => $programme->name,
            'code' => $programme->code,
            'degree' => $programme->degree,
            'duration_years' => (int) $programme->duration_years,
        ];
    }
}
