<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Filament\Staff\Pages\Concerns\RequiresFeesView;
use App\Models\Payment;
use App\Models\Programme;
use App\Models\User;
use App\Services\FinanceReport;
use App\Support\Money;
use App\Support\StatusTone;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Payment list. Filters and exact search stay on the server.
 *
 * Super Admin and Bursar manage from the detail page. Registrar can read.
 */
class TransactionsPage extends Page implements HasTable
{
    use InteractsWithTable;
    use RequiresFeesView;

    protected static ?string $slug = 'transactions';

    protected static ?string $navigationLabel = 'Transactions';

    protected static string|UnitEnum|null $navigationGroup = 'Fees and finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 4;

    public function getTitle(): string
    {
        return 'Transactions';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.staff.transactions-export'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => app(FinanceReport::class)->transactions($this->actor(), $this->transactionFilters()))
            ->paginated([FinanceReport::PAGE_SIZE])
            ->defaultPaginationPageOption(FinanceReport::PAGE_SIZE)
            ->emptyStateHeading('No transactions yet')
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->url(fn (Payment $record): string => TransactionPage::getUrl(['payment' => $record->getKey()])),
                TextColumn::make('student.user.name')
                    ->label('Student')
                    ->formatStateUsing(function (mixed $state, Payment $record): string {
                        $name = is_string($state) && $state !== ''
                            ? $state
                            : trim((string) $record->student?->first_name.' '.(string) $record->student?->last_name);

                        return trim($name.' '.(string) $record->student?->matric_no);
                    }),
                TextColumn::make('invoice.number')->label('Invoice'),
                TextColumn::make('amount_kobo')->label('Amount')->formatStateUsing(
                    fn (mixed $state): string => Money::format((int) $state),
                ),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(function (PaymentStatus|string $state): string {
                        $status = $state instanceof PaymentStatus ? $state : PaymentStatus::tryFrom((string) $state);

                        return $status instanceof PaymentStatus ? StatusTone::for($status) : StatusTone::NEUTRAL;
                    }),
                TextColumn::make('created_at')->label('Date')->dateTime('Y-m-d H:i'),
            ])
            ->filters([
                Filter::make('status')->schema([
                    Select::make('status')->label('Status')->options(
                        collect(PaymentStatus::cases())->mapWithKeys(fn (PaymentStatus $status): array => [$status->value => $status->value])->all(),
                    ),
                ]),
                Filter::make('dates')->schema([
                    DatePicker::make('from')->label('From'),
                    DatePicker::make('to')->label('To'),
                ]),
                Filter::make('programme')->schema([
                    Select::make('programme_id')->label('Programme')->options(
                        fn (): array => Programme::query()->orderBy('code')->pluck('code', 'id')->all(),
                    ),
                    Select::make('level')->label('Level')->options([
                        100 => '100', 200 => '200', 300 => '300', 400 => '400', 500 => '500', 600 => '600',
                    ]),
                ]),
                Filter::make('provider')->schema([
                    Select::make('provider')->label('Provider')->options(
                        collect(PaymentGateway::cases())->mapWithKeys(fn (PaymentGateway $gateway): array => [$gateway->value => $gateway->value])->all(),
                    ),
                ]),
                Filter::make('search')->schema([
                    TextInput::make('search')->label('Reference, invoice, or matric'),
                ]),
            ]);
    }

    /**
     * Download address for the current filters.
     */
    public function exportUrl(): string
    {
        return route('staff.downloads.finance.transactions', $this->transactionFilters());
    }

    /**
     * Filters currently on the table. Free text stays out of the audit row.
     *
     * @return array<string, string>
     */
    public function transactionFilters(): array
    {
        $filters = is_array($this->tableFilters) ? $this->tableFilters : [];

        return [
            'status' => $this->filterText($filters, 'status', 'status'),
            'from' => $this->filterText($filters, 'dates', 'from'),
            'to' => $this->filterText($filters, 'dates', 'to'),
            'programme_id' => $this->filterText($filters, 'programme', 'programme_id'),
            'level' => $this->filterText($filters, 'programme', 'level'),
            'provider' => $this->filterText($filters, 'provider', 'provider'),
            'search' => $this->filterText($filters, 'search', 'search'),
        ];
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(404);
        }

        return $actor;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filterText(array $filters, string $group, string $key): string
    {
        $group_values = $filters[$group] ?? [];

        if (! is_array($group_values)) {
            return '';
        }

        $value = $group_values[$key] ?? '';

        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }
}
