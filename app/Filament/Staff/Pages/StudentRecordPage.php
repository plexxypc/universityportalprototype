<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use App\Policies\StudentPolicy;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * One student record. Missing and out-of-scope ids both end as 404.
 *
 * Courses, fees, results, and attendance are not built. Those tabs do not
 * query. Only a Super Admin reads the activity rows.
 */
class StudentRecordPage extends Page
{
    protected static ?string $slug = 'students/{student}';

    protected static bool $shouldRegisterNavigation = false;

    public const string MODULE_LATER = 'This section is not available yet.';

    public const string ACTIVITY_HIDDEN = 'Only a Super Admin can read activity.';

    public const string ACTIVITY_EMPTY = 'No activity is recorded for this student.';

    public int $student_id = 0;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(StudentPolicy::class)->viewAny($user);
    }

    public function mount(int $student): void
    {
        $row = $this->findVisible($student);

        abort_if($row === null, 404);

        $this->student_id = (int) $row->getKey();
    }

    public function getTitle(): string
    {
        $student = $this->student();

        return trim($student->first_name.' '.$student->last_name);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Record')->tabs([
                Tab::make('Profile')->schema($this->profileLines()),
                Tab::make('Courses')->schema([Text::make(self::MODULE_LATER)]),
                Tab::make('Fees and payments')->schema([Text::make(self::MODULE_LATER)]),
                Tab::make('Results')->schema([Text::make(self::MODULE_LATER)]),
                Tab::make('Attendance')->schema([Text::make(self::MODULE_LATER)]),
                Tab::make('Documents')->schema([Text::make(self::MODULE_LATER)]),
                Tab::make('Activity')->schema([
                    Text::make(fn (): string => $this->activityText()),
                ]),
            ]),
        ]);
    }

    /**
     * A student this account may open, or null when the id is missing or outside scope.
     */
    public function findVisible(int $student_id): ?Student
    {
        $student = Student::query()
            ->visibleTo($this->actor())
            ->with(['user', 'programme.department.faculty', 'entrySession'])
            ->whereKey($student_id)
            ->first();

        if (! $student instanceof Student) {
            return null;
        }

        if (! app(StudentPolicy::class)->view($this->actor(), $student)) {
            return null;
        }

        return $student;
    }

    /**
     * Activity text. A registrar does not query audit logs.
     */
    public function activityText(): string
    {
        $actor = $this->actor();

        if (! $actor->hasRole(Role::SuperAdmin)) {
            return self::ACTIVITY_HIDDEN;
        }

        $student = $this->student();
        $rows = AuditLog::query()
            ->where(function (Builder $query) use ($student): void {
                $query->where(function (Builder $students) use ($student): void {
                    $students->where('entity', 'students')->where('entity_id', $student->getKey());
                })->orWhere(function (Builder $users) use ($student): void {
                    $users->where('entity', 'users')->where('entity_id', $student->user_id);
                });
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        if ($rows->isEmpty()) {
            return self::ACTIVITY_EMPTY;
        }

        return $rows->map(function (AuditLog $row): string {
            $at = $row->created_at instanceof Carbon
                ? $row->created_at->timezone('Africa/Lagos')->format('d M Y, H:i')
                : '';

            return trim($row->action.' '.$at);
        })->implode(' | ');
    }

    /**
     * @return list<Text>
     */
    private function profileLines(): array
    {
        $student = $this->student();
        $user = $student->user;
        $programme = $student->programme;
        $session = $student->entrySession;

        $lines = [
            'Matric number: '.$student->matric_no,
            'Name: '.trim($student->first_name.' '.($student->other_names ?? '').' '.$student->last_name),
            'Email: '.$user->email,
            'Phone: '.($user->phone ?? ''),
            'Gender: '.$student->gender->value,
            'Date of birth: '.($student->date_of_birth instanceof Carbon ? $student->date_of_birth->format('d M Y') : ''),
            'State of origin: '.($student->state_of_origin ?? ''),
            'Address: '.($student->address ?? ''),
            'Programme: '.$programme->name,
            'Department: '.$programme->department->name,
            'Faculty: '.$programme->department->faculty->name,
            'Level: '.$student->level,
            'Status: '.$student->status->value,
            'Entry session: '.$session->name,
        ];

        return array_map(
            fn (string $line): Text => Text::make($line),
            $lines,
        );
    }

    private function student(): Student
    {
        $student = $this->findVisible($this->student_id);

        abort_if($student === null, 404);

        return $student;
    }

    private function actor(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
