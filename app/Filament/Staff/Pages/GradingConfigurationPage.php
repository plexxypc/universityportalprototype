<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\RepeatPolicy;
use App\Models\GradingScheme;
use App\Models\User;
use App\Services\GradingConfigService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use UnitEnum;

/**
 * The active grading scheme. Saving stores a new version.
 *
 * The default numbers appear only when a Super Admin presses the button and
 * no scheme exists. A migration does not load them.
 */
class GradingConfigurationPage extends Page
{
    protected static ?string $slug = 'grading-configuration';

    protected static ?string $navigationLabel = 'Grading';

    protected static string|UnitEnum|null $navigationGroup = 'University setup and configuration';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?int $navigationSort = 8;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The last preview. Null until the button is used. Preview writes nothing.
     *
     * @var array{courses: list<array{total: string, letter: string, points: string, passed: bool}>, gpa: string|null, classification: string|null}|null
     */
    public ?array $preview_lines = null;

    /**
     * Registrar and Exam Officer can open the page. Only Super Admin can save.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && Gate::forUser($user)->allows('grading_configuration.view');
    }

    public function getTitle(): string
    {
        return 'Grading';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->gradingForm()->fill(app(GradingConfigService::class)->formValues());
    }

    /**
     * Store the form as the next scheme version.
     */
    public function save(): void
    {
        $actor = $this->actor();

        if (! $this->canManage()) {
            abort(403);
        }

        try {
            app(GradingConfigService::class)->saveVersion($actor, $this->gradingForm()->getState(), request());
        } catch (ValidationException $exception) {
            throw $this->prefixed($exception);
        }

        $this->gradingForm()->fill(app(GradingConfigService::class)->formValues());

        Notification::make()->title('Grading scheme saved')->success()->send();
    }

    /**
     * Grade the sample courses on the form. The scheme is not saved.
     */
    public function preview(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $this->preview_lines = app(GradingConfigService::class)->preview($this->gradingForm()->getState());
        } catch (ValidationException $exception) {
            $this->preview_lines = null;

            Notification::make()->title($this->firstError($exception, 'Those scores could not be previewed.'))->danger()->send();
        }
    }

    /**
     * Insert the default scheme. The button is the only caller.
     */
    public function loadDefault(): void
    {
        $actor = $this->actor();

        if (! $this->canManage()) {
            abort(403);
        }

        try {
            app(GradingConfigService::class)->loadDefault($actor, request());
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'The default scheme could not be loaded.'))->danger()->send();

            return;
        }

        $this->gradingForm()->fill(app(GradingConfigService::class)->formValues());

        Notification::make()->title('Default scheme loaded')->success()->send();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('loadDefault')
                ->label('Load the default scheme')
                ->visible(fn (): bool => $this->canManage() && ! GradingScheme::query()->exists())
                ->action(fn () => $this->loadDefault()),
        ];
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->maxLength(64)
                ->helperText('Saving stores a new version. Published results keep their scheme. Unpublished results move to the new version.'),
            TextInput::make('pass_mark')
                ->label('Pass mark')
                ->required()
                ->helperText('One hundredth above the fail band. 40.00 means F ends at 39.99.'),
            Select::make('repeat_policy')
                ->label('Repeat policy')
                ->options([
                    RepeatPolicy::Latest->value => 'Latest',
                    RepeatPolicy::Best->value => 'Best',
                ])
                ->required(),
            TextInput::make('resit_points_cap')
                ->label('Resit grade-point cap')
                ->helperText('Leave blank when a resit has no cap.'),
            Repeater::make('components')
                ->label('Assessment components')
                ->schema([
                    TextInput::make('name')->label('Name')->required()->maxLength(64),
                    TextInput::make('max_score')->label('Maximum')->required(),
                ])
                ->minItems(1)
                ->helperText('The maximums must add up to 100.00.'),
            Repeater::make('bands')
                ->label('Grade bands')
                ->schema([
                    TextInput::make('letter')->label('Letter')->required()->maxLength(8),
                    TextInput::make('min_score')->label('From')->required(),
                    TextInput::make('max_score')->label('To')->required(),
                    TextInput::make('points')->label('Grade points')->required(),
                    TextInput::make('remark')->label('Remark')->required()->maxLength(64),
                ])
                ->helperText('Bands run from 0.00 to 100.00 with no gap. The next band starts one hundredth after the previous maximum.'),
            Repeater::make('classifications')
                ->label('Classification')
                ->schema([
                    TextInput::make('name')->label('Class')->required()->maxLength(64),
                    TextInput::make('min_cgpa')->label('From')->required(),
                    TextInput::make('max_cgpa')->label('To')->required(),
                ])
                ->helperText('Classes run from 0.00 to the highest grade point with no gap.'),
            Toggle::make('track_standing')
                ->label('Track academic standing'),
            TextInput::make('warning_cgpa')
                ->label('Warning CGPA'),
            TextInput::make('probation_cgpa')
                ->label('Probation CGPA')
                ->helperText('Leave standing off, or set at least one threshold. Probation must be below warning.'),
            Repeater::make('preview_courses')
                ->label('Sample courses')
                ->schema([
                    TextInput::make('units')->label('Credit units')->required(),
                    TextInput::make('scores.0')->label('First component score')->required(),
                    TextInput::make('scores.1')->label('Second component score'),
                    TextInput::make('scores.2')->label('Third component score'),
                ])
                ->maxItems(3)
                ->helperText('Up to three courses. Scores follow the component order above. Preview does not save the scheme.'),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.staff.grading-preview'),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('preview')
                            ->label('Preview these scores')
                            ->action('preview'),
                        Action::make('save')
                            ->label('Save grading scheme')
                            ->visible(fn (): bool => $this->canManage())
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    /**
     * The signed-in account, or a 403 when the session has none.
     */
    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }

    /**
     * Super Admin only. Registrar and Exam Officer stay on the view.
     */
    private function canManage(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && Gate::forUser($actor)->allows('grading_configuration.manage');
    }

    private function gradingForm(): Schema
    {
        $form = $this->getSchema('form');

        if (! $form instanceof Schema) {
            throw new RuntimeException('The grading form is not available.');
        }

        return $form;
    }

    /**
     * Prefix service errors so Filament shows them on the form.
     */
    private function prefixed(ValidationException $exception): ValidationException
    {
        $messages = [];

        foreach ($exception->errors() as $field => $errors) {
            $messages['data.'.$field] = $errors;
        }

        return ValidationException::withMessages($messages);
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
}
