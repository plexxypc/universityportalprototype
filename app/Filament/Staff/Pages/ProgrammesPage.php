<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\Degree;
use App\Models\Department;
use App\Models\Programme;
use App\Models\User;
use App\Policies\ProgrammePolicy;
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
 * Programmes. Degree is chosen from the closed list.
 *
 * A Faculty Admin or Department Officer can save only inside their scope.
 * The department id is checked again in StructureService.
 */
class ProgrammesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'programmes';

    protected static ?string $navigationLabel = 'Programmes';

    protected static string|UnitEnum|null $navigationGroup = 'University setup and configuration';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ProgrammePolicy::class)->viewAny($user);
    }

    public function getTitle(): string
    {
        return 'Programmes';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Add programme')
                ->authorize(fn (): bool => $this->canCreate())
                ->schema($this->fields())
                ->action(function (array $data): void {
                    $this->saveProgramme(
                        0,
                        (int) ($data['department_id'] ?? 0),
                        $this->text($data, 'name'),
                        $this->text($data, 'code'),
                        $this->text($data, 'degree'),
                        $data['duration_years'] ?? null,
                    );
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Programme::query()->visibleTo($this->actor())->with('department'))
            ->defaultSort('name')
            ->emptyStateHeading('No programmes yet')
            ->columns([
                TextColumn::make('department.name')->label('Department'),
                TextColumn::make('name')->label('Name'),
                TextColumn::make('code')->label('Code'),
                TextColumn::make('degree')->label('Degree'),
                TextColumn::make('duration_years')->label('Years'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->authorize(fn (Programme $record): bool => $this->policy()->update($this->actor(), $record))
                    ->fillForm(fn (Programme $record): array => [
                        'department_id' => $record->department_id,
                        'name' => $record->name,
                        'code' => $record->code,
                        'degree' => $record->degree,
                        'duration_years' => $record->duration_years,
                    ])
                    ->schema($this->fields())
                    ->action(function (Programme $record, array $data): void {
                        $this->saveProgramme(
                            (int) $record->getKey(),
                            (int) ($data['department_id'] ?? 0),
                            $this->text($data, 'name'),
                            $this->text($data, 'code'),
                            $this->text($data, 'degree'),
                            $data['duration_years'] ?? null,
                        );
                    }),
                DeleteAction::make()
                    ->authorize(fn (Programme $record): bool => $this->policy()->delete($this->actor(), $record))
                    ->action(function (Programme $record): void {
                        $this->deleteProgramme((int) $record->getKey());
                    }),
            ]);
    }

    /**
     * Create or update one programme. A forged id is refused and changes nothing.
     */
    public function saveProgramme(
        int $programme_id,
        int $department_id,
        string $name,
        string $code,
        string $degree,
        mixed $duration_years,
    ): void {
        try {
            $service = app(StructureService::class);

            if ($programme_id === 0) {
                $service->createProgramme($this->actor(), $department_id, $name, $code, $degree, $duration_years, request());
            } else {
                $service->updateProgramme($this->actor(), $programme_id, $department_id, $name, $code, $degree, $duration_years, request());
            }
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That programme could not be saved.'))->danger()->send();

            return;
        }

        Notification::make()->title('Programme saved')->success()->send();
    }

    /**
     * Delete one programme. A forged id is refused and deletes nothing.
     */
    public function deleteProgramme(int $programme_id): void
    {
        try {
            app(StructureService::class)->deleteProgramme($this->actor(), $programme_id, request());
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That programme could not be deleted.'))->danger()->send();

            return;
        }

        Notification::make()->title('Programme deleted')->success()->send();
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
        $years = [];

        for ($year = 1; $year <= 10; $year++) {
            $years[$year] = (string) $year;
        }

        return [
            Select::make('department_id')
                ->label('Department')
                ->options(fn (): array => Department::query()->visibleTo($this->actor())->orderBy('name')->pluck('name', 'id')->all())
                ->required(),
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('code')->label('Code')->required()->maxLength(32),
            Select::make('degree')->label('Degree')->options(Degree::options())->required(),
            Select::make('duration_years')->label('Duration (years)')->options($years)->required(),
        ];
    }

    private function canCreate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $this->policy()->create($user);
    }

    private function policy(): ProgrammePolicy
    {
        return app(ProgrammePolicy::class);
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
