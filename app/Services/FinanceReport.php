<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\AcademicSession;
use App\Models\CourseRegistration;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Support\CsvSafe;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Staff reads of payments, reconciliation, and the finance dashboard.
 *
 * These queries use our rows only. They do not call a payment provider.
 * A payment becomes Successful only through PaymentService::verifyAndSettle.
 */
final class FinanceReport
{
    public const string EMPTY = 'Nothing to review';

    public const string ERROR = 'This section could not be loaded.';

    public const string PENDING_NOTE = 'Pending payments are not counted as collected.';

    public const int PAGE_SIZE = 25;

    public const int OFFENCE_CAP = 50;

    /**
     * Payments the actor may see, with the student and invoice loaded.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Payment>
     */
    public function transactions(User $actor, array $filters): Builder
    {
        $query = Payment::query()
            ->visibleTo($actor)
            ->with(['student.user', 'invoice'])
            ->select('payments.*');

        $this->applyTransactionFilters($query, $filters);

        return $query->orderByDesc('payments.id');
    }

    /**
     * Pending payments whose expiry has passed.
     *
     * @return array{count: int, rows: list<array<string, string>>}
     */
    public function stuckPending(int $page): array
    {
        $query = Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->where('expires_at', '<', now())
            ->orderBy('payments.id');

        return $this->pagePayments($query, $page);
    }

    /**
     * Open review events: overpayment, mismatch, and held-back codes.
     *
     * @return array{count: int, rows: list<array<string, string>>}
     */
    public function needsReview(int $page): array
    {
        $query = Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->whereRaw(
                "exists (select 1 from `payment_events` where `payment_events`.`payment_id` = `payments`.`id` and (`payment_events`.`event_key` = concat('overpayment_review:', `payments`.`reference`) or `payment_events`.`event_key` = concat('mismatch:', `payments`.`reference`)))",
            )
            ->whereRaw(
                "not exists (select 1 from `payment_events` where `payment_events`.`payment_id` = `payments`.`id` and `payment_events`.`event_key` = concat('review_resolved:', `payments`.`reference`))",
            )
            ->orderBy('payments.id');

        return $this->pagePayments($query, $page);
    }

    /**
     * Latest whitelisted result that does not match the payment status.
     *
     * @return array{count: int, rows: list<array<string, string>>}
     */
    public function disagreements(int $page): array
    {
        $result = "json_unquote(json_extract(`latest`.`payload`, '$.result'))";
        $query = Payment::query()
            ->joinSub($this->latestEvents(), 'latest', 'latest.payment_id', '=', 'payments.id')
            ->where(function ($inner) use ($result): void {
                $inner->whereRaw($result.' = ? and `payments`.`status` <> ?', ['success', PaymentStatus::Successful->value])
                    ->orWhereRaw(
                        $result.' in (?, ?, ?) and `payments`.`status` = ?',
                        ['failed', 'cancelled', 'pending', PaymentStatus::Successful->value],
                    );
            })
            ->select('payments.*')
            ->orderBy('payments.id');

        return $this->pagePayments($query, $page);
    }

    /**
     * Set-based ledger checks. The count is the full set. The rows are the first 50.
     *
     * @return array{count: int, rows: list<array<string, string>>}
     */
    public function integrity(int $page): array
    {
        $count = (int) DB::query()->fromSub($this->integrityUnion(), 'offences')->count();
        $offset = max(0, ($page - 1) * self::PAGE_SIZE);

        if ($offset >= self::OFFENCE_CAP) {
            return ['count' => $count, 'rows' => []];
        }

        $limit = min(self::PAGE_SIZE, self::OFFENCE_CAP - $offset);
        $rows = DB::query()
            ->fromSub($this->integrityUnion(), 'offences')
            ->orderBy('row_id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $shown = [];

        foreach ($rows as $row) {
            $shown[] = [
                'kind' => CsvSafe::cell((string) $row->kind),
                'row_id' => (string) $row->row_id,
            ];
        }

        return ['count' => $count, 'rows' => $shown];
    }

    /**
     * Collected, outstanding, and the collection rate for one session.
     *
     * @return array{
     *     collected: string,
     *     outstanding: string,
     *     rate: string,
     *     review_count: int,
     *     recent: list<array<string, string>>,
     *     breakdown: list<array<string, string>>
     * }
     */
    public function dashboard(int $session_id): array
    {
        $collected = $this->collectedKobo($session_id);
        $outstanding = $this->outstandingKobo($session_id);

        return [
            'collected' => Money::format($collected),
            'outstanding' => Money::format($outstanding),
            'rate' => $this->collectionRate($collected, $outstanding),
            'review_count' => app(PaymentService::class)->openReviewCount(),
            'recent' => $this->recentTransactions($session_id),
            'breakdown' => $this->breakdown($session_id),
        ];
    }

    /**
     * Registration for the active semester, and finance figures for fees.view.
     *
     * @return array{
     *     registration_status: string,
     *     show_finance: bool,
     *     outstanding: string,
     *     last_payment_status: string,
     *     transaction_id: int
     * }
     */
    public function studentSnapshot(User $actor, Student $student): array
    {
        $registration = CourseRegistration::query()
            ->where('student_id', $student->getKey())
            ->whereIn('semester_id', function ($query): void {
                $query->select('id')->from('semesters')->where('is_active', true);
            })
            ->orderByDesc('id')
            ->first();
        $show_finance = Gate::forUser($actor)->allows('fees.view');
        $outstanding = '';
        $last_status = '';
        $transaction_id = 0;

        if ($show_finance) {
            $session = AcademicSession::query()->where('is_current', true)->first();
            $invoice = $session instanceof AcademicSession
                ? Invoice::query()
                    ->where('student_id', $student->getKey())
                    ->where('session_id', $session->getKey())
                    ->where('status', '!=', InvoiceStatus::Cancelled->value)
                    ->first()
                : null;
            $outstanding = $invoice instanceof Invoice
                ? Money::format(InvoiceService::outstandingKobo($invoice))
                : 'No invoice';
            $payment = Payment::query()
                ->where('student_id', $student->getKey())
                ->orderByDesc('id')
                ->first();
            $last_status = $payment instanceof Payment ? $payment->status->value : 'No payment';
            $transaction_id = $payment instanceof Payment ? (int) $payment->getKey() : 0;
        }

        return [
            'registration_status' => $registration instanceof CourseRegistration
                ? $registration->status->value
                : 'No registration',
            'show_finance' => $show_finance,
            'outstanding' => $outstanding,
            'last_payment_status' => $last_status,
            'transaction_id' => $transaction_id,
        ];
    }

    /**
     * One decimal percent from integer basis points. No floats.
     */
    public function collectionRate(int $collected_kobo, int $outstanding_kobo): string
    {
        $base = $collected_kobo + $outstanding_kobo;

        if ($base <= 0) {
            return '0.0%';
        }

        $basis_points = intdiv($collected_kobo, $base) * 10000
            + intdiv(($collected_kobo % $base) * 10000, $base);
        $whole = intdiv($basis_points, 100);
        $tenth = intdiv($basis_points % 100, 10);

        return $whole.'.'.$tenth.'%';
    }

    /**
     * The message when an export is larger than the row limit.
     */
    public static function exportTooLarge(): string
    {
        return 'Narrow the filters. An export can include at most '.(int) config('payments.export_row_limit').' rows.';
    }

    /**
     * Write transaction rows in chunks. Text cells go through CsvSafe.
     *
     * @param  Builder<Payment>  $query
     * @param  resource  $handle
     */
    public function writeTransactions(Builder $query, $handle): int
    {
        fputcsv($handle, ['reference', 'student', 'matric_no', 'invoice', 'amount', 'status', 'date']);
        $written = 0;
        $last_id = 0;
        $limit = (int) config('payments.export_row_limit');

        while ($written < $limit) {
            $rows = (clone $query)
                ->reorder('payments.id')
                ->where('payments.id', '>', $last_id)
                ->limit(200)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $payment) {
                if ($written >= $limit) {
                    break;
                }

                $student = $payment->student;
                $name = $student instanceof Student
                    ? (string) $student->user->name
                    : '';
                fputcsv($handle, [
                    CsvSafe::cell($payment->reference),
                    CsvSafe::cell($name),
                    CsvSafe::cell((string) $student?->matric_no),
                    CsvSafe::cell((string) $payment->invoice?->number),
                    CsvSafe::cell(Money::format((int) $payment->amount_kobo)),
                    CsvSafe::cell($payment->status->value),
                    CsvSafe::cell($payment->created_at?->timezone('Africa/Lagos')->format('Y-m-d H:i') ?? ''),
                ]);
                $written++;
                $last_id = (int) $payment->getKey();
            }
        }

        return $written;
    }

    /**
     * Write one reconciliation section. Text cells go through CsvSafe.
     *
     * @param  list<array<string, string>>  $rows
     * @param  resource  $handle
     */
    public function writeSection(string $section, array $rows, $handle): int
    {
        fputcsv($handle, ['section', 'reference', 'detail']);
        $written = 0;

        foreach ($rows as $row) {
            fputcsv($handle, [
                CsvSafe::cell($section),
                CsvSafe::cell($row['reference'] ?? $row['row_id'] ?? ''),
                CsvSafe::cell($row['detail'] ?? $row['kind'] ?? ''),
            ]);
            $written++;
        }

        return $written;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Builder<Payment>  $query
     */
    private function applyTransactionFilters(Builder $query, array $filters): void
    {
        $status = PaymentStatus::tryFrom($this->text($filters, 'status'));

        if ($status instanceof PaymentStatus) {
            $query->where('payments.status', $status->value);
        }

        $provider = PaymentGateway::tryFrom($this->text($filters, 'provider'));

        if ($provider instanceof PaymentGateway) {
            $query->where('payments.gateway', $provider->value);
        }

        $from = $this->date($this->text($filters, 'from'));
        $to = $this->date($this->text($filters, 'to'));

        if ($from instanceof Carbon) {
            $query->where('payments.created_at', '>=', $from->startOfDay());
        }

        if ($to instanceof Carbon) {
            $query->where('payments.created_at', '<=', $to->endOfDay());
        }

        $programme_id = $this->integer($filters, 'programme_id');
        $level = $this->integer($filters, 'level');

        if ($programme_id > 0 || $level > 0) {
            $query->whereHas('student', function (Builder $students) use ($programme_id, $level): void {
                if ($programme_id > 0) {
                    $students->where('programme_id', $programme_id);
                }

                if ($level > 0) {
                    $students->where('level', $level);
                }
            });
        }

        $search = $this->text($filters, 'search');

        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('payments.reference', $search)
                    ->orWhereHas('invoice', fn (Builder $invoices): Builder => $invoices->where('number', $search))
                    ->orWhereHas('student', fn (Builder $students): Builder => $students->where('matric_no', $search));
            });
        }
    }

    /**
     * Latest event id for each payment.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function latestEvents()
    {
        return DB::table('payment_events as events')
            ->joinSub(
                DB::table('payment_events')
                    ->select('payment_id', DB::raw('max(`id`) as `id`'))
                    ->whereNotNull('payment_id')
                    ->groupBy('payment_id'),
                'newest',
                'newest.id',
                '=',
                'events.id',
            )
            ->select('events.payment_id', 'events.payload');
    }

    /**
     * @param  Builder<Payment>  $query
     * @return array{count: int, rows: list<array<string, string>>}
     */
    private function pagePayments(Builder $query, int $page): array
    {
        $count = (clone $query)->count();
        $rows = (clone $query)
            ->with(['student.user', 'invoice'])
            ->forPage(max(1, $page), self::PAGE_SIZE)
            ->get();

        return ['count' => $count, 'rows' => $this->paymentRows($rows)];
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return list<array<string, string>>
     */
    private function paymentRows(Collection $payments): array
    {
        $rows = [];

        foreach ($payments as $payment) {
            $student = $payment->student;
            $rows[] = [
                'id' => (string) $payment->getKey(),
                'reference' => (string) $payment->reference,
                'detail' => trim((string) $student?->user?->name).' '.$payment->status->value,
                'status' => $payment->status->value,
            ];
        }

        return $rows;
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function integrityUnion()
    {
        $paid = DB::table('invoices')
            ->leftJoinSub(
                DB::table('payments')->select('invoice_id', DB::raw('coalesce(sum(`amount_kobo`), 0) as `paid`'))
                    ->where('status', PaymentStatus::Successful->value)
                    ->groupBy('invoice_id'),
                'paid_sum',
                'paid_sum.invoice_id',
                '=',
                'invoices.id',
            )
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
            ->whereRaw('`invoices`.`paid_kobo` <> coalesce(`paid_sum`.`paid`, 0)')
            ->selectRaw("'paid_kobo' as `kind`, `invoices`.`id` as `row_id`");

        $adjustments = DB::table('invoices')
            ->leftJoinSub(
                DB::table('invoice_adjustments')->select('invoice_id', DB::raw('coalesce(sum(`amount_kobo`), 0) as `adjusted`'))
                    ->groupBy('invoice_id'),
                'adjustment_sum',
                'adjustment_sum.invoice_id',
                '=',
                'invoices.id',
            )
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
            ->whereRaw('`invoices`.`adjustments_kobo` <> coalesce(`adjustment_sum`.`adjusted`, 0)')
            ->selectRaw("'adjustments_kobo' as `kind`, `invoices`.`id` as `row_id`");

        $items = DB::table('invoices')
            ->leftJoinSub(
                DB::table('invoice_items')->select('invoice_id', DB::raw('coalesce(sum(`amount_kobo`), 0) as `items_total`'))
                    ->groupBy('invoice_id'),
                'item_sum',
                'item_sum.invoice_id',
                '=',
                'invoices.id',
            )
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
            ->whereRaw('`invoices`.`total_kobo` <> coalesce(`item_sum`.`items_total`, 0)')
            ->selectRaw("'total_kobo' as `kind`, `invoices`.`id` as `row_id`");

        $status = DB::table('invoices')
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->whereRaw(
                "`status` <> case when `paid_kobo` + `adjustments_kobo` = `total_kobo` then 'Paid' when `paid_kobo` > 0 then 'Part-paid' else 'Unpaid' end",
            )
            ->selectRaw("'status' as `kind`, `id` as `row_id`");

        $missing_receipt = DB::table('payments')
            ->leftJoin('receipts', 'receipts.payment_id', '=', 'payments.id')
            ->whereIn('payments.status', [PaymentStatus::Successful->value, PaymentStatus::Reversed->value])
            ->groupBy('payments.id')
            ->havingRaw('count(`receipts`.`id`) <> 1')
            ->selectRaw("'receipt' as `kind`, `payments`.`id` as `row_id`");

        $extra_receipt = DB::table('payments')
            ->join('receipts', 'receipts.payment_id', '=', 'payments.id')
            ->whereNotIn('payments.status', [PaymentStatus::Successful->value, PaymentStatus::Reversed->value])
            ->selectRaw("'receipt_extra' as `kind`, `payments`.`id` as `row_id`");

        $student = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->whereColumn('payments.student_id', '!=', 'invoices.student_id')
            ->selectRaw("'student' as `kind`, `payments`.`id` as `row_id`");

        $paid_at = DB::table('payments')
            ->where('status', PaymentStatus::Successful->value)
            ->whereNull('paid_at')
            ->selectRaw("'paid_at' as `kind`, `id` as `row_id`");

        return $paid
            ->unionAll($adjustments)
            ->unionAll($items)
            ->unionAll($status)
            ->unionAll($missing_receipt)
            ->unionAll($extra_receipt)
            ->unionAll($student)
            ->unionAll($paid_at);
    }

    private function collectedKobo(int $session_id): int
    {
        return (int) Payment::query()
            ->where('payments.status', PaymentStatus::Successful->value)
            ->whereIn('invoice_id', function ($query) use ($session_id): void {
                $query->select('id')->from('invoices')
                    ->where('session_id', $session_id)
                    ->where('status', '!=', InvoiceStatus::Cancelled->value);
            })
            ->sum('amount_kobo');
    }

    private function outstandingKobo(int $session_id): int
    {
        $row = DB::table('invoices')
            ->where('session_id', $session_id)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->selectRaw('coalesce(sum(`total_kobo` - `adjustments_kobo` - `paid_kobo`), 0) as `outstanding`')
            ->first();

        return (int) ($row->outstanding ?? 0);
    }

    /**
     * @return list<array<string, string>>
     */
    private function recentTransactions(int $session_id): array
    {
        $rows = Payment::query()
            ->with(['student.user'])
            ->whereIn('invoice_id', function ($query) use ($session_id): void {
                $query->select('id')->from('invoices')->where('session_id', $session_id);
            })
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return $this->paymentRows($rows);
    }

    /**
     * @return list<array<string, string>>
     */
    private function breakdown(int $session_id): array
    {
        $collected = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('students', 'students.id', '=', 'invoices.student_id')
            ->join('programmes', 'programmes.id', '=', 'students.programme_id')
            ->where('invoices.session_id', $session_id)
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
            ->where('payments.status', PaymentStatus::Successful->value)
            ->groupBy('programmes.id', 'programmes.code', 'students.level')
            ->selectRaw('`programmes`.`code` as `programme`, `students`.`level` as `level`, coalesce(sum(`payments`.`amount_kobo`), 0) as `collected`')
            ->get();
        $outstanding = DB::table('invoices')
            ->join('students', 'students.id', '=', 'invoices.student_id')
            ->join('programmes', 'programmes.id', '=', 'students.programme_id')
            ->where('invoices.session_id', $session_id)
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
            ->groupBy('programmes.id', 'programmes.code', 'students.level')
            ->selectRaw('`programmes`.`code` as `programme`, `students`.`level` as `level`, coalesce(sum(`invoices`.`total_kobo` - `invoices`.`adjustments_kobo` - `invoices`.`paid_kobo`), 0) as `outstanding`')
            ->get();
        $by_key = [];

        foreach ($outstanding as $row) {
            $key = $row->programme.'|'.$row->level;
            $by_key[$key] = [
                'programme' => CsvSafe::cell((string) $row->programme),
                'level' => (string) $row->level,
                'collected' => Money::format(0),
                'outstanding' => Money::format((int) $row->outstanding),
            ];
        }

        foreach ($collected as $row) {
            $key = $row->programme.'|'.$row->level;
            $existing = $by_key[$key] ?? [
                'programme' => CsvSafe::cell((string) $row->programme),
                'level' => (string) $row->level,
                'collected' => Money::format(0),
                'outstanding' => Money::format(0),
            ];
            $existing['collected'] = Money::format((int) $row->collected);
            $by_key[$key] = $existing;
        }

        return array_values($by_key);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function text(array $filters, string $key): string
    {
        $value = $filters[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function integer(array $filters, string $key): int
    {
        $value = $filters[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function date(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'Africa/Lagos');
        } catch (\Throwable) {
            return null;
        }
    }
}
