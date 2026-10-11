<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Policies\NotificationPolicy;
use App\Services\NotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The signed-in student's notification list.
 *
 * Mark-read looks the row up through the owner's visible rows, so another
 * account's id and a missing id are the same 404. The redirect is always
 * the notifications page.
 */
class NotificationController extends Controller
{
    /**
     * Show this account's notifications.
     */
    public function index(Request $request, NotificationService $notifications): View
    {
        $user = $this->account($request);

        abort_unless(app(NotificationPolicy::class)->viewAny($user), 403);

        try {
            $page = $notifications->listFor($user);
            $failed = false;
        } catch (Throwable $exception) {
            report($exception);
            $page = null;
            $failed = true;
        }

        return view('student.notifications', [
            'notifications' => $page,
            'failed' => $failed,
            'unread_count' => $notifications->unreadCount($user),
        ]);
    }

    /**
     * Mark one of this account's notifications read.
     */
    public function markRead(
        Request $request,
        string $notification,
        NotificationService $notifications,
    ): RedirectResponse {
        $user = $this->account($request);
        $row = $notifications->findOwned($user, (int) $notification);

        if ($row === null || ! app(NotificationPolicy::class)->markRead($user, $row)) {
            abort(404);
        }

        $notifications->markRead($user, $row);

        return redirect()->route('student.notifications');
    }

    /**
     * Mark all of this account's unread notifications read.
     */
    public function markAll(Request $request, NotificationService $notifications): RedirectResponse
    {
        $user = $this->account($request);

        abort_unless(app(NotificationPolicy::class)->markAllRead($user), 403);

        $notifications->markAllRead($user);

        return redirect()->route('student.notifications');
    }

    /**
     * The signed-in account. The middleware has already required one.
     */
    private function account(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
