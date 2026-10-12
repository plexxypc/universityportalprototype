<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Models\AcademicSession;
use App\Models\Semester;
use App\Models\User;
use App\Policies\SemesterPolicy;
use App\Services\AcademicService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Semesters for a Super Admin or Registrar.
 *
 * An active semester always belongs to the current session. Switching to a
 * semester in another session changes both flags in one save.
 */
class SemestersPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'semesters';

    protected static ?string $navigationLabel = 'Semesters';

    protected static string|UnitEnum|null $navigationGroup = 'University setup and configuration';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 3;

    /**
     * Anyone who may view academic structure may open the list.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(SemesterPolicy::class)->viewAny($user);
    }

    public function getTitle(): string
    {
        return 'Semesters';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Add semester')
                ->authorize(fn (): bool => $this->canManage())
                ->schema($this->fields())
                ->action(function (array $data): void {
                    $this->guardManage();
                    app(AcademicService::class)->createSemester(
                        $this->actor(),
                        (int) ($data['session_id'] ?? 0),
                        $this->text($data, 'name'),
                        (bool) ($data['is_active'] ?? false),
                        $data['registration_deadline'] ?? null,
                        $data['add_drop_deadline'] ?? null,
                        request(),
                    );
                    Notification::make()->title('Semester saved')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Semester::query()->visibleTo($this->actor())->with('academicSession'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('academicSession.name')->label('Session'),
                TextColumn::make('name')->label('Semester'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->authorize(fn (Semester $record): bool => $this->policy()->update($this->actor(), $record))
                    ->fillForm(fn (Semester $record): array => [
                        'session_id' => $record->session_id,
                        'name' => $record->name,
                        'is_active' => $record->is_active,
                        'registration_deadline' => $record->registration_deadline,
                        'add_drop_deadline' => $record->add_drop_deadline,
                    ])
                    ->schema($this->fields())
                    ->action(function (Semester $record, array $data): void {
                        $this->guardManage();
                        app(AcademicService::class)->updateSemester(
                            $this->actor(),
                            (int) $record->getKey(),
                            $this->text($data, 'name'),
                            (bool) ($data['is_active'] ?? false),
                            $data['registration_deadline'] ?? null,
                            $data['add_drop_deadline'] ?? null,
                            request(),
                        );
                        Notification::make()->title('Semester saved')->success()->send();
                    }),
                DeleteAction::make()
                    ->authorize(fn (Semester $record): bool => $this->policy()->delete($this->actor(), $record))
                    ->action(function (Semester $record): void {
                        $this->deleteSemester((int) $record->getKey());
                    }),
            ]);
    }

    /**
     * Delete one semester. A forged id is refused and deletes nothing.
     */
    public function deleteSemester(int $semester_id): void
    {
        $this->guardManage();

        try {
            app(AcademicService::class)->deleteSemester($this->actor(), $semester_id, request());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title($exception->errors()['semester'][0] ?? 'That semester could not be deleted.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title('Semester deleted')->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return array<int, Select|TextInput|Toggle>
     */
    private function fields(): array
    {
        return [
            Select::make('session_id')
                ->label('Session')
                ->options(fn (): array => AcademicSession::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required(),
            TextInput::make('name')->label('Name')->required()->maxLength(32),
            TextInput::make('registration_deadline')->label('Registration deadline'),
            TextInput::make('add_drop_deadline')->label('Add/drop deadline'),
            Toggle::make('is_active')->label('Active semester')->default(true),
        ];
    }

    private function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $this->policy()->create($user);
    }

    private function guardManage(): void
    {
        if (! $this->canManage()) {
            throw new AuthorizationException;
        }
    }

    private function policy(): SemesterPolicy
    {
        return app(SemesterPolicy::class);
    }

    private function actor(): User
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $this->policy()->viewAny($user)) {
            abort(403);
        }

        return $user;
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
