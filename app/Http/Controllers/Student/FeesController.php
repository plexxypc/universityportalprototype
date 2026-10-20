<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;

/**
 * The signed-in student's invoices.
 *
 * The student is not part of the URL. A missing invoice and another
 * student's invoice are the same 404.
 */
class FeesController extends Controller
{
    /**
     * List this student's invoices and the outstanding balance.
     */
    public function index(): View
    {
        $user = $this->owner();
        $invoices = Invoice::query()
            ->visibleTo($user)
            ->with('academicSession')
            ->orderByDesc('id')
            ->get();
        $outstanding_kobo = 0;

        foreach ($invoices as $invoice) {
            if ($invoice->status !== InvoiceStatus::Cancelled) {
                $outstanding_kobo += InvoiceService::outstandingKobo($invoice);
            }
        }

        return view('student.fees', [
            'invoices' => $invoices,
            'outstanding_kobo' => $outstanding_kobo,
        ]);
    }

    /**
     * Show one invoice this student may open.
     */
    public function show(int $invoice): View
    {
        $user = $this->owner();
        $row = Invoice::query()
            ->visibleTo($user)
            ->with(['items', 'adjustments', 'payments', 'academicSession'])
            ->whereKey($invoice)
            ->first();

        abort_unless($row instanceof Invoice, 404);

        return view('student.invoice', [
            'invoice' => $row,
            'outstanding_kobo' => InvoiceService::outstandingKobo($row),
        ]);
    }

    /**
     * The signed-in account. The page does not accept a student id.
     */
    private function owner(): User
    {
        $student = CurrentStudent::require();
        $user = $student->user;

        abort_unless($user instanceof User, 404);

        return $user;
    }
}
