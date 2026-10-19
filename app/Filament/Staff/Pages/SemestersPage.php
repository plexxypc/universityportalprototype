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
use Filament\Schemas\Components\Text;
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
                    $session_id = (int) ($data['session_id'] ?? 0);
                    $name = $this->text($data, 'name');
                    $is_active = (bool) ($data['is_active'] ?? false);

                    if ($this->queueSwitchConfirmation(0, $session_id, $name, $is_active, $data)) {
                        return;
                    }

                    $this->saveSemester(
                        0,
                        $session_id,
                        $name,
                        $is_active,
                        $data['registration_deadline'] ?? null,
                        $data['add_drop_deadline'] ?? null,
                        (int) ($data['semester_no'] ?? 0),
                    );
                }),
        ];
    }

    /**
     * Confirm a save that also changes which session is current.
     *
     * Kept off the header. A hidden action is treated as disabled and cannot stay mounted.
     */
    public function confirmSemesterSwitchAction(): Action
    {
        return Action::make('confirmSemesterSwitch')
            ->authorize(fn (): bool => $this->canManage())
            ->modalHeading('Save both changes')
            ->modalDescription(function (array $arguments): string {
                $notice = $arguments['notice'] ?? '';

                return is_string($notice) ? $notice : '';
            })
            ->modalSubmitActionLabel('Save both changes')
            ->action(function (array $arguments): void {
                $name = $arguments['name'] ?? '';
                $this->saveSemester(
                    (int) ($arguments['semester_id'] ?? 0),
                    (int) ($arguments['session_id'] ?? 0),
                    is_string($name) ? $name : '',
                    (bool) ($arguments['is_active'] ?? false),
                    $arguments['registration_deadline'] ?? null,
                    $arguments['add_drop_deadline'] ?? null,
                    (int) ($arguments['semester_no'] ?? 0),
                );
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Semester::query()->visibleTo($this->actor())->with('academicSession'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('academicSession.name')->label('Session'),
                TextColumn::make('name')->label('Semester'),
                TextColumn::make('semester_no')->label('Number')->placeholder('Not set'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->authorize(fn (Semester $record): bool => $this->policy()->update($this->actor(), $record))
                    ->fillForm(fn (Semester $record): array => [
                        'session_id' => $record->session_id,
                        'name' => $record->name,
                        'semester_no' => $record->semesterNumber(),
                        'is_active' => $record->is_active,
                        'registration_deadline' => $record->registration_deadline,
                        'add_drop_deadline' => $record->add_drop_deadline,
                    ])
                    ->schema($this->fields())
                    ->action(function (Semester $record, array $data): void {
                        $name = $this->text($data, 'name');
                        $is_active = (bool) ($data['is_active'] ?? false);
                        $session_id = (int) $record->session_id;

                        if ($this->queueSwitchConfirmation((int) $record->getKey(), $session_id, $name, $is_active, $data)) {
                            return;
                        }

                        $this->saveSemester(
                            (int) $record->getKey(),
                            $session_id,
                            $name,
                            $is_active,
                            $data['registration_deadline'] ?? null,
                            $data['add_drop_deadline'] ?? null,
                            (int) ($data['semester_no'] ?? 0),
                        );
                    }),
                DeleteAction::make()
                    ->authorize(fn (Semester $record): bool => $this->policy()->delete($this->actor(), $record))
                    ->action(function (Semester $record): void {
                        $this->deleteSemester((int) $record->getKey());
                    }),
            ]);
    }

    /**
     * Create or update one semester. A forged id is refused and changes nothing.
     */
    public function saveSemester(
        int $semester_id,
        int $session_id,
        string $name,
        bool $is_active,
        mixed $registration_deadline,
        mixed $add_drop_deadline,
        int $semester_no,
    ): void {
        $this->guardManage();

        try {
            $service = app(AcademicService::class);

            if ($semester_id === 0) {
                $service->createSemester(
                    $this->actor(),
                    $session_id,
                    $name,
                    $is_active,
                    $registration_deadline,
                    $add_drop_deadline,
                    $semester_no,
                    request(),
                );
            } else {
                $service->updateSemester(
                    $this->actor(),
                    $semester_id,
                    $name,
                    $is_active,
                    $registration_deadline,
                    $add_drop_deadline,
                    $semester_no,
                    request(),
                );
            }
        } catch (ValidationException $exception) {
            Notification::make()
                ->title($this->firstError($exception, 'That semester could not be saved.'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title('Semester saved')->success()->send();
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
                ->title($this->firstError($exception, 'That semester could not be deleted.'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title('Semester deleted')->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        $components = [];
        $warning = $this->semesterNumberWarning();

        if ($warning !== null) {
            $components[] = Text::make($warning);
        }

        $components[] = EmbeddedTable::make();

        return $schema->components($components);
    }

    /**
     * Shown when the active semester cannot be matched to programme courses.
     */
    public function semesterNumberWarning(): ?string
    {
        $active = Semester::query()->active()->first();

        if ($active instanceof Semester && $active->semesterNumber() === null) {
            return AcademicService::MESSAGE_SEMESTER_NUMBER_MISSING;
        }

        return null;
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
            Select::make('semester_no')
                ->label('Semester number')
                ->options([1 => '1', 2 => '2'])
                ->required(),
            TextInput::make('registration_deadline')->label('Registration deadline'),
            TextInput::make('add_drop_deadline')->label('Add/drop deadline'),
            Toggle::make('is_active')->label('Active semester')->default(true),
        ];
    }

    /**
     * Open the confirmation dialog when this save also switches the current session.
     *
     * @param  array<string, mixed>  $data
     */
    private function queueSwitchConfirmation(int $semester_id, int $session_id, string $name, bool $is_active, array $data): bool
    {
        $notice = app(AcademicService::class)->activationNotice($session_id, $name, $is_active);

        if ($notice === null) {
            return false;
        }

        $this->replaceMountedAction('confirmSemesterSwitch', [
            'semester_id' => $semester_id,
            'session_id' => $session_id,
            'name' => $name,
            'is_active' => true,
            'notice' => $notice,
            'registration_deadline' => $data['registration_deadline'] ?? null,
            'add_drop_deadline' => $data['add_drop_deadline'] ?? null,
            'semester_no' => (int) ($data['semester_no'] ?? 0),
        ]);

        return true;
    }

    /**
     * The first validation sentence, for the danger notice.
     */
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
