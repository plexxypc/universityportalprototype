<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\UserStatus;
use App\Exceptions\NotificationRejected;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * In-app notifications stored in the portal notifications table.
 *
 * This is not Laravel's database-notification channel. Later phases call
 * create(). This service does not send email and does not write audit rows.
 */
final class NotificationService
{
    /**
     * Same-site path. A second slash, a scheme, or a query is refused.
     */
    public const string LINK_PATTERN = '#\A/(?:[A-Za-z0-9][A-Za-z0-9/_-]*)?\z#';

    private const int TITLE_MAX = 120;

    private const int MESSAGE_MAX = 240;

    private const int PER_PAGE = 15;

    /**
     * Keys the data column may store.
     *
     * @var list<string>
     */
    private const array DATA_KEYS = ['title', 'message', 'link'];

    /**
     * Insert one notification for this account.
     *
     * An unknown type, an unexpected data key, or an unsafe link throws
     * and writes nothing. The exception message is a fixed code.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $recipient, string $type, array $data): Notification
    {
        $notification_type = NotificationType::tryFrom($type);

        if ($notification_type === null) {
            throw NotificationRejected::unknownType();
        }

        $payload = $this->payload($data);

        $notification = Notification::query()->create([
            'user_id' => $recipient->id,
            'type' => $notification_type,
            'data' => $payload,
            'read_at' => null,
        ]);

        $this->forgetCount($recipient);

        return $notification;
    }

    /**
     * One page of this account's notifications, newest first.
     *
     * @return Paginator<int, Notification>
     */
    public function listFor(User $user): Paginator
    {
        return Notification::query()
            ->visibleTo($user)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE);
    }

    /**
     * Unread rows for this account.
     *
     * The predicates are user_id and read_at so MySQL can use
     * notifications_user_id_read_at_index. The result is remembered on the
     * current request so a layout does not count once per bell.
     */
    public function unreadCount(User $user): int
    {
        $key = $this->countKey($user);

        if (request()->attributes->has($key)) {
            return (int) request()->attributes->get($key);
        }

        $count = $this->unreadQuery($user)->count();
        request()->attributes->set($key, $count);

        return $count;
    }

    /**
     * The row when it belongs to this account, otherwise null.
     */
    public function findOwned(User $user, int $notification_id): ?Notification
    {
        return Notification::query()
            ->visibleTo($user)
            ->whereKey($notification_id)
            ->first();
    }

    /**
     * Mark one of this account's notifications read.
     *
     * Another account's row is left unchanged. A row that is already read
     * is left unchanged.
     */
    public function markRead(User $actor, Notification $notification): bool
    {
        if (! $this->canChange($actor) || (int) $notification->user_id !== (int) $actor->id) {
            return false;
        }

        $updated = $this->unreadQuery($actor)
            ->whereKey($notification->getKey())
            ->update(['read_at' => now()]);

        $this->forgetCount($actor);

        return $updated > 0;
    }

    /**
     * Mark every unread notification for this account, and no other account.
     */
    public function markAllRead(User $actor): int
    {
        if (! $this->canChange($actor)) {
            return 0;
        }

        $updated = $this->unreadQuery($actor)->update(['read_at' => now()]);
        $this->forgetCount($actor);

        return $updated;
    }

    /**
     * A same-site relative path, or null when the value cannot be used as a link.
     */
    public static function relativePath(mixed $link): ?string
    {
        if (! is_string($link) || preg_match(self::LINK_PATTERN, $link) !== 1) {
            return null;
        }

        return $link;
    }

    /**
     * Badge text. Counts above 99 stay at 99+.
     */
    public static function badge(int $count): string
    {
        if ($count > 99) {
            return '99+';
        }

        return (string) max(0, $count);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string, link?: string}
     */
    private function payload(array $data): array
    {
        $unknown = array_diff(array_keys($data), self::DATA_KEYS);

        if ($unknown !== [] || ! array_key_exists('title', $data) || ! array_key_exists('message', $data)) {
            throw NotificationRejected::invalidData();
        }

        $title = $this->plainText($data['title'], self::TITLE_MAX);
        $message = $this->plainText($data['message'], self::MESSAGE_MAX);

        if ($title === null || $message === null) {
            throw NotificationRejected::invalidData();
        }

        $payload = [
            'title' => $title,
            'message' => $message,
        ];

        if (! array_key_exists('link', $data) || $data['link'] === null) {
            return $payload;
        }

        $link = self::relativePath($data['link']);

        if ($link === null) {
            throw NotificationRejected::invalidLink();
        }

        $payload['link'] = $link;

        return $payload;
    }

    /**
     * A short single-line string, or null when the value is not usable.
     */
    private function plainText(mixed $value, int $max): ?string
    {
        if (! is_string($value) || preg_match('/[\r\n]/', $value) === 1) {
            return null;
        }

        $text = trim($value);

        if ($text === '' || mb_strlen($text) > $max) {
            return null;
        }

        return $text;
    }

    /**
     * Unread rows for one account. Both columns are the composite index.
     *
     * @return Builder<Notification>
     */
    private function unreadQuery(User $user): Builder
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at');
    }

    /**
     * An active account may mark its own rows.
     */
    private function canChange(User $actor): bool
    {
        return $actor->status === UserStatus::Active;
    }

    /**
     * Request-cache key for this account's unread count.
     */
    private function countKey(User $user): string
    {
        return 'notification_unread_count.'.$user->id;
    }

    /**
     * Drop the cached unread count after a write.
     */
    private function forgetCount(User $user): void
    {
        request()->attributes->remove($this->countKey($user));
    }
}
