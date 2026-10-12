<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CourseType;
use App\Enums\Role;
use App\Models\Course;
use App\Models\CoursePrerequisite;
use App\Models\Department;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\User;
use App\Policies\CoursePolicy;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The course catalogue, its prerequisites, and programme mappings.
 *
 * A two-course cycle is stored. A course cannot require itself. Scoped roles
 * cannot map a course and a programme that sit in different faculties, and
 * a Department Officer cannot map outside their department.
 */
class CatalogueService
{
    public const string CREATED = 'course.created';

    public const string UPDATED = 'course.updated';

    public const string DELETED = 'course.deleted';

    public const string MESSAGE_CODE = 'Enter a course code of 1 to 32 characters.';

    public const string MESSAGE_TITLE = 'Enter a title of 1 to 255 characters.';

    public const string MESSAGE_UNITS = 'Enter credit units from 1 to 255.';

    public const string MESSAGE_DUPLICATE = 'That course code is already in use.';

    public const string MESSAGE_MISSING = 'That course was not found.';

    public const string MESSAGE_DEPARTMENT = 'That department was not found.';

    public const string MESSAGE_PROGRAMME = 'That programme was not found.';

    public const string MESSAGE_LEVEL = 'Choose a level of 100, 200, 300, 400, 500, or 600.';

    public const string MESSAGE_SEMESTER = 'Choose semester 1 or 2.';

    public const string MESSAGE_TYPE = 'Choose Core or Elective.';

    public const string MESSAGE_SELF = 'A course cannot require itself.';

    public const string MESSAGE_PREREQUISITE = 'That prerequisite course was not found.';

    public const string MESSAGE_MAPPING = 'Each programme can be mapped once.';

    public const string MESSAGE_IN_USE = 'This course is in use, so it cannot be deleted.';

    /**
     * @var list<int>
     */
    private const array LEVELS = [100, 200, 300, 400, 500, 600];

    public function __construct(private readonly Audit $audit) {}

    /**
     * Create a course and its mappings and prerequisites in one transaction.
     *
     * @param  list<array<string, mixed>>|array<int|string, mixed>  $mappings
     * @param  list<mixed>  $prerequisite_ids
     */
    public function createCourse(
        User $actor,
        int $department_id,
        string $code,
        string $title,
        mixed $credit_units,
        array $mappings,
        array $prerequisite_ids,
        Request $request,
    ): Course {
        if (! app(CoursePolicy::class)->create($actor)) {
            throw new AuthorizationException;
        }

        $code = $this->label($code, 'code', self::MESSAGE_CODE, 32);
        $title = $this->label($title, 'title', self::MESSAGE_TITLE, 255);
        $units = $this->units($credit_units);
        $parsed_mappings = $this->mappings($mappings);
        $parsed_prerequisites = $this->prerequisiteIds($prerequisite_ids, null);

        return DB::transaction(function () use ($actor, $department_id, $code, $title, $units, $parsed_mappings, $parsed_prerequisites, $request): Course {
            $this->departmentForWrite($actor, $department_id);
            $this->uniqueCode($code, null);

            $course = new Course;
            $course->department_id = $department_id;
            $course->code = $code;
            $course->title = $title;
            $course->credit_units = $units;
            $course->save();

            $this->writeMappings($actor, $course, $parsed_mappings);
            $this->writePrerequisites($course, $parsed_prerequisites);

            $this->audit->record(
                $actor->id,
                self::CREATED,
                'courses',
                (int) $course->getKey(),
                null,
                $this->snapshot($course),
                $this->audit->ipFromRequest($request),
            );

            return $course->refresh();
        });
    }

    /**
     * Update a course, replacing its mappings and prerequisites in the same transaction.
     *
     * @param  list<array<string, mixed>>|array<int|string, mixed>  $mappings
     * @param  list<mixed>  $prerequisite_ids
     */
    public function updateCourse(
        User $actor,
        int $course_id,
        int $department_id,
        string $code,
        string $title,
        mixed $credit_units,
        array $mappings,
        array $prerequisite_ids,
        Request $request,
    ): Course {
        $code = $this->label($code, 'code', self::MESSAGE_CODE, 32);
        $title = $this->label($title, 'title', self::MESSAGE_TITLE, 255);
        $units = $this->units($credit_units);
        $parsed_mappings = $this->mappings($mappings);
        $parsed_prerequisites = $this->prerequisiteIds($prerequisite_ids, $course_id);

        return DB::transaction(function () use ($actor, $course_id, $department_id, $code, $title, $units, $parsed_mappings, $parsed_prerequisites, $request): Course {
            $course = $this->lockedCourse($actor, $course_id);
            $before = $this->snapshot($course);
            $this->departmentForWrite($actor, $department_id);
            $this->uniqueCode($code, (int) $course->getKey());
            $course->department_id = $department_id;
            $course->code = $code;
            $course->title = $title;
            $course->credit_units = $units;
            $course->save();

            $this->writeMappings($actor, $course, $parsed_mappings);
            $this->writePrerequisites($course, $parsed_prerequisites);

            $this->audit->record(
                $actor->id,
                self::UPDATED,
                'courses',
                (int) $course->getKey(),
                $before,
                $this->snapshot($course),
                $this->audit->ipFromRequest($request),
            );

            return $course->refresh();
        });
    }

    /**
     * Delete a course that nothing else references.
     */
    public function deleteCourse(User $actor, int $course_id, Request $request): void
    {
        DB::transaction(function () use ($actor, $course_id, $request): void {
            $course = $this->lockedCourse($actor, $course_id);

            if ($course->programmeCourses()->exists()
                || $course->prerequisites()->exists()
                || $course->dependents()->exists()
                || $course->assignments()->exists()
                || $course->registrationItems()->exists()
                || $course->attendanceSessions()->exists()
                || $course->examTimetable()->exists()
                || $course->results()->exists()) {
                throw ValidationException::withMessages(['course' => self::MESSAGE_IN_USE]);
            }

            $before = $this->snapshot($course);
            $course_key = (int) $course->getKey();
            $course->delete();

            $this->audit->record(
                $actor->id,
                self::DELETED,
                'courses',
                $course_key,
                $before,
                null,
                $this->audit->ipFromRequest($request),
            );
        });
    }

    /**
     * Replace programme mappings. Scoped roles cannot cross a faculty or, for a
     * Department Officer, a department.
     *
     * @param  list<array{programme_id: int, level: int, semester_no: int, type: string}>  $mappings
     */
    private function writeMappings(User $actor, Course $course, array $mappings): void
    {
        $course->programmeCourses()->delete();

        foreach ($mappings as $mapping) {
            $programme = Programme::query()->whereKey($mapping['programme_id'])->first();

            if (! $programme instanceof Programme) {
                throw ValidationException::withMessages(['mappings' => self::MESSAGE_PROGRAMME]);
            }

            $this->assertMappingAllowed($actor, $programme, $course);

            $row = new ProgrammeCourse;
            $row->programme_id = $programme->id;
            $row->course_id = $course->id;
            $row->level = $mapping['level'];
            $row->semester_no = $mapping['semester_no'];
            $row->type = CourseType::from($mapping['type']);
            $row->save();
        }
    }

    /**
     * Replace prerequisites. A cycle of two courses is kept.
     *
     * @param  list<int>  $prerequisite_ids
     */
    private function writePrerequisites(Course $course, array $prerequisite_ids): void
    {
        $course->prerequisites()->delete();

        foreach ($prerequisite_ids as $prerequisite_id) {
            if ($prerequisite_id === (int) $course->getKey()) {
                throw ValidationException::withMessages(['prerequisite_ids' => self::MESSAGE_SELF]);
            }

            if (! Course::query()->whereKey($prerequisite_id)->exists()) {
                throw ValidationException::withMessages(['prerequisite_ids' => self::MESSAGE_PREREQUISITE]);
            }

            $row = new CoursePrerequisite;
            $row->course_id = $course->id;
            $row->prerequisite_course_id = $prerequisite_id;
            $row->save();
        }
    }

    /**
     * Refuse a cross-faculty mapping for a scoped role, and a cross-department
     * mapping for a Department Officer.
     */
    private function assertMappingAllowed(User $actor, Programme $programme, Course $course): void
    {
        if ($this->institutionWide($actor)) {
            return;
        }

        $programme->loadMissing('department');
        $course->loadMissing('department');
        $programme_faculty = (int) $programme->department?->faculty_id;
        $course_faculty = (int) $course->department?->faculty_id;

        if ($actor->hasRole(Role::FacultyAdmin) && ! $actor->hasRole(Role::DepartmentOfficer)) {
            $faculties = $actor->facultyIds();

            if (! in_array($programme_faculty, $faculties, true) || ! in_array($course_faculty, $faculties, true)) {
                throw new AuthorizationException;
            }

            return;
        }

        if ($actor->hasRole(Role::DepartmentOfficer)) {
            $departments = $actor->departmentIds();

            if (! in_array((int) $programme->department_id, $departments, true)
                || ! in_array((int) $course->department_id, $departments, true)) {
                throw new AuthorizationException;
            }

            return;
        }

        if ($actor->hasRole(Role::FacultyAdmin)) {
            $faculties = $actor->facultyIds();

            if (! in_array($programme_faculty, $faculties, true) || ! in_array($course_faculty, $faculties, true)) {
                throw new AuthorizationException;
            }

            return;
        }

        throw new AuthorizationException;
    }

    /**
     * A department the account may attach a course to.
     */
    private function departmentForWrite(User $actor, int $department_id): void
    {
        $department = Department::query()->whereKey($department_id)->first();

        if (! $department instanceof Department) {
            throw ValidationException::withMessages(['department_id' => self::MESSAGE_DEPARTMENT]);
        }

        if ($this->institutionWide($actor)) {
            return;
        }

        if ($actor->hasRole(Role::FacultyAdmin) && in_array((int) $department->faculty_id, $actor->facultyIds(), true)) {
            return;
        }

        if ($actor->hasRole(Role::DepartmentOfficer) && in_array($department_id, $actor->departmentIds(), true)) {
            return;
        }

        throw new AuthorizationException;
    }

    /**
     * A visible course, locked for the write.
     */
    private function lockedCourse(User $actor, int $course_id): Course
    {
        $course = Course::query()->visibleTo($actor)->whereKey($course_id)->lockForUpdate()->first();

        if (! $course instanceof Course) {
            if (! app(CoursePolicy::class)->create($actor)) {
                throw new AuthorizationException;
            }

            throw ValidationException::withMessages(['course' => self::MESSAGE_MISSING]);
        }

        if (! app(CoursePolicy::class)->update($actor, $course)) {
            throw new AuthorizationException;
        }

        return $course;
    }

    /**
     * @param  list<array<string, mixed>>|array<int|string, mixed>  $mappings
     * @return list<array{programme_id: int, level: int, semester_no: int, type: string}>
     */
    private function mappings(array $mappings): array
    {
        $parsed = [];
        $seen = [];

        foreach ($mappings as $mapping) {
            if (! is_array($mapping)) {
                throw ValidationException::withMessages(['mappings' => self::MESSAGE_PROGRAMME]);
            }

            $programme_id = (int) ($mapping['programme_id'] ?? 0);
            $level = (int) ($mapping['level'] ?? 0);
            $semester_no = (int) ($mapping['semester_no'] ?? 0);
            $type = $mapping['type'] ?? '';
            $type = is_string($type) ? $type : '';

            if ($programme_id < 1) {
                throw ValidationException::withMessages(['mappings' => self::MESSAGE_PROGRAMME]);
            }

            if (isset($seen[$programme_id])) {
                throw ValidationException::withMessages(['mappings' => self::MESSAGE_MAPPING]);
            }

            if (! in_array($level, self::LEVELS, true)) {
                throw ValidationException::withMessages(['mappings' => self::MESSAGE_LEVEL]);
            }

            if (! in_array($semester_no, [1, 2], true)) {
                throw ValidationException::withMessages(['mappings' => self::MESSAGE_SEMESTER]);
            }

            if (CourseType::tryFrom($type) === null) {
                throw ValidationException::withMessages(['mappings' => self::MESSAGE_TYPE]);
            }

            $seen[$programme_id] = true;
            $parsed[] = [
                'programme_id' => $programme_id,
                'level' => $level,
                'semester_no' => $semester_no,
                'type' => $type,
            ];
        }

        usort($parsed, fn (array $left, array $right): int => $left['programme_id'] <=> $right['programme_id']);

        return $parsed;
    }

    /**
     * @param  list<mixed>  $prerequisite_ids
     * @return list<int>
     */
    private function prerequisiteIds(array $prerequisite_ids, ?int $course_id): array
    {
        $ids = [];

        foreach ($prerequisite_ids as $prerequisite_id) {
            if (is_array($prerequisite_id)) {
                throw ValidationException::withMessages(['prerequisite_ids' => self::MESSAGE_PREREQUISITE]);
            }

            $id = (int) $prerequisite_id;

            if ($id < 1) {
                throw ValidationException::withMessages(['prerequisite_ids' => self::MESSAGE_PREREQUISITE]);
            }

            if ($course_id !== null && $id === $course_id) {
                throw ValidationException::withMessages(['prerequisite_ids' => self::MESSAGE_SELF]);
            }

            $ids[$id] = $id;
        }

        $sorted = array_values($ids);
        sort($sorted);

        return $sorted;
    }

    private function uniqueCode(string $code, ?int $ignore_id): void
    {
        $query = Course::query()->where('code', $code);

        if ($ignore_id !== null) {
            $query->whereKeyNot($ignore_id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['code' => self::MESSAGE_DUPLICATE]);
        }
    }

    private function label(string $value, string $field, string $message, int $max): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw ValidationException::withMessages([$field => $message]);
        }

        return $value;
    }

    private function units(mixed $value): int
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');

        if (preg_match('/^[1-9][0-9]{0,2}$/', $text) !== 1) {
            throw ValidationException::withMessages(['credit_units' => self::MESSAGE_UNITS]);
        }

        $units = (int) $text;

        if ($units < 1 || $units > 255) {
            throw ValidationException::withMessages(['credit_units' => self::MESSAGE_UNITS]);
        }

        return $units;
    }

    private function institutionWide(User $actor): bool
    {
        return $actor->hasRole(Role::SuperAdmin) || $actor->hasRole(Role::Registrar);
    }

    /**
     * @return array{department_id: int, code: string, title: string, credit_units: int, mappings: list<array{programme_id: int, level: int, semester_no: int, type: string}>, prerequisite_ids: list<int>}
     */
    private function snapshot(Course $course): array
    {
        $course->load(['programmeCourses', 'prerequisites']);

        $mappings = [];

        foreach ($course->programmeCourses as $mapping) {
            $mappings[] = [
                'programme_id' => (int) $mapping->programme_id,
                'level' => (int) $mapping->level,
                'semester_no' => (int) $mapping->semester_no,
                'type' => $mapping->type->value,
            ];
        }

        usort($mappings, fn (array $left, array $right): int => $left['programme_id'] <=> $right['programme_id']);

        $prerequisite_ids = $course->prerequisites
            ->map(fn (CoursePrerequisite $row): int => (int) $row->prerequisite_course_id)
            ->all();
        sort($prerequisite_ids);

        return [
            'department_id' => (int) $course->department_id,
            'code' => $course->code,
            'title' => $course->title,
            'credit_units' => (int) $course->credit_units,
            'mappings' => $mappings,
            'prerequisite_ids' => $prerequisite_ids,
        ];
    }
}
