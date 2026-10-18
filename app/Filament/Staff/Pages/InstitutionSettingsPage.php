<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Models\InstitutionSetting;
use App\Models\User;
use App\Policies\InstitutionSettingPolicy;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use App\Support\MatricPattern;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use UnitEnum;

/**
 * Institution profile for a Super Admin.
 *
 * The form is filled from the effective settings, so the first save writes
 * what was on screen. A blank table therefore does not replace a config
 * value with a column default.
 */
class InstitutionSettingsPage extends Page
{
    protected static ?string $slug = 'institution-settings';

    protected static ?string $navigationLabel = 'Institution settings';

    protected static string|UnitEnum|null $navigationGroup = 'University setup and configuration';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 1;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Super Admin only. The permission key is users_roles_settings.manage.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(InstitutionSettingPolicy::class)->viewAny($user);
    }

    public function getTitle(): string
    {
        return 'Institution settings';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->settingsForm()->fill(app(SettingsService::class)->formValues());
    }

    public function save(): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $policy = app(InstitutionSettingPolicy::class);
        $existing = InstitutionSetting::query()->first();
        $allowed = $existing instanceof InstitutionSetting
            ? $policy->update($actor, $existing)
            : $policy->create($actor);

        abort_unless($allowed, 403);

        $state = $this->settingsForm()->getState();
        $logo = $state['logo'] ?? null;
        unset($state['logo']);

        if (is_array($logo)) {
            $logo = $logo[0] ?? null;
        }

        try {
            app(SettingsService::class)->save(
                $actor,
                $state,
                $logo instanceof UploadedFile ? $logo : null,
                request(),
            );
        } catch (ValidationException $exception) {
            $messages = [];

            foreach ($exception->errors() as $field => $errors) {
                $messages['data.'.$field] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }

        $this->settingsForm()->fill(app(SettingsService::class)->formValues());

        Notification::make()
            ->success()
            ->title('Institution settings saved')
            ->send();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        $max_kilobytes = config('portal.uploads.logo_max_kilobytes');

        return $schema->components([
            View::make('filament.staff.institution-settings-intro'),
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->label('Short code')
                ->required()
                ->maxLength(32)
                ->helperText('Stored in upper case.'),
            TextInput::make('motto')
                ->label('Motto')
                ->maxLength(255),
            Textarea::make('address')
                ->label('Address')
                ->rows(3)
                ->maxLength(2000),
            TextInput::make('phone')
                ->label('Phone')
                ->tel()
                ->maxLength(32),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->maxLength(255),
            TextInput::make('matric_pattern')
                ->label('Matric pattern')
                ->required()
                ->maxLength(64)
                ->helperText(MatricPattern::help()),
            TextInput::make('min_units')
                ->label('Minimum units')
                ->required()
                ->numeric()
                ->minValue(1)
                ->maxValue(255),
            TextInput::make('max_units')
                ->label('Maximum units')
                ->required()
                ->numeric()
                ->minValue(1)
                ->maxValue(255),
            TextInput::make('attendance_threshold')
                ->label('Attendance threshold')
                ->required()
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%'),
            Toggle::make('approval_required')
                ->label('Registration approval required'),
            Toggle::make('withhold_results_for_debt')
                ->label('Withhold results for outstanding fees'),
            Toggle::make('require_minimum_payment')
                ->label('Require a minimum payment before registration')
                ->helperText(RegistrationService::MESSAGE_PAYMENT_UNAVAILABLE),
            FileUpload::make('logo')
                ->label('Logo')
                ->acceptedFileTypes(['image/png', 'image/jpeg'])
                ->maxSize(is_int($max_kilobytes) ? $max_kilobytes : 1024)
                ->storeFiles(false)
                ->helperText('PNG or JPEG, up to 1 MB, at most 2000 by 2000 pixels. SVG files are rejected.'),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->formContent(),
        ]);
    }

    /**
     * The form schema named form. Filament resolves it from form().
     */
    private function settingsForm(): Schema
    {
        $form = $this->getSchema('form');

        if (! $form instanceof Schema) {
            throw new RuntimeException('The institution settings form is not available.');
        }

        return $form;
    }

    /**
     * The settings form and its save button.
     */
    private function formContent(): Form
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label('Save settings')
                        ->submit('save'),
                ]),
            ]);
    }
}
