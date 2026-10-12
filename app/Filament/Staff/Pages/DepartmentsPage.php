<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\User;
use App\Policies\DepartmentPolicy;
use App\Services\StructureService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
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
 * Departments. A Faculty Admin can add one only in their faculty.
 *
 * A Department Officer can edit their own department. The faculty id is
 * checked again in StructureService.
 */
class DepartmentsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'departments';

    protected static ?string $navigationLabel = 'Departments';

    protected static string|UnitEnum|null $navigationGroup = 'University setup and configuration';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(DepartmentPolicy::class)->viewAny($user);
    }

    public function getTitle(): string
    {
        return 'Departments';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Add department')
                ->authorize(fn (): bool => $this->canCreate())
                ->schema($this->fields())
                ->action(function (array $data): void {
                    $this->saveDepartment(0, (int) ($data['faculty_id'] ?? 0), $this->text($data, 'name'), $this->text($data, 'code'));
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Department::query()->visibleTo($this->actor())->with('faculty'))
            ->defaultSort('name')
            ->emptyStateHeading('No departments yet')
            ->columns([
                TextColumn::make('faculty.name')->label('Faculty'),
                TextColumn::make('name')->label('Name'),
                TextColumn::make('code')->label('Code'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->authorize(fn (Department $record): bool => $this->policy()->update($this->actor(), $record))
                    ->fillForm(fn (Department $record): array => [
                        'faculty_id' => $record->faculty_id,
                        'name' => $record->name,
                        'code' => $record->code,
                    ])
                    ->schema($this->fields())
                    ->action(function (Department $record, array $data): void {
                        $this->saveDepartment(
                            (int) $record->getKey(),
                            (int) ($data['faculty_id'] ?? 0),
                            $this->text($data, 'name'),
                            $this->text($data, 'code'),
                        );
                    }),
                DeleteAction::make()
                    ->authorize(fn (Department $record): bool => $this->policy()->delete($this->actor(), $record))
                    ->action(function (Department $record): void {
                        $this->deleteDepartment((int) $record->getKey());
                    }),
            ]);
    }

    /**
     * Create or update one department. A forged id is refused and changes nothing.
     */
    public function saveDepartment(int $department_id, int $faculty_id, string $name, string $code): void
    {
        try {
            $service = app(StructureService::class);

            if ($department_id === 0) {
                $service->createDepartment($this->actor(), $faculty_id, $name, $code, request());
            } else {
                $service->updateDepartment($this->actor(), $department_id, $faculty_id, $name, $code, request());
            }
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That department could not be saved.'))->danger()->send();

            return;
        }

        Notification::make()->title('Department saved')->success()->send();
    }

    /**
     * Delete one department. A forged id is refused and deletes nothing.
     */
    public function deleteDepartment(int $department_id): void
    {
        try {
            app(StructureService::class)->deleteDepartment($this->actor(), $department_id, request());
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That department could not be deleted.'))->danger()->send();

            return;
        }

        Notification::make()->title('Department deleted')->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return array<int, Select|TextInput>
     */
    private function fields(): array
    {
        return [
            Select::make('faculty_id')
                ->label('Faculty')
                ->options(fn (): array => Faculty::query()->visibleTo($this->actor())->orderBy('name')->pluck('name', 'id')->all())
                ->required(),
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('code')->label('Code')->required()->maxLength(32),
        ];
    }

    private function canCreate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $this->policy()->create($user);
    }

    private function policy(): DepartmentPolicy
    {
        return app(DepartmentPolicy::class);
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
}
