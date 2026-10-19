<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\RegistrationSlip;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download a registration slip.
 */
class RegistrationSlipController extends Controller
{
    public function __construct(private readonly RegistrationSlip $slips) {}

    /**
     * The signed-in student's approved slip. The route has no id.
     */
    public function student(Request $request): Response
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(404);
        }

        return $this->slips->student($actor);
    }

    /**
     * A staff slip for one registration this account may approve.
     */
    public function staff(Request $request, int $registration): Response
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(404);
        }

        return $this->slips->staff($actor, $registration);
    }
}
