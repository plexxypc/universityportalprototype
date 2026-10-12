<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Models\AcademicSession;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Programme;
use App\Models\User;
use App\Services\FeeService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Prices for a programme, level, and session.
 *
 * Changing a price does not change invoices that were already issued.
 */
class FeeStructuresPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'fee-structures';

    protected static ?string $navigationLabel = 'Fee structures';

    protected static string|UnitEnum|null $navigationGroup = 'Fees and finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Gate::forUser($user)->allows('fees.view');
    }

    public function getTitle(): string
    {
        return 'Fee structures';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Add price')
                ->visible(fn (): bool => $this->canManage())
                ->schema($this->fields())
                ->action(function (array $data): void {
                    $this->saveStructure(0, $data);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => FeeStructure::query()->with(['feeCategory', 'programme', 'academicSession']))
            ->emptyStateHeading('No fee prices yet')
            ->columns([
                TextColumn::make('feeCategory.name')->label('Category'),
                TextColumn::make('programme.code')->label('Programme'),
                TextColumn::make('level')->label('Level'),
                TextColumn::make('academicSession.name')->label('Session'),
                TextColumn::make('amount_kobo')->label('Amount')->formatStateUsing(
                    fn (mixed $state): string => Money::format((int) $state),
                ),
            ])
            ->recordActions([
                Action::make('edit')
                    ->visible(fn (): bool => $this->canManage())
                    ->fillForm(fn (FeeStructure $record): array => [
                        'fee_category_id' => $record->fee_category_id,
                        'programme_id' => $record->programme_id,
                        'level' => $record->level,
                        'session_id' => $record->session_id,
                        'amount' => $this->naira((int) $record->amount_kobo),
                    ])
                    ->schema($this->fields())
                    ->action(function (FeeStructure $record, array $data): void {
                        $this->saveStructure((int) $record->getKey(), $data);
                    }),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveStructure(int $structure_id, array $data): void
    {
        if (! $this->canManage()) {
            abort(403);
        }

        $actor = $this->actor();
        $service = app(FeeService::class);
        $category_id = is_numeric($data['fee_category_id'] ?? null) ? (int) $data['fee_category_id'] : 0;
        $programme_id = is_numeric($data['programme_id'] ?? null) ? (int) $data['programme_id'] : 0;
        $level = is_numeric($data['level'] ?? null) ? (int) $data['level'] : 0;
        $session_id = is_numeric($data['session_id'] ?? null) ? (int) $data['session_id'] : 0;
        $amount = $this->text($data, 'amount');

        try {
            if ($structure_id === 0) {
                $service->createStructure($actor, $category_id, $programme_id, $level, $session_id, $amount, request());
            } else {
                $service->updateStructure($actor, $structure_id, $category_id, $programme_id, $level, $session_id, $amount, request());
            }
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, 'That price could not be saved.'))->danger()->send();

            return;
        }

        Notification::make()->title('Price saved')->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.staff.fee-structures-note'),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return array<int, Select|TextInput>
     */
    private function fields(): array
    {
        $levels = [];

        foreach ([100, 200, 300, 400, 500, 600] as $level) {
            $levels[$level] = (string) $level;
        }

        return [
            Select::make('fee_category_id')
                ->label('Category')
                ->options(fn (): array => FeeCategory::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required(),
            Select::make('programme_id')
                ->label('Programme')
                ->options(fn (): array => Programme::query()->orderBy('code')->pluck('code', 'id')->all())
                ->required(),
            Select::make('level')->label('Level')->options($levels)->required(),
            Select::make('session_id')
                ->label('Session')
                ->options(fn (): array => AcademicSession::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required(),
            TextInput::make('amount')
                ->label('Amount in naira')
                ->required()
                ->helperText('Changing a price does not change invoices that were already issued.'),
        ];
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }

    private function canManage(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && Gate::forUser($actor)->allows('fees.manage');
    }

    /**
     * Naira text the form can parse again, without a thousands separator.
     */
    private function naira(int $kobo): string
    {
        return intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : (is_numeric($value) ? (string) $value : '');
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
