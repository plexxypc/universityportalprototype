<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\DocumentOwner;
use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Services\CredentialService;
use App\Services\DocumentService;
use App\Services\StudentService;
use App\Support\NigerianStates;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

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

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->editAction(),
            $this->statusAction(),
            $this->promoteAction(),
            $this->resendAction(),
        ];
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
                Tab::make('Documents')->schema([
                    View::make('filament.staff.student-documents'),
                ]),
                Tab::make('Activity')->schema([
                    Text::make(fn (): string => $this->activityText()),
                ]),
            ]),
        ]);
    }

    private function editAction(): Action
    {
        return Action::make('editStudent')
            ->label('Edit')
            ->visible(fn (): bool => $this->canManage())
            ->authorize(fn (): bool => $this->canManage())
            ->fillForm(fn (): array => $this->editState())
            ->schema($this->editFields())
            ->action(function (array $data): void {
                $this->runStudentAction(function () use ($data): void {
                    app(StudentService::class)->update($this->actor(), $this->student_id, $data, request());
                });
                Notification::make()->success()->title('Student updated.')->send();
            });
    }

    private function statusAction(): Action
    {
        return Action::make('changeStatus')
            ->label('Change status')
            ->visible(fn (): bool => $this->canManage())
            ->authorize(fn (): bool => $this->canManage())
            ->schema([
                Select::make('status')
                    ->label('Status')
                    ->options(collect(StudentStatus::cases())->mapWithKeys(
                        fn (StudentStatus $status): array => [$status->value => $status->value],
                    )->all())
                    ->required(),
                Textarea::make('reason')->label('Reason')->required()->maxLength(500),
            ])
            ->action(function (array $data): void {
                $this->runStudentAction(function () use ($data): void {
                    app(StudentService::class)->changeStatus(
                        $this->actor(),
                        $this->student_id,
                        (string) ($data['status'] ?? ''),
                        (string) ($data['reason'] ?? ''),
                        request(),
                    );
                });
                Notification::make()->success()->title('Status updated.')->send();
            });
    }

    private function promoteAction(): Action
    {
        return Action::make('promoteStudent')
            ->label('Promote one level')
            ->visible(fn (): bool => $this->canManage())
            ->authorize(fn (): bool => $this->canManage())
            ->requiresConfirmation()
            ->action(function (): void {
                $service = app(StudentService::class);
                $preview = $service->previewPromotion($this->actor(), [$this->student_id]);
                $eligible = array_column($preview['eligible'], 'id');

                if ($eligible === []) {
                    $reason = $preview['excluded'][0]['reason'] ?? StudentService::MESSAGE_PROMOTION_LEVEL;
                    throw ValidationException::withMessages(['promotion' => $reason]);
                }

                $service->commitPromotion($this->actor(), [$this->student_id], $eligible, request());
                Notification::make()->success()->title('Student promoted.')->send();
            });
    }

    private function resendAction(): Action
    {
        return Action::make('resendCredentials')
            ->label('Resend credentials')
            ->visible(fn (): bool => $this->canManage())
            ->authorize(fn (): bool => $this->canManage())
            ->schema(function (): array {
                if ($this->student()->user->must_change_password) {
                    return [];
                }

                return [
                    Checkbox::make('replace_chosen_password')
                        ->label(CredentialService::MESSAGE_REPLACE)
                        ->accepted(),
                ];
            })
            ->action(function (array $data): void {
                $student = $this->student();
                app(CredentialService::class)->resend(
                    $this->actor(),
                    $student->user,
                    $student->matric_no,
                    request(),
                    (bool) ($data['replace_chosen_password'] ?? false),
                );
                Notification::make()->success()->title('A new temporary password was issued.')->send();
            });
    }

    /**
     * @param  callable(): void  $callback
     */
    private function runStudentAction(callable $callback): void
    {
        try {
            $callback();
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    private function canManage(): bool
    {
        return app(StudentPolicy::class)->update($this->actor(), $this->student());
    }

    /**
     * @return array<string, mixed>
     */
    private function editState(): array
    {
        $student = $this->student();
        $born = $student->date_of_birth;

        return [
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'other_names' => $student->other_names,
            'email' => $student->user->email,
            'phone' => $student->user->phone,
            'gender' => $student->gender->value,
            'date_of_birth' => $born instanceof Carbon ? $born->toDateString() : null,
            'state_of_origin' => $student->state_of_origin,
            'address' => $student->address,
            'programme_id' => $student->programme_id,
            'level' => $student->level,
            'entry_session_id' => $student->entry_session_id,
        ];
    }

    /**
     * @return list<TextInput|Select|DatePicker|Textarea>
     */
    private function editFields(): array
    {
        return [
            TextInput::make('first_name')->label('First name')->required()->maxLength(100),
            TextInput::make('last_name')->label('Last name')->required()->maxLength(100),
            TextInput::make('other_names')->label('Other names')->maxLength(100),
            TextInput::make('email')->label('Email')->email()->required()->maxLength(255),
            TextInput::make('phone')->label('Phone')->tel()->maxLength(32),
            Select::make('gender')
                ->label('Gender')
                ->options(collect(Gender::cases())->mapWithKeys(
                    fn (Gender $gender): array => [$gender->value => $gender->value],
                )->all())
                ->required(),
            DatePicker::make('date_of_birth')->label('Date of birth')->required()->native(false),
            Select::make('state_of_origin')
                ->label('State of origin')
                ->options(collect(NigerianStates::names())->mapWithKeys(
                    fn (string $name): array => [$name => $name],
                )->all())
                ->searchable()
                ->required(),
            Textarea::make('address')->label('Address')->rows(3)->maxLength(1000),
            Select::make('programme_id')
                ->label('Programme')
                ->options(fn (): array => Programme::query()->visibleTo($this->actor())->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('level', null)),
            Select::make('level')
                ->label('Level')
                ->options(function (Get $get): array {
                    $programme = Programme::query()->find($get('programme_id'));
                    $years = $programme instanceof Programme ? min((int) $programme->duration_years, 6) : 0;
                    $options = [];

                    for ($year = 1; $year <= $years; $year++) {
                        $options[$year * 100] = (string) ($year * 100);
                    }

                    return $options;
                })
                ->required(),
            Select::make('entry_session_id')
                ->label('Entry session')
                ->options(fn (): array => AcademicSession::query()->orderByDesc('name')->pluck('name', 'id')->all())
                ->required(),
        ];
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

    /**
     * Documents on this student record. Download uses the existing route.
     *
     * @return list<array{id: int, kind: string, mime: string, url: string, can_remove: bool}>
     */
    public function documentRows(): array
    {
        $student = $this->student();
        $can_remove = $this->canManage();

        return Document::query()
            ->visibleTo($this->actor())
            ->where('owner_type', DocumentOwner::Student)
            ->where('owner_id', $student->getKey())
            ->orderByDesc('id')
            ->get()
            ->map(fn (Document $document): array => [
                'id' => (int) $document->getKey(),
                'kind' => (string) $document->kind,
                'mime' => (string) $document->mime,
                'url' => route('staff.downloads.document', $document),
                'can_remove' => $can_remove,
            ])
            ->all();
    }

    /**
     * Remove one document on this record. The Registrar and Super Admin may do this.
     */
    public function removeDocument(int $document_id): void
    {
        $document = Document::query()->visibleTo($this->actor())->whereKey($document_id)->first();

        if (! $document instanceof Document
            || $document->owner_type !== DocumentOwner::Student
            || (int) $document->owner_id !== $this->student_id) {
            abort(404);
        }

        $this->runStudentAction(function () use ($document_id): void {
            app(DocumentService::class)->remove($this->actor(), $document_id, request());
        });

        Notification::make()->success()->title('Document removed.')->send();
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
