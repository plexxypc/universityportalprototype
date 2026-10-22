<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FinanceReport;
use App\Support\Audit;
use App\Support\Rbac\FinanceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV downloads for transactions and reconciliation.
 *
 * fees.view is checked again here. The audit row stores filter ids and
 * the row count, not the cells.
 */
class FinanceExportController extends Controller
{
    public const string ACTION_EXPORTED = 'report.exported';

    public function __construct(
        private readonly FinanceReport $report,
        private readonly Audit $audit,
    ) {}

    /**
     * Stream the transaction list for the query filters.
     */
    public function transactions(Request $request): Response
    {
        $actor = $this->actor($request);
        $filters = $this->transactionFilters($request);
        $query = $this->report->transactions($actor, $filters);
        $count = (clone $query)->count();
        $limit = (int) config('payments.export_row_limit');

        if ($count > $limit) {
            return response(FinanceReport::exportTooLarge(), 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $this->auditExport($actor, 'transactions', $this->auditFilters($filters), $count, $request);

        return $this->download('transactions.csv', function () use ($query): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            $this->report->writeTransactions($query, $handle);
            fclose($handle);
        });
    }

    /**
     * Stream one reconciliation section.
     */
    public function reconciliation(Request $request): Response
    {
        $actor = $this->actor($request);
        $requested = $request->query('section');
        $section = is_string($requested) && in_array($requested, ['stuck', 'review', 'disagreement', 'integrity'], true)
            ? $requested
            : 'stuck';
        $rows = $this->sectionRows($section);
        $count = count($rows);
        $limit = (int) config('payments.export_row_limit');

        if ($count > $limit) {
            return response(FinanceReport::exportTooLarge(), 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $this->auditExport($actor, 'reconciliation', ['section' => $section], $count, $request);

        return $this->download('reconciliation.csv', function () use ($section, $rows): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            $this->report->writeSection($section, $rows, $handle);
            fclose($handle);
        });
    }

    /**
     * @param  callable(): void  $write
     */
    private function download(string $name, callable $write): StreamedResponse
    {
        return response()->streamDownload($write, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array<string, int|string>  $filters
     */
    private function auditExport(User $actor, string $report, array $filters, int $row_count, Request $request): void
    {
        DB::transaction(function () use ($actor, $report, $filters, $row_count, $request): void {
            $this->audit->record(
                (int) $actor->getKey(),
                self::ACTION_EXPORTED,
                'reports',
                null,
                null,
                [
                    'report' => $report,
                    'filters' => $filters,
                    'row_count' => $row_count,
                ],
                $this->audit->ipFromRequest($request),
            );
        });
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User && FinanceAccess::canView($actor), 404);

        return $actor;
    }

    /**
     * @return array<string, string>
     */
    private function transactionFilters(Request $request): array
    {
        $filters = [];

        foreach (['status', 'from', 'to', 'programme_id', 'level', 'provider', 'search'] as $key) {
            $value = $request->query($key);
            $filters[$key] = is_string($value) ? $value : '';
        }

        return $filters;
    }

    /**
     * Ids only. The search text is not stored.
     *
     * @param  array<string, string>  $filters
     * @return array<string, int>
     */
    private function auditFilters(array $filters): array
    {
        return [
            'programme_id' => is_numeric($filters['programme_id']) ? (int) $filters['programme_id'] : 0,
            'level' => is_numeric($filters['level']) ? (int) $filters['level'] : 0,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function sectionRows(string $section): array
    {
        $page = 1;
        $rows = [];
        $count = 0;

        do {
            $batch = match ($section) {
                'stuck' => $this->report->stuckPending($page),
                'review' => $this->report->needsReview($page),
                'disagreement' => $this->report->disagreements($page),
                'integrity' => $this->report->integrity($page),
                default => ['count' => 0, 'rows' => []],
            };
            $count = $batch['count'];

            foreach ($batch['rows'] as $row) {
                $rows[] = $row;
            }

            $page++;
        } while (count($rows) < $count && $batch['rows'] !== [] && $page <= 4);

        return $rows;
    }
}
