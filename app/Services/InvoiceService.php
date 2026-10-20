<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\AcademicSession;
use App\Models\Counter;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Builds invoices from fee structures and keeps the stored totals in step.
 *
 * Amounts are copied onto invoice items. A later price change does not
 * rewrite an issued invoice. Lock order for this service is the invoice
 * row only. Payment settlement locks the payment row first.
 */
class InvoiceService
{
    public const int CHUNK_SIZE = 25;

    public const string ACTION_GENERATED = 'invoice.generated';

    public const string ACTION_CANCELLED = 'invoice.cancelled';

    public const string MESSAGE_MANAGE = 'You cannot change invoices.';

    public const string MESSAGE_VIEW = 'You cannot view invoices.';

    public const string MESSAGE_LEVEL = 'Choose a level.';

    public const string MESSAGE_PROGRAMME = 'Choose a programme.';

    public const string MESSAGE_SESSION = 'Choose a session.';

    public const string MESSAGE_MISSING = 'That invoice could not be found.';

    public const string MESSAGE_PAID = 'This invoice has a payment, so it cannot be cancelled.';

    public const string MESSAGE_PENDING = 'This invoice has a payment in progress, so it cannot be cancelled.';

    public const string MESSAGE_REASON = 'Enter a reason.';

    public const string MESSAGE_NO_FEE = 'This student has no fee structure for this session.';

    public const string MESSAGE_REASON_LENGTH = 'The reason must be 500 characters or fewer.';

    /**
     * Levels stored on fee structures.
     *
     * @var list<int>
     */
    private const array LEVELS = [100, 200, 300, 400, 500, 600];

    public function __construct(private readonly Audit $audit) {}

    /**
     * Classify students for this programme, level, and session.
     *
     * This does not write. A student with no price is listed, not rejected.
     */
    public function preview(User $actor, int $programme_id, int $level, int $session_id): InvoicePreview
    {
        $this->authoriseView($actor);
        $this->assertFilters($programme_id, $level, $session_id);

        $will_create = [];
        $already_invoiced = [];
        $no_fee_structure = [];

        foreach ($this->cohort($actor, $programme_id, $level) as $student) {
            $bucket = $this->bucket($student, $session_id);

            if ($bucket === 'already_invoiced') {
                $already_invoiced[] = (int) $student->getKey();
            } elseif ($bucket === 'no_fee_structure') {
                $no_fee_structure[] = (int) $student->getKey();
            } else {
                $will_create[] = (int) $student->getKey();
            }
        }

        return new InvoicePreview($will_create, $already_invoiced, $no_fee_structure);
    }

    /**
     * Create invoices for the next 25 students after the cursor.
     *
     * Each call is its own transaction. Running it again does not create
     * a second open invoice for the same student and session.
     */
    public function confirmChunk(
        User $actor,
        int $programme_id,
        int $level,
        int $session_id,
        int $after_student_id,
        Request $request,
    ): InvoiceChunk {
        $this->authoriseManage($actor);
        $this->assertFilters($programme_id, $level, $session_id);

        return DB::transaction(function () use ($actor, $programme_id, $level, $session_id, $after_student_id, $request): InvoiceChunk {
            $this->authoriseManage($actor);
            $ids = Student::query()
                ->visibleTo($actor)
                ->where('programme_id', $programme_id)
                ->where('level', $level)
                ->where('id', '>', $after_student_id)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids === []) {
                return new InvoiceChunk(0, 0, 0, $after_student_id, true);
            }

            $locked = Student::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $created = 0;
            $already_invoiced = 0;
            $no_fee_structure = 0;
            $last_student_id = $after_student_id;

            $last_student_id = max($ids);

            foreach ($locked as $student) {
                if ((int) $student->programme_id !== $programme_id || (int) $student->level !== $level) {
                    continue;
                }

                $bucket = $this->bucket($student, $session_id);

                if ($bucket === 'already_invoiced') {
                    $already_invoiced++;

                    continue;
                }

                if ($bucket === 'no_fee_structure') {
                    $no_fee_structure++;

                    continue;
                }

                $invoice = $this->writeInvoice($actor, $student, $session_id, $request);

                if ($invoice instanceof Invoice) {
                    $created++;
                } else {
                    $no_fee_structure++;
                }
            }

            return new InvoiceChunk(
                $created,
                $already_invoiced,
                $no_fee_structure,
                $last_student_id,
                count($ids) < self::CHUNK_SIZE,
            );
        });
    }

    /**
     * Issue the current-session invoice while a student row is being created.
     *
     * The caller already holds the transaction and has taken the matric
     * counter. This method locks the student row, then the invoice counter.
     * No fee structure, or no current session, creates nothing.
     */
    public function issueDuringCreate(User $actor, Student $student, Request $request): ?Invoice
    {
        $session = AcademicSession::query()->where('is_current', true)->first();

        if (! $session instanceof AcademicSession) {
            return null;
        }

        $write = function () use ($actor, $student, $session, $request): ?Invoice {
            $locked = Student::query()->whereKey($student->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Student) {
                return null;
            }

            if ($this->bucket($locked, (int) $session->getKey()) !== 'will_create') {
                return null;
            }

            return $this->writeInvoice($actor, $locked, (int) $session->getKey(), $request);
        };

        if (DB::transactionLevel() > 0) {
            return $write();
        }

        return DB::transaction($write);
    }

    /**
     * Cancel an invoice that has no successful payment and no open reservation.
     *
     * A Pending payment whose expiry has passed does not block this.
     * The reason is stored on the row and in the audit entry.
     */
    public function cancel(User $actor, int $invoice_id, string $reason, Request $request): Invoice
    {
        $this->authoriseManage($actor);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $invoice_id, $reason, $request): Invoice {
            $this->authoriseManage($actor);

            return $this->cancelLocked($actor, $invoice_id, $reason, $request);
        });
    }

    /**
     * Cancel this invoice and issue a replacement in the same transaction.
     *
     * The old number stays on the cancelled row. The new invoice gets the
     * next number. No fee structure rolls the cancellation back.
     */
    public function regenerate(User $actor, int $invoice_id, string $reason, Request $request): Invoice
    {
        $this->authoriseManage($actor);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $invoice_id, $reason, $request): Invoice {
            $this->authoriseManage($actor);
            $cancelled = $this->cancelLocked($actor, $invoice_id, $reason, $request);
            $student = Student::query()->whereKey($cancelled->student_id)->lockForUpdate()->first();

            if (! $student instanceof Student) {
                throw ValidationException::withMessages(['invoice' => self::MESSAGE_MISSING]);
            }

            $invoice = $this->writeInvoice($actor, $student, (int) $cancelled->session_id, $request);

            if (! $invoice instanceof Invoice) {
                throw ValidationException::withMessages(['invoice' => self::MESSAGE_NO_FEE]);
            }

            return $invoice;
        });
    }

    /**
     * Status implied by the stored totals.
     *
     * Paid includes a scholarship that covers the whole invoice. Cancelled
     * is not derived here.
     */
    public static function statusFor(int $total_kobo, int $adjustments_kobo, int $paid_kobo): InvoiceStatus
    {
        if ($paid_kobo + $adjustments_kobo === $total_kobo) {
            return InvoiceStatus::Paid;
        }

        if ($paid_kobo > 0) {
            return InvoiceStatus::PartPaid;
        }

        return InvoiceStatus::Unpaid;
    }

    /**
     * Amount still owed, after adjustments and successful payments.
     */
    public static function outstandingKobo(Invoice $invoice): int
    {
        return max(0, (int) $invoice->total_kobo - (int) $invoice->adjustments_kobo - (int) $invoice->paid_kobo);
    }

    /**
     * Kobo held by other Pending payments that have not expired.
     *
     * The caller locks the invoice. Payment rows are not locked.
     */
    public static function reservedKobo(Invoice $invoice): int
    {
        return (int) Payment::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('status', PaymentStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->sum('amount_kobo');
    }

    /**
     * Cancel the locked invoice. The caller holds the transaction.
     */
    private function cancelLocked(User $actor, int $invoice_id, string $reason, Request $request): Invoice
    {
        $invoice = Invoice::query()->visibleTo($actor)->whereKey($invoice_id)->lockForUpdate()->first();

        if (! $invoice instanceof Invoice) {
            throw ValidationException::withMessages(['invoice' => self::MESSAGE_MISSING]);
        }

        if ($invoice->status === InvoiceStatus::Cancelled) {
            throw ValidationException::withMessages(['invoice' => self::MESSAGE_MISSING]);
        }

        if ((int) $invoice->paid_kobo > 0) {
            throw ValidationException::withMessages(['invoice' => self::MESSAGE_PAID]);
        }

        if (self::reservedKobo($invoice) > 0) {
            throw ValidationException::withMessages(['invoice' => self::MESSAGE_PENDING]);
        }

        $before = [
            'invoice_id' => (int) $invoice->getKey(),
            'status' => $invoice->status->value,
            'paid_kobo' => (int) $invoice->paid_kobo,
        ];

        $invoice->status = InvoiceStatus::Cancelled;
        $invoice->cancelled_reason = $reason;
        $invoice->cancelled_by = (int) $actor->getKey();
        $invoice->cancelled_at = now();
        $invoice->save();

        $this->audit->record(
            (int) $actor->getKey(),
            self::ACTION_CANCELLED,
            'invoices',
            (int) $invoice->getKey(),
            $before,
            [
                'invoice_id' => (int) $invoice->getKey(),
                'status' => InvoiceStatus::Cancelled->value,
                'paid_kobo' => (int) $invoice->paid_kobo,
                'reason' => $reason,
            ],
            $this->audit->ipFromRequest($request),
        );

        return $invoice;
    }

    /**
     * Copy the current prices onto a new invoice.
     *
     * The student row is already locked by the caller. The invoice counter
     * is taken only when there is at least one line.
     */
    private function writeInvoice(User $actor, Student $student, int $session_id, Request $request): ?Invoice
    {
        $existing = Invoice::query()
            ->where('student_id', $student->getKey())
            ->where('session_id', $session_id)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->first();

        if ($existing instanceof Invoice) {
            return null;
        }

        $lines = $this->lines($student, $session_id);

        if ($lines === []) {
            return null;
        }

        $total_kobo = 0;

        foreach ($lines as $line) {
            $total_kobo += $line['amount_kobo'];
        }

        $invoice = new Invoice;
        $invoice->number = $this->nextNumber();
        $invoice->student_id = (int) $student->getKey();
        $invoice->session_id = $session_id;
        $invoice->total_kobo = $total_kobo;
        $invoice->adjustments_kobo = 0;
        $invoice->paid_kobo = 0;
        $invoice->status = self::statusFor($total_kobo, 0, 0);
        $invoice->save();

        $sort = 0;

        foreach ($lines as $line) {
            $item = new InvoiceItem;
            $item->invoice_id = (int) $invoice->getKey();
            $item->fee_category_id = $line['fee_category_id'];
            $item->description = $line['description'];
            $item->amount_kobo = $line['amount_kobo'];
            $item->sort = $sort;
            $item->save();
            $sort++;
        }

        $this->audit->record(
            (int) $actor->getKey(),
            self::ACTION_GENERATED,
            'invoices',
            (int) $invoice->getKey(),
            null,
            [
                'invoice_id' => (int) $invoice->getKey(),
                'student_id' => (int) $student->getKey(),
                'session_id' => $session_id,
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'total_kobo' => $total_kobo,
                'adjustments_kobo' => 0,
                'paid_kobo' => 0,
            ],
            $this->audit->ipFromRequest($request),
        );

        return $invoice;
    }

    /**
     * Active prices for this student and session, in category order.
     *
     * @return list<array{fee_category_id: int, description: string, amount_kobo: int}>
     */
    private function lines(Student $student, int $session_id): array
    {
        $structures = FeeStructure::query()
            ->with('feeCategory')
            ->where('programme_id', $student->programme_id)
            ->where('level', $student->level)
            ->where('session_id', $session_id)
            ->whereHas('feeCategory', function ($query): void {
                $query->where('is_active', true);
            })
            ->get()
            ->sortBy(function (FeeStructure $structure): string {
                $sort = (int) ($structure->feeCategory->sort ?? 0);

                return sprintf('%05d-%010d', $sort, (int) $structure->getKey());
            })
            ->values();

        $lines = [];

        foreach ($structures as $structure) {
            $name = trim((string) $structure->feeCategory?->name);

            if ($name === '') {
                continue;
            }

            $lines[] = [
                'fee_category_id' => (int) $structure->fee_category_id,
                'description' => $name,
                'amount_kobo' => (int) $structure->amount_kobo,
            ];
        }

        return $lines;
    }

    /**
     * will_create, already_invoiced, or no_fee_structure.
     */
    private function bucket(Student $student, int $session_id): string
    {
        $open = Invoice::query()
            ->where('student_id', $student->getKey())
            ->where('session_id', $session_id)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->exists();

        if ($open) {
            return 'already_invoiced';
        }

        if ($this->lines($student, $session_id) === []) {
            return 'no_fee_structure';
        }

        return 'will_create';
    }

    /**
     * Students in this programme and level that the actor may see, in id order.
     *
     * @return Collection<int, Student>
     */
    private function cohort(User $actor, int $programme_id, int $level): Collection
    {
        return Student::query()
            ->visibleTo($actor)
            ->where('programme_id', $programme_id)
            ->where('level', $level)
            ->orderBy('id')
            ->get();
    }

    /**
     * Next invoice number from the counter for this Lagos year.
     *
     * The caller holds the transaction. The format is INV-2026-000042.
     */
    private function nextNumber(): string
    {
        $year = now()->format('Y');
        $key = 'invoice:'.$year;
        $now = now();

        DB::table('counters')->insertOrIgnore([
            'key' => $key,
            'value' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $counter = Counter::query()->where('key', $key)->lockForUpdate()->first();

        if (! $counter instanceof Counter) {
            throw ValidationException::withMessages(['invoice' => self::MESSAGE_SESSION]);
        }

        $next = (int) $counter->value + 1;
        $counter->value = $next;
        $counter->save();

        return 'INV-'.$year.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Programme, level, and session must exist. A bad id uses one message.
     */
    private function assertFilters(int $programme_id, int $level, int $session_id): void
    {
        if (! in_array($level, self::LEVELS, true)) {
            throw ValidationException::withMessages(['level' => self::MESSAGE_LEVEL]);
        }

        if (! Programme::query()->whereKey($programme_id)->exists()) {
            throw ValidationException::withMessages(['programme_id' => self::MESSAGE_PROGRAMME]);
        }

        if (! AcademicSession::query()->whereKey($session_id)->exists()) {
            throw ValidationException::withMessages(['session_id' => self::MESSAGE_SESSION]);
        }
    }

    /**
     * A required reason, trimmed, at most 500 characters.
     */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => self::MESSAGE_REASON]);
        }

        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => self::MESSAGE_REASON_LENGTH]);
        }

        return $reason;
    }

    private function authoriseView(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('fees.view')) {
            throw new AuthorizationException(self::MESSAGE_VIEW);
        }
    }

    private function authoriseManage(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('fees.manage')) {
            throw new AuthorizationException(self::MESSAGE_MANAGE);
        }
    }
}
