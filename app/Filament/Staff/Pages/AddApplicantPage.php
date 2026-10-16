<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\ApplicantStatus;
use App\Enums\Gender;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Programme;
use App\Models\User;
use App\Policies\ApplicantPolicy;
use App\Services\ApplicantService;
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
 * Add or correct one applicant.
 *
 * Only a Super Admin or a Registrar can open this page. The service checks
 * that again. A converted applicant is not edited here.
 */
class AddApplicantPage extends Page
{
    protected static ?string $slug = 'applicants/create';

    protected static ?string $navigationLabel = 'Add applicant';

    protected static string|UnitEnum|null $navigationGroup = 'Admissions and onboarding';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?int $navigationSort = 2;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?int $applicant_id = null;

    /**
     * Super Admin and Registrar. Other staff roles are refused.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ApplicantPolicy::class)->create($user);
    }

    public function getTitle(): string
    {
        return $this->applicant_id === null ? 'Add applicant' : 'Edit applicant';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $raw = request()->query('applicant');
        $this->applicantForm()->fill();

        if ($raw === null || $raw === '') {
            return;
        }

        if (! is_numeric($raw)) {
            abort(404);
        }

        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $applicant = Applicant::query()->visibleTo($actor)->whereKey((int) $raw)->first();

        if (! $applicant instanceof Applicant || $applicant->status === ApplicantStatus::Converted) {
            abort(404);
        }

        $this->applicant_id = (int) $applicant->getKey();
        $this->applicantForm()->fill([
            'first_name' => $applicant->first_name,
            'last_name' => $applicant->last_name,
            'other_names' => $applicant->other_names,
            'email' => $applicant->email,
            'phone' => $applicant->phone,
            'gender' => $applicant->gender?->value,
            'date_of_birth' => $applicant->date_of_birth?->toDateString(),
            'state_of_origin' => $applicant->state_of_origin,
            'address' => $applicant->address,
            'programme_id' => $applicant->programme_id,
            'level' => $applicant->level,
            'entry_session_id' => $applicant->entry_session_id,
        ]);
    }

    /**
     * Create or update the applicant.
     */
    public function save(): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! app(ApplicantPolicy::class)->create($actor)) {
            abort(403);
        }

        $state = $this->applicantForm()->getState();

        if (($state['entry_session_id'] ?? '') === '') {
            $state['entry_session_id'] = null;
        }

        try {
            if ($this->applicant_id === null) {
                app(ApplicantService::class)->create($actor, $state, request());
            } else {
                app(ApplicantService::class)->update($actor, $this->applicant_id, $state, request());
            }
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $messages = [];

            foreach ($exception->errors() as $field => $errors) {
                $messages['data.'.$field] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }

        $this->applicant_id = null;
        $this->applicantForm()->fill();

        Notification::make()
            ->success()
            ->title('Applicant saved.')
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
                )->all()),
            DatePicker::make('date_of_birth')->label('Date of birth')->native(false),
            Select::make('state_of_origin')
                ->label('State of origin')
                ->options(collect(NigerianStates::names())->mapWithKeys(
                    fn (string $name): array => [$name => $name],
                )->all())
                ->searchable(),
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
                            ->label($this->applicant_id === null ? 'Add applicant' : 'Save applicant')
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    /**
     * The form schema named form.
     */
    private function applicantForm(): Schema
    {
        $form = $this->getSchema('form');

        if (! $form instanceof Schema) {
            throw new RuntimeException('The applicant form is not available.');
        }

        return $form;
    }

    /**
     * @return array<int, string>
     */
    private function programmeOptions(): array
    {
        return Programme::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
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
}
