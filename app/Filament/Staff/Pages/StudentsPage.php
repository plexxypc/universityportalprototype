<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\StudentStatus;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Support\StatusTone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use UnitEnum;

/**
 * Student list. Search, filters, and 25 rows per page.
 *
 * A Faculty Admin or Department Officer only sees their scope. A filter
 * value outside that scope is ignored. A lecturer sees nobody until an
 * Approved registration exists.
 */
class StudentsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'students';

    protected static ?string $navigationLabel = 'Students';

    protected static string|UnitEnum|null $navigationGroup = 'Student information management';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(StudentPolicy::class)->viewAny($user);
    }

    public function getTitle(): string
    {
        return 'Students';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Add student')
                ->url(AddStudentPage::getUrl())
                ->visible(fn (): bool => app(StudentPolicy::class)->create($this->actor())),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Student::query()
                ->visibleTo($this->actor())
                ->with(['user', 'programme.department.faculty']))
            ->defaultSort('matric_no')
            ->paginated([25])
            ->defaultPaginationPageOption(25)
            ->searchable()
            ->searchUsing(function (Builder $query, string $search): void {
                $like = self::likeContains($search);

                $query->where(function (Builder $inner) use ($like): void {
                    $inner->where('matric_no', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('other_names', 'like', $like)
                        ->orWhereHas('user', fn (Builder $users): Builder => $users->where('email', 'like', $like));
                });
            })
            ->emptyStateHeading('No students yet')
            ->columns([
                TextColumn::make('matric_no')->label('Matric number')->searchable(false),
                TextColumn::make('first_name')
                    ->label('Name')
                    ->formatStateUsing(fn (mixed $state, Student $record): string => trim($record->first_name.' '.$record->last_name)),
                TextColumn::make('user.email')->label('Email'),
                TextColumn::make('programme.name')->label('Programme'),
                TextColumn::make('programme.department.name')->label('Department'),
                TextColumn::make('programme.department.faculty.name')->label('Faculty'),
                TextColumn::make('level')->label('Level'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(function (StudentStatus|string $state): string {
                        $status = $state instanceof StudentStatus ? $state : StudentStatus::tryFrom((string) $state);

                        return $status instanceof StudentStatus ? StatusTone::for($status) : StatusTone::NEUTRAL;
                    }),
            ])
            ->filters([
                Filter::make('faculty')
                    ->schema([
                        Select::make('faculty_id')->label('Faculty')->options(fn (): array => $this->facultyOptions()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $faculty_id = $this->acceptedId($data['faculty_id'] ?? null, $this->facultyOptions());

                        if ($faculty_id === null) {
                            return $query;
                        }

                        return $query->whereIn('programme_id', function (QueryBuilder $sub) use ($faculty_id): void {
                            $sub->select('programmes.id')
                                ->from('programmes')
                                ->join('departments', 'departments.id', '=', 'programmes.department_id')
                                ->where('departments.faculty_id', $faculty_id);
                        });
                    }),
                Filter::make('department')
                    ->schema([
                        Select::make('department_id')->label('Department')->options(fn (): array => $this->departmentOptions()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $department_id = $this->acceptedId($data['department_id'] ?? null, $this->departmentOptions());

                        if ($department_id === null) {
                            return $query;
                        }

                        return $query->whereIn('programme_id', function (QueryBuilder $sub) use ($department_id): void {
                            $sub->select('id')->from('programmes')->where('department_id', $department_id);
                        });
                    }),
                Filter::make('programme')
                    ->schema([
                        Select::make('programme_id')->label('Programme')->options(fn (): array => $this->programmeOptions()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $programme_id = $this->acceptedId($data['programme_id'] ?? null, $this->programmeOptions());

                        if ($programme_id === null) {
                            return $query;
                        }

                        return $query->where('programme_id', $programme_id);
                    }),
                Filter::make('level')
                    ->schema([
                        Select::make('level')->label('Level')->options($this->levelOptions()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $level = $data['level'] ?? null;

                        if (! is_numeric($level) || ! array_key_exists((int) $level, $this->levelOptions())) {
                            return $query;
                        }

                        return $query->where('level', (int) $level);
                    }),
                Filter::make('status')
                    ->schema([
                        Select::make('status')->label('Status')->options($this->statusOptions()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['status'] ?? null;

                        if (! is_string($status) || ! array_key_exists($status, $this->statusOptions())) {
                            return $query;
                        }

                        return $query->where('status', $status);
                    }),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * A LIKE pattern that treats %, _ and \ as ordinary characters.
     */
    public static function likeContains(string $value): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        return '%'.$escaped.'%';
    }

    /**
     * Faculties this account may filter by. A scoped role sees only its own.
     *
     * @return array<int, string>
     */
    public function facultyOptions(): array
    {
        return Faculty::query()->visibleTo($this->actor())->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Departments this account may filter by.
     *
     * @return array<int, string>
     */
    public function departmentOptions(): array
    {
        return Department::query()->visibleTo($this->actor())->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Programmes this account may filter by.
     *
     * @return array<int, string>
     */
    public function programmeOptions(): array
    {
        return Programme::query()->visibleTo($this->actor())->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * An id from the offered options, or null when the value is outside them.
     *
     * @param  array<int|string, string>  $options
     */
    public function acceptedId(mixed $value, array $options): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return array_key_exists($id, $options) ? $id : null;
    }

    /**
     * @return array<int, string>
     */
    private function levelOptions(): array
    {
        $options = [];

        foreach ([100, 200, 300, 400, 500, 600] as $level) {
            $options[$level] = (string) $level;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        $options = [];

        foreach (StudentStatus::cases() as $status) {
            $options[$status->value] = $status->value;
        }

        return $options;
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
