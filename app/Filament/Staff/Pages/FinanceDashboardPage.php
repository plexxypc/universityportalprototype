<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Filament\Staff\Pages\Concerns\RequiresFeesView;
use App\Models\AcademicSession;
use App\Services\FinanceReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/**
 * Collected and outstanding figures for one session.
 *
 * Pending payments are not collected. Cancelled invoices are left out.
 * Reversed payments are not Successful, so they are not collected.
 */
class FinanceDashboardPage extends Page
{
    use RequiresFeesView;

    protected static ?string $slug = 'finance';

    protected static ?string $navigationLabel = 'Finance';

    protected static string|UnitEnum|null $navigationGroup = 'Fees and finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 6;

    public string $session_id = '';

    public string $state = 'loading';

    /** @var array<string, mixed> */
    public array $figures = [];

    public function mount(): void
    {
        $current = AcademicSession::query()->where('is_current', true)->first();
        $this->session_id = $current instanceof AcademicSession ? (string) $current->getKey() : '';
    }

    public function getTitle(): string
    {
        return 'Finance';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.staff.finance-dashboard'),
        ]);
    }

    /**
     * Load the figures for the selected session.
     */
    public function loadDashboard(): void
    {
        abort_unless(static::feesView(), 404);

        try {
            $this->figures = app(FinanceReport::class)->dashboard($this->selectedSessionId());
            $this->state = 'ready';
        } catch (Throwable) {
            $this->figures = [];
            $this->state = 'error';
        }
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function sessionOptions(): array
    {
        $options = [];

        foreach (AcademicSession::query()->orderBy('name')->limit(50)->get(['id', 'name']) as $session) {
            $options[] = ['id' => (int) $session->getKey(), 'name' => (string) $session->name];
        }

        return $options;
    }

    private function selectedSessionId(): int
    {
        return is_numeric($this->session_id) ? (int) $this->session_id : 0;
    }
}
