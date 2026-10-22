<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\InvoiceAdjustmentType;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\Programme;
use App\Models\User;
use App\Services\InvoiceService;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Invoice list, bulk issue, cancel, and adjustments.
 *
 * Registrar can view and preview. Bursar can issue and add an adjustment.
 * Only a Super Admin can remove an adjustment.
 */
class InvoicesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'invoices';

    protected static ?string $navigationLabel = 'Invoices';

    protected static string|UnitEnum|null $navigationGroup = 'Fees and finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 3;

    public string $programme_id = '';

    public string $level = '';

    public string $session_id = '';

    public int $after_student_id = 0;

    public string $filter_key = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && Gate::forUser($user)->allows('fees.view');
    }

    public function getTitle(): string
    {
        return 'Invoices';
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
            Action::make('preview')
                ->label('Preview')
                ->schema($this->filterFields())
                ->action(function (array $data): void {
                    $this->fillFilters($data);
                    $this->previewInvoices();
                }),
            Action::make('confirm')
                ->label('Create the next 25')
                ->visible(fn (): bool => $this->canManage())
                ->schema($this->filterFields())
                ->action(function (array $data): void {
                    $this->fillFilters($data);
                    $this->confirmInvoices();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Invoice::query()->visibleTo($this->actor())->with(['student', 'academicSession']))
            ->emptyStateHeading('No invoices yet')
            ->columns([
                TextColumn::make('number')->label('Number'),
                TextColumn::make('student.matric_no')->label('Matric number'),
                TextColumn::make('academicSession.name')->label('Session'),
                TextColumn::make('status')->label('Status'),
                TextColumn::make('total_kobo')->label('Total')->formatStateUsing(
                    fn (mixed $state): string => Money::format((int) $state),
                ),
                TextColumn::make('outstanding')->label('Outstanding')->state(
                    fn (Invoice $record): string => Money::format(InvoiceService::outstandingKobo($record)),
                ),
            ])
            ->recordActions([
                Action::make('adjust')
                    ->label('Add adjustment')
                    ->visible(fn (): bool => $this->canManage())
                    ->schema([
                        Select::make('type')
                            ->label('Type')
                            ->options([
                                InvoiceAdjustmentType::Scholarship->value => 'Scholarship',
                                InvoiceAdjustmentType::Discount->value => 'Discount',
                                InvoiceAdjustmentType::Waiver->value => 'Waiver',
                            ])
                            ->required(),
                        TextInput::make('amount')->label('Amount in naira')->required(),
                        TextInput::make('reason')->label('Reason')->required(),
                    ])
                    ->action(function (Invoice $record, array $data): void {
                        $this->addInvoiceAdjustment(
                            (int) $record->getKey(),
                            $this->text($data, 'type'),
                            $this->text($data, 'amount'),
                            $this->text($data, 'reason'),
                        );
                    }),
                Action::make('removeAdjustment')
                    ->label('Remove adjustment')
                    ->visible(fn (): bool => $this->canRemoveAdjustment())
                    ->schema([
                        TextInput::make('adjustment_id')->label('Adjustment')->required(),
                        TextInput::make('reason')->label('Reason')->required(),
                    ])
                    ->action(function (Invoice $record, array $data): void {
                        $adjustment_id = $this->text($data, 'adjustment_id');
                        $this->removeInvoiceAdjustment(
                            (int) $record->getKey(),
                            is_numeric($adjustment_id) ? (int) $adjustment_id : 0,
                            $this->text($data, 'reason'),
                        );
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->visible(fn (Invoice $record): bool => $this->canManage() && $record->status !== InvoiceStatus::Cancelled)
                    ->schema([
                        TextInput::make('reason')->label('Reason')->required(),
                    ])
                    ->action(function (Invoice $record, array $data): void {
                        $this->cancelInvoice((int) $record->getKey(), $this->text($data, 'reason'));
                    }),
                Action::make('regenerate')
                    ->label('Regenerate')
                    ->visible(fn (Invoice $record): bool => $this->canManage() && $record->status !== InvoiceStatus::Cancelled)
                    ->schema([
                        TextInput::make('reason')->label('Reason')->required(),
                    ])
                    ->action(function (Invoice $record, array $data): void {
                        $this->regenerateInvoice((int) $record->getKey(), $this->text($data, 'reason'));
                    }),
            ]);
    }

    /**
     * Classify the selected programme, level, and session.
     */
    public function previewInvoices(): void
    {
        [$programme_id, $level, $session_id] = $this->selectedFilters();

        try {
            $preview = app(InvoiceService::class)->preview($this->actor(), $programme_id, $level, $session_id);
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, InvoiceService::MESSAGE_PROGRAMME))->danger()->send();

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        Notification::make()->title($this->counts(
            'Will create',
            count($preview->will_create),
            count($preview->already_invoiced),
            count($preview->no_fee_structure),
            false,
        ))->success()->send();
    }

    /**
     * Issue the next 25 invoices. The cursor stays on this page.
     */
    public function confirmInvoices(): void
    {
        abort_unless($this->canManage(), 403);
        [$programme_id, $level, $session_id] = $this->selectedFilters();

        try {
            $chunk = app(InvoiceService::class)->confirmChunk(
                $this->actor(),
                $programme_id,
                $level,
                $session_id,
                $this->after_student_id,
                request(),
            );
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, InvoiceService::MESSAGE_PROGRAMME))->danger()->send();

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        if ($chunk->last_student_id > 0) {
            $this->after_student_id = $chunk->last_student_id;
        }

        Notification::make()->title($this->counts(
            'Created',
            $chunk->created,
            $chunk->already_invoiced,
            $chunk->no_fee_structure,
            $chunk->done,
        ))->success()->send();
    }

    /**
     * Cancel one invoice. A missing id uses the same message as a forged id.
     */
    public function cancelInvoice(int $invoice_id, string $reason): void
    {
        $this->changeInvoice(
            $invoice_id,
            $reason,
            fn (User $actor, int $id, string $text): mixed => app(InvoiceService::class)->cancel($actor, $id, $text, request()),
            'Invoice cancelled',
        );
    }

    /**
     * Cancel one invoice and issue a replacement.
     */
    public function regenerateInvoice(int $invoice_id, string $reason): void
    {
        $this->changeInvoice(
            $invoice_id,
            $reason,
            fn (User $actor, int $id, string $text): mixed => app(InvoiceService::class)->regenerate($actor, $id, $text, request()),
            'Invoice regenerated',
        );
    }

    /**
     * Add a scholarship, discount, or waiver.
     */
    public function addInvoiceAdjustment(int $invoice_id, string $type, string $amount, string $reason): void
    {
        abort_unless($this->canManage(), 403);

        try {
            app(InvoiceService::class)->addAdjustment($this->actor(), $invoice_id, $type, $amount, $reason, request());
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, InvoiceService::MESSAGE_MISSING))->danger()->send();

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        Notification::make()->title('Adjustment saved')->success()->send();
    }

    /**
     * Remove one adjustment. Super Admin only.
     */
    public function removeInvoiceAdjustment(int $invoice_id, int $adjustment_id, string $reason): void
    {
        abort_unless($this->canRemoveAdjustment(), 403);

        try {
            app(InvoiceService::class)->removeAdjustment($this->actor(), $invoice_id, $adjustment_id, $reason, request());
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, InvoiceService::MESSAGE_ADJUSTMENT))->danger()->send();

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        Notification::make()->title('Adjustment removed')->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.staff.invoices-note'),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fillFilters(array $data): void
    {
        $this->programme_id = $this->text($data, 'programme_id');
        $this->level = $this->text($data, 'level');
        $this->session_id = $this->text($data, 'session_id');
    }

    /**
     * Programme, level, and session. A changed filter starts the cursor again.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function selectedFilters(): array
    {
        $programme_id = $this->filterId($this->programme_id);
        $level = $this->filterId($this->level);
        $session_id = $this->filterId($this->session_id);
        $key = $programme_id.'|'.$level.'|'.$session_id;

        if ($key !== $this->filter_key) {
            $this->filter_key = $key;
            $this->after_student_id = 0;
        }

        return [$programme_id, $level, $session_id];
    }

    /**
     * @param  callable(User, int, string): mixed  $change
     */
    private function changeInvoice(int $invoice_id, string $reason, callable $change, string $saved): void
    {
        abort_unless($this->canManage(), 403);

        try {
            $change($this->actor(), $invoice_id, $reason);
        } catch (ValidationException $exception) {
            Notification::make()->title($this->firstError($exception, InvoiceService::MESSAGE_MISSING))->danger()->send();

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        Notification::make()->title($saved)->success()->send();
    }

    private function counts(string $lead, int $created, int $already_invoiced, int $no_fee_structure, bool $done): string
    {
        $message = $lead.' '.$created.'. Already invoiced '.$already_invoiced.'. No fee structure '.$no_fee_structure.'.';

        if ($done) {
            $message .= ' That was the last group.';
        }

        return $message;
    }

    /**
     * @return array<int, Select|TextInput>
     */
    private function filterFields(): array
    {
        $levels = [];

        foreach ([100, 200, 300, 400, 500, 600] as $level) {
            $levels[$level] = (string) $level;
        }

        return [
            Select::make('programme_id')
                ->label('Programme')
                ->options(fn (): array => Programme::query()->orderBy('code')->pluck('code', 'id')->all())
                ->required(),
            Select::make('level')->label('Level')->options($levels)->required(),
            Select::make('session_id')
                ->label('Session')
                ->options(fn (): array => AcademicSession::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required(),
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

    private function canRemoveAdjustment(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->hasRole(Role::SuperAdmin);
    }

    private function filterId(string $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
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
