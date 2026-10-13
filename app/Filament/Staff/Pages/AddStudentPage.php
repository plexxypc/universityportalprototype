<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\Gender;
use App\Models\AcademicSession;
use App\Models\Programme;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Services\CreatedStudent;
use App\Services\CredentialDelivery;
use App\Services\StudentService;
use App\Support\NigerianStates;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use UnitEnum;

/**
 * Add one student. The list and the record page are later tasks.
 *
 * Only a Super Admin or a Registrar can open this page. The service
 * checks that again. The temporary password is never shown.
 */
class AddStudentPage extends Page
{
    protected static ?string $slug = 'students/create';

    protected static ?string $navigationLabel = 'Add student';

    protected static string|UnitEnum|null $navigationGroup = 'Student information management';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?int $navigationSort = 1;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Super Admin and Registrar. Other staff roles are refused.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(StudentPolicy::class)->create($user);
    }

    public function getTitle(): string
    {
        return 'Add student';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->studentForm()->fill();
    }

    /**
     * Create the student and say whether the welcome email went out.
     */
    public function save(): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! app(StudentPolicy::class)->create($actor)) {
            abort(403);
        }

        $state = $this->studentForm()->getState();

        if (($state['entry_session_id'] ?? '') === '') {
            $state['entry_session_id'] = null;
        }

        try {
            $created = app(StudentService::class)->create($actor, $state, request());
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $messages = [];

            foreach ($exception->errors() as $field => $errors) {
                $messages['data.'.$field] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }

        $this->studentForm()->fill();

        Notification::make()
            ->success()
            ->title($this->createdMessage($created))
            ->send();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('first_name')->label('First name')->required()->maxLength(100),
            TextInput::make('last_name')->label('Last name')->required()->maxLength(100),
            TextInput::make('other_names')->label('Other names')->maxLength(100),
            TextInput::make('email')->label('Email')->email()->required()->maxLength(255),
            TextInput::make('phone')
                ->label('Phone')
                ->tel()
                ->maxLength(32)
                ->helperText('A Nigerian number, for example 08012345678.'),
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
                ->options(fn (): array => $this->programmeOptions())
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('level', null)),
            Select::make('level')
                ->label('Level')
                ->options(fn (Get $get): array => $this->levelOptions($get('programme_id')))
                ->required(),
            Select::make('entry_session_id')
                ->label('Entry session')
                ->options(fn (): array => AcademicSession::query()->orderByDesc('name')->pluck('name', 'id')->all())
                ->placeholder('Current session')
                ->helperText('Leave blank to use the current academic session.'),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Add student')
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    /**
     * The form schema named form.
     */
    private function studentForm(): Schema
    {
        $form = $this->getSchema('form');

        if (! $form instanceof Schema) {
            throw new RuntimeException('The add-student form is not available.');
        }

        return $form;
    }

    /**
     * Programmes this account is allowed to see.
     *
     * @return array<int, string>
     */
    private function programmeOptions(): array
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return [];
        }

        return Programme::query()
            ->visibleTo($actor)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Programme $programme): array => [
                (int) $programme->id => $programme->name,
            ])
            ->all();
    }

    /**
     * Levels from 100 up to the programme length, and never past 600.
     *
     * @return array<int, string>
     */
    private function levelOptions(mixed $programme_id): array
    {
        $programme = is_numeric($programme_id)
            ? Programme::query()->find((int) $programme_id)
            : null;

        if (! $programme instanceof Programme) {
            return [];
        }

        $years = min((int) $programme->duration_years, 6);
        $options = [];

        for ($year = 1; $year <= $years; $year++) {
            $level = $year * 100;
            $options[$level] = (string) $level;
        }

        return $options;
    }

    /**
     * The on-screen result. It names the matric number and the email outcome.
     */
    private function createdMessage(CreatedStudent $created): string
    {
        $matric = $created->student->matric_no;
        $email = match ($created->delivery->state) {
            CredentialDelivery::SENT => 'The welcome email was sent.',
            CredentialDelivery::NOT_SENT => 'The welcome email was not sent.',
            default => 'The welcome email is queued.',
        };

        return 'Student '.$matric.' was created. '.$email;
    }
}
