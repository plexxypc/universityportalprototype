<?php

declare(strict_types=1);

namespace App\Filament\Staff\Pages;

use App\Models\User;
use App\Policies\NotificationPolicy;
use App\Services\NotificationService;
use Filament\Pages\Page;
use Throwable;

/**
 * In-app notifications for the signed-in staff account.
 *
 * The page is not in the navigation. The top-bar bell links here.
 * Mark-read uses the owner's visible rows. The redirect is this page.
 */
class NotificationsPage extends Page
{
    protected static ?string $slug = 'notifications';

    protected static ?string $title = 'Notifications';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.staff.notifications';

    /**
     * Any active account may open its own list. The panel still requires a staff role.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(NotificationPolicy::class)->viewAny($user);
    }

    /**
     * Mark one owned notification read, then return to this page.
     */
    public function markRead(int $notification_id): void
    {
        $actor = $this->actor();
        $notifications = app(NotificationService::class);
        $row = $notifications->findOwned($actor, $notification_id);

        if ($row === null || ! app(NotificationPolicy::class)->markRead($actor, $row)) {
            abort(404);
        }

        $notifications->markRead($actor, $row);
        $this->redirect(static::getUrl());
    }

    /**
     * Mark this account's unread rows, then return to this page.
     */
    public function markAll(): void
    {
        $actor = $this->actor();

        abort_unless(app(NotificationPolicy::class)->markAllRead($actor), 403);

        app(NotificationService::class)->markAllRead($actor);
        $this->redirect(static::getUrl());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $actor = $this->actor();
        $notifications = app(NotificationService::class);
        $failed = false;
        $page = null;

        try {
            $page = $notifications->listFor($actor);
        } catch (Throwable $exception) {
            report($exception);
            $failed = true;
        }

        return [
            'notifications' => $page,
            'failed' => $failed,
            'unread_count' => $notifications->unreadCount($actor),
        ];
    }

    /**
     * The signed-in account, or a refusal.
     */
    private function actor(): User
    {
        $user = auth()->user();

        if (! $user instanceof User || ! app(NotificationPolicy::class)->viewAny($user)) {
            abort(403);
        }

        return $user;
    }
}
