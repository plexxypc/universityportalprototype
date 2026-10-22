<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Filament\Staff\Pages\Concerns\RequiresFeesView;
use App\Models\User;
use App\Services\FinanceReport;
use App\Services\PaymentService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnitEnum;

/**
 * Our own records compared with themselves. The provider is not called.
 */
class ReconciliationPage extends Page
{
    use RequiresFeesView;

    protected static ?string $slug = 'reconciliation';

    protected static ?string $navigationLabel = 'Reconciliation';

    protected static string|UnitEnum|null $navigationGroup = 'Fees and finance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 5;

    public string $stuck_state = 'loading';

    public string $review_state = 'loading';

    public string $disagreement_state = 'loading';

    public string $integrity_state = 'loading';

    /** @var list<array<string, string>> */
    public array $stuck_rows = [];

    /** @var list<array<string, string>> */
    public array $review_rows = [];

    /** @var list<array<string, string>> */
    public array $disagreement_rows = [];

    /** @var list<array<string, string>> */
    public array $integrity_rows = [];

    public int $stuck_count = 0;

    public int $review_count = 0;

    public int $disagreement_count = 0;

    public int $integrity_count = 0;

    public int $integrity_page = 1;

    public string $resolve_reference = '';

    public string $resolve_reason = '';

    public function getTitle(): string
    {
        return 'Reconciliation';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.staff.reconciliation'),
        ]);
    }

    /**
     * Load each section on its own so one failure does not blank the page.
     */
    public function loadSections(): void
    {
        abort_unless(static::feesView(), 404);
        $report = app(FinanceReport::class);
        $this->fillSection('stuck', fn (): array => $report->stuckPending(1));
        $this->fillSection('review', fn (): array => $report->needsReview(1));
        $this->fillSection('disagreement', fn (): array => $report->disagreements(1));
        $this->fillSection('integrity', fn (): array => $report->integrity($this->integrity_page));
    }

    /**
     * The next page of ledger rows, still inside the first 50.
     */
    public function integrityPage(int $page): void
    {
        abort_unless(static::feesView(), 404);
        $this->integrity_page = max(1, $page);
        $this->fillSection('integrity', fn (): array => app(FinanceReport::class)->integrity($this->integrity_page));
    }

    /**
     * Close one review. The same service method the invoices page links to.
     */
    public function markResolved(): void
    {
        abort_unless(static::feesManage(), 403);

        try {
            app(PaymentService::class)->resolveReview($this->actor(), $this->resolve_reference, $this->resolve_reason, request());
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->validator->errors()->first() ?: PaymentService::MESSAGE_PAYMENT)->danger()->send();

            return;
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->resolve_reference = '';
        $this->resolve_reason = '';
        $this->loadSections();
        Notification::make()->title('Review resolved')->success()->send();
    }

    /**
     * @param  callable(): array{count: int, rows: list<array<string, string>>}  $load
     */
    private function fillSection(string $name, callable $load): void
    {
        try {
            $result = $load();
            $this->{$name.'_rows'} = $result['rows'];
            $this->{$name.'_count'} = $result['count'];
            $this->{$name.'_state'} = $result['count'] === 0 ? 'empty' : 'ready';
        } catch (Throwable) {
            $this->{$name.'_rows'} = [];
            $this->{$name.'_count'} = 0;
            $this->{$name.'_state'} = 'error';
        }
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(404);
        }

        return $actor;
    }
}
