<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Models\Faculty;
use App\Models\User;
use App\Policies\FacultyPolicy;
use App\Services\StructureService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
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
 * Faculties. Creating one is limited to Super Admin and Registrar.
 *
 * A Faculty Admin may edit only their own faculty. A Department Officer
 * may open the list and cannot save.
 */
class FacultiesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'faculties';

    protected static ?string $navigationLabel = 'Faculties';

    protected static string|UnitEnum|null $navigationGroup = 'University setup and configuration';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 4;

    /**
     * Anyone who may view academic structure may open the list.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(FacultyPolicy::class)->viewAny($user);
    }

    public function getTitle(): string
    {
        return 'Faculties';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Add faculty')
                ->authorize(fn (): bool => $this->canCreate())
                ->schema($this->fields())
                ->action(function (array $data): void {
                    $this->saveFaculty(0, $this->text($data, 'name'), $this->text($data, 'code'));
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Faculty::query()->visibleTo($this->actor()))
            ->defaultSort('name')
            ->emptyStateHeading('No faculties yet')
            ->columns([
                TextColumn::make('name')->label('Name'),
                TextColumn::make('code')->label('Code'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->authorize(fn (Faculty $record): bool => $this->policy()->update($this->actor(), $record))
                    ->fillForm(fn (Faculty $record): array => [
                        'name' => $record->name,
                        'code' => $record->code,
                    ])
                    ->schema($this->fields())
                    ->action(function (Faculty $record, array $data): void {
                        $this->saveFaculty((int) $record->getKey(), $this->text($data, 'name'), $this->text($data, 'code'));
                    }),
                DeleteAction::make()
                    ->authorize(fn (Faculty $record): bool => $this->policy()->delete($this->actor(), $record))
                    ->action(function (Faculty $record): void {
                        $this->deleteFaculty((int) $record->getKey());
                    }),
            ]);
    }

    /**
     * Create or update one faculty. A forged id is refused and changes nothing.
     */
    public function saveFaculty(int $faculty_id, string $name, string $code): void
    {
        try {
            $service = app(StructureService::class);

            if ($faculty_id === 0) {
                $service->createFaculty($this->actor(), $name, $code, request());
            } else {
                $service->updateFaculty($this->actor(), $faculty_id, $name, $code, request());
            }
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That faculty could not be saved.'))->danger()->send();

            return;
        }

        Notification::make()->title('Faculty saved')->success()->send();
    }

    /**
     * Delete one faculty. A forged id is refused and deletes nothing.
     */
    public function deleteFaculty(int $faculty_id): void
    {
        try {
            app(StructureService::class)->deleteFaculty($this->actor(), $faculty_id, request());
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That faculty could not be deleted.'))->danger()->send();

            return;
        }

        Notification::make()->title('Faculty deleted')->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return array<int, TextInput>
     */
    private function fields(): array
    {
        return [
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('code')->label('Code')->required()->maxLength(32),
        ];
    }

    private function canCreate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $this->policy()->create($user);
    }

    private function policy(): FacultyPolicy
    {
        return app(FacultyPolicy::class);
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
