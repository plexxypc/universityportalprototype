<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\CourseType;
use App\Models\Course;
use App\Models\Department;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\User;
use App\Policies\CoursePolicy;
use App\Services\CatalogueService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Course catalogue, prerequisites, and programme mappings.
 *
 * Scoped roles are checked again in CatalogueService. A Faculty Admin cannot
 * map across faculties. A Department Officer cannot map outside their department.
 */
class CoursesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'courses';

    protected static ?string $navigationLabel = 'Courses';

    protected static string|UnitEnum|null $navigationGroup = 'University setup and configuration';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 7;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(CoursePolicy::class)->viewAny($user);
    }

    public function getTitle(): string
    {
        return 'Courses';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Add course')
                ->authorize(fn (): bool => $this->canCreate())
                ->schema($this->fields())
                ->action(function (array $data): void {
                    $this->saveCourse(
                        0,
                        (int) ($data['department_id'] ?? 0),
                        $this->text($data, 'code'),
                        $this->text($data, 'title'),
                        $data['credit_units'] ?? null,
                        $this->rows($data['mappings'] ?? []),
                        $this->ids($data['prerequisite_ids'] ?? []),
                    );
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Course::query()->visibleTo($this->actor())->with('department'))
            ->defaultSort('code')
            ->emptyStateHeading('No courses yet')
            ->columns([
                TextColumn::make('code')->label('Code'),
                TextColumn::make('title')->label('Title'),
                TextColumn::make('credit_units')->label('Units'),
                TextColumn::make('department.name')->label('Department'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->authorize(fn (Course $record): bool => $this->policy()->update($this->actor(), $record))
                    ->fillForm(fn (Course $record): array => [
                        'department_id' => $record->department_id,
                        'code' => $record->code,
                        'title' => $record->title,
                        'credit_units' => $record->credit_units,
                        'prerequisite_ids' => $record->prerequisites()->pluck('prerequisite_course_id')->all(),
                        'mappings' => $record->programmeCourses()->get()->map(fn (ProgrammeCourse $mapping): array => [
                            'programme_id' => $mapping->programme_id,
                            'level' => $mapping->level,
                            'semester_no' => $mapping->semester_no,
                            'type' => $mapping->type->value,
                        ])->all(),
                    ])
                    ->schema($this->fields())
                    ->action(function (Course $record, array $data): void {
                        $this->saveCourse(
                            (int) $record->getKey(),
                            (int) ($data['department_id'] ?? 0),
                            $this->text($data, 'code'),
                            $this->text($data, 'title'),
                            $data['credit_units'] ?? null,
                            $this->rows($data['mappings'] ?? []),
                            $this->ids($data['prerequisite_ids'] ?? []),
                        );
                    }),
                DeleteAction::make()
                    ->authorize(fn (Course $record): bool => $this->policy()->delete($this->actor(), $record))
                    ->action(function (Course $record): void {
                        $this->deleteCourse((int) $record->getKey());
                    }),
            ]);
    }

    /**
     * Create or update one course. A forged id is refused and changes nothing.
     *
     * @param  list<array<string, mixed>>  $mappings
     * @param  list<int>  $prerequisite_ids
     */
    public function saveCourse(
        int $course_id,
        int $department_id,
        string $code,
        string $title,
        mixed $credit_units,
        array $mappings,
        array $prerequisite_ids,
    ): void {
        try {
            $service = app(CatalogueService::class);

            if ($course_id === 0) {
                $service->createCourse(
                    $this->actor(),
                    $department_id,
                    $code,
                    $title,
                    $credit_units,
                    $mappings,
                    $prerequisite_ids,
                    request(),
                );
            } else {
                $service->updateCourse(
                    $this->actor(),
                    $course_id,
                    $department_id,
                    $code,
                    $title,
                    $credit_units,
                    $mappings,
                    $prerequisite_ids,
                    request(),
                );
            }
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That course could not be saved.'))->danger()->send();

            return;
        }

        Notification::make()->title('Course saved')->success()->send();
    }

    /**
     * Delete one course. A forged id is refused and deletes nothing.
     */
    public function deleteCourse(int $course_id): void
    {
        try {
            app(CatalogueService::class)->deleteCourse($this->actor(), $course_id, request());
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That course could not be deleted.'))->danger()->send();

            return;
        }

        Notification::make()->title('Course deleted')->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return array<int, Select|TextInput|Repeater>
     */
    private function fields(): array
    {
        $levels = [];

        foreach ([100, 200, 300, 400, 500, 600] as $level) {
            $levels[$level] = (string) $level;
        }

        return [
            Select::make('department_id')
                ->label('Department')
                ->options(fn (): array => Department::query()->visibleTo($this->actor())->orderBy('name')->pluck('name', 'id')->all())
                ->required(),
            TextInput::make('code')->label('Code')->required()->maxLength(32),
            TextInput::make('title')->label('Title')->required()->maxLength(255),
            TextInput::make('credit_units')->label('Credit units')->required()->numeric()->minValue(1)->maxValue(255),
            Select::make('prerequisite_ids')
                ->label('Prerequisites')
                ->multiple()
                ->options(function (?Course $record): array {
                    return Course::query()
                        ->visibleTo($this->actor())
                        ->when($record instanceof Course, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
                        ->orderBy('code')
                        ->pluck('title', 'id')
                        ->all();
                }),
            Repeater::make('mappings')
                ->label('Programme mappings')
                ->schema([
                    Select::make('programme_id')
                        ->label('Programme')
                        ->options(fn (): array => Programme::query()->visibleTo($this->actor())->orderBy('name')->pluck('name', 'id')->all())
                        ->required(),
                    Select::make('level')->label('Level')->options($levels)->required(),
                    Select::make('semester_no')->label('Semester')->options([1 => '1', 2 => '2'])->required(),
                    Select::make('type')->label('Type')->options([
                        CourseType::Core->value => CourseType::Core->value,
                        CourseType::Elective->value => CourseType::Elective->value,
                    ])->required(),
                ])
                ->defaultItems(0)
                ->addActionLabel('Add mapping'),
        ];
    }

    private function canCreate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $this->policy()->create($user);
    }

    private function policy(): CoursePolicy
    {
        return app(CoursePolicy::class);
    }

    private function actor(): User
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $this->policy()->viewAny($user)) {
            abort(403);
        }

        return $user;
    }

    private function firstError(ValidationException $exception, string $fallback): string
    {
        foreach ($exception->errors() as $messages) {
            $message = $messages[0] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $id) {
            if (is_int($id) || (is_string($id) && $id !== '')) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
