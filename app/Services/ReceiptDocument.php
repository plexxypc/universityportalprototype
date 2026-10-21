<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Money;
use Barryvdh\DomPDF\PDF;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receipt page and PDF for a Successful or Reversed payment.
 *
 * A missing receipt, a receipt outside this account, and a payment that
 * is not Successful or Reversed are the same 404. The PDF has no remote file.
 */
final class ReceiptDocument
{
    public const int RATE_LIMIT = 10;

    /**
     * Rate-limit key for one account.
     */
    public static function rateKey(User $user): string
    {
        return 'receipt-document:'.$user->getKey();
    }

    /**
     * HTML receipt for the signed-in student.
     */
    public function studentPage(User $actor, int $receipt_id): Response
    {
        $receipt = $this->open($actor, $receipt_id);

        return $this->page('student.receipt', $receipt, route('student.fees.receipt.pdf', ['receipt' => $receipt->getKey()]));
    }

    /**
     * PDF receipt for the signed-in student.
     */
    public function studentPdf(User $actor, int $receipt_id): Response
    {
        return $this->pdf($this->open($actor, $receipt_id));
    }

    /**
     * HTML receipt for a staff account that may view this receipt.
     */
    public function staffPage(User $actor, int $receipt_id): Response
    {
        $receipt = $this->open($actor, $receipt_id);

        return $this->page('staff.receipt', $receipt, route('staff.downloads.receipt.pdf', ['receipt' => $receipt->getKey()]));
    }

    /**
     * PDF receipt for a staff account that may view this receipt.
     */
    public function staffPdf(User $actor, int $receipt_id): Response
    {
        return $this->pdf($this->open($actor, $receipt_id));
    }

    /**
     * PDF bytes. Remote file access stays off.
     */
    public function bytes(Receipt $receipt): string
    {
        $html = view('pdf.receipt', $this->viewData($receipt))->render();

        if (str_contains($html, '<img') || str_contains($html, '<svg') || preg_match('/https?:/i', $html) === 1) {
            throw new \RuntimeException('receipt_markup');
        }

        /** @var PDF $pdf */
        $pdf = app('dompdf.wrapper');
        $pdf->setOption('isRemoteEnabled', false);
        $pdf->setOption('isFontSubsettingEnabled', true);

        if ($pdf->getOptions()->getIsRemoteEnabled() !== false) {
            throw new \RuntimeException('receipt_remote');
        }

        $pdf->loadHTML($html);

        return $pdf->output();
    }

    /**
     * The receipt this account may open, or a 404.
     */
    private function open(User $actor, int $receipt_id): Receipt
    {
        $this->limit($actor);

        $receipt = Receipt::query()->visibleTo($actor)->whereKey($receipt_id)->first();

        if (! $receipt instanceof Receipt || ! $actor->can('view', $receipt)) {
            abort(404);
        }

        $receipt->loadMissing('payment.student');
        $payment = $receipt->payment;

        if (! $payment instanceof Payment || ! $this->showable($payment)) {
            abort(404);
        }

        return $receipt;
    }

    /**
     * Successful and Reversed receipts are shown. Every other status is hidden.
     */
    private function showable(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Successful
            || $payment->status === PaymentStatus::Reversed;
    }

    /**
     * @return array{
     *     institution_name: string,
     *     number: string,
     *     status: string,
     *     amount: string,
     *     student_name: string,
     *     matric_no: string,
     *     pdf_url: string
     * }
     */
    private function viewData(Receipt $receipt, string $pdf_url = ''): array
    {
        $payment = $receipt->payment;
        $student = $payment instanceof Payment ? $payment->student : null;

        return [
            'institution_name' => (string) app(SettingsService::class)->effective()['name'],
            'number' => (string) $receipt->number,
            'status' => $payment instanceof Payment ? $payment->status->value : '',
            'amount' => $payment instanceof Payment ? Money::format((int) $payment->amount_kobo) : '',
            'student_name' => $student !== null ? trim($student->first_name.' '.$student->last_name) : '',
            'matric_no' => $student !== null ? (string) $student->matric_no : '',
            'pdf_url' => $pdf_url,
        ];
    }

    private function page(string $view, Receipt $receipt, string $pdf_url): Response
    {
        return response()
            ->view($view, $this->viewData($receipt, $pdf_url))
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Cache-Control', 'no-store');
    }

    private function pdf(Receipt $receipt): Response
    {
        return response($this->bytes($receipt), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="receipt.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function limit(User $user): void
    {
        $key = self::rateKey($user);

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            abort(429);
        }

        RateLimiter::hit($key, 60);
    }
}
