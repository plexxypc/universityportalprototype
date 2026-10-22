<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ReceiptDocument;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receipt page and PDF. The service applies the same 404 to every refusal.
 */
class ReceiptController extends Controller
{
    public function __construct(private readonly ReceiptDocument $receipts) {}

    /**
     * The signed-in student's receipt page.
     */
    public function student(Request $request, int $receipt): Response
    {
        return $this->receipts->studentPage($this->actor($request), $receipt);
    }

    /**
     * The signed-in student's receipt PDF.
     */
    public function studentPdf(Request $request, int $receipt): Response
    {
        return $this->receipts->studentPdf($this->actor($request), $receipt);
    }

    /**
     * A staff receipt page for one receipt this account may view.
     */
    public function staff(Request $request, int $receipt): Response
    {
        return $this->receipts->staffPage($this->actor($request), $receipt);
    }

    /**
     * A staff receipt PDF for one receipt this account may view.
     */
    public function staffPdf(Request $request, int $receipt): Response
    {
        return $this->receipts->staffPdf($this->actor($request), $receipt);
    }

    /**
     * The signed-in account. A guest is a 404.
     */
    private function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 404);

        return $actor;
    }
}
