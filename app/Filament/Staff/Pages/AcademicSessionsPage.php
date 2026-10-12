<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Models\AcademicSession;
use App\Models\User;
use App\Policies\AcademicSessionPolicy;
use App\Services\AcademicService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
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
 * Academic sessions for a Super Admin or Registrar.
 *
 * Faculty Admin and Department Officer may open the list. They cannot
 * create, switch, or delete a session.
 */
class AcademicSessionsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'academic-sessions';

    protected static ?string $navigationLabel = 'Academic sessions';

    protected static string|UnitEnum|null $navigationGroup = 'University setup and configuration';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?int $navigationSort = 2;

    /**
     * Anyone who may view academic structure may open the list.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AcademicSessionPolicy::class)->viewAny($user);
    }

    public function getTitle(): string
    {
        return 'Academic sessions';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Add session')
                ->authorize(fn (): bool => $this->canManage())
                ->schema([
                    TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->maxLength(9)
                        ->helperText('Use 2026/2027. The second year is the next year.'),
                    Toggle::make('is_current')
                        ->label('Current session')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    $this->guardManage();
                    app(AcademicService::class)->createSession(
                        $this->actor(),
                        $this->text($data, 'name'),
                        (bool) ($data['is_current'] ?? false),
                        request(),
                    );
                    Notification::make()->title('Session saved')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AcademicSession::query()->visibleTo($this->actor()))
            ->defaultSort('name', 'desc')
            ->columns([
                TextColumn::make('name')->label('Session'),
                IconColumn::make('is_current')->label('Current')->boolean(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->authorize(fn (AcademicSession $record): bool => $this->policy()->update($this->actor(), $record))
                    ->fillForm(fn (AcademicSession $record): array => [
                        'name' => $record->name,
                        'is_current' => $record->is_current,
                    ])
                    ->schema([
                        TextInput::make('name')->label('Name')->required()->maxLength(9),
                        Toggle::make('is_current')->label('Current session'),
                    ])
                    ->action(function (AcademicSession $record, array $data): void {
                        $this->guardManage();
                        app(AcademicService::class)->updateSession(
                            $this->actor(),
                            (int) $record->getKey(),
                            $this->text($data, 'name'),
                            (bool) ($data['is_current'] ?? false),
                            request(),
                        );
                        Notification::make()->title('Session saved')->success()->send();
                    }),
                DeleteAction::make()
                    ->authorize(fn (AcademicSession $record): bool => $this->policy()->delete($this->actor(), $record))
                    ->action(function (AcademicSession $record): void {
                        $this->deleteSession((int) $record->getKey());
                    }),
            ]);
    }

    /**
     * Delete one session. A forged id is refused and deletes nothing.
     */
    public function deleteSession(int $session_id): void
    {
        $this->guardManage();

        try {
            app(AcademicService::class)->deleteSession($this->actor(), $session_id, request());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title($exception->errors()['session'][0] ?? 'That session could not be deleted.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title('Session deleted')->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * Whether the signed-in account may change sessions.
     */
    private function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $this->policy()->create($user);
    }

    /**
     * Refuse a write from an account that may only view the calendar.
     */
    private function guardManage(): void
    {
        if (! $this->canManage()) {
            throw new AuthorizationException;
        }
    }

    private function policy(): AcademicSessionPolicy
    {
        return app(AcademicSessionPolicy::class);
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
