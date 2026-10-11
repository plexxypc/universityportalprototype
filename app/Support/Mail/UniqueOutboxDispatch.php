<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Jobs\SendOutboxEmail;
use Illuminate\Queue\Events\UniqueJobSkipped;
use Illuminate\Support\Facades\Event;

/**
 * Reports whether a send dispatch was queued or dropped.
 *
 * ShouldBeUnique acquires its lock in the dispatch destructor and fires
 * UniqueJobSkipped when that lock is already held. One listener per
 * application reads that event. Each attempt keeps a frame so the caller
 * can count a drop as skipped.
 */
final class UniqueOutboxDispatch
{
    private const string LISTENER_KEY = 'mail.unique_outbox_dispatch_listener';

    /**
     * @var list<array{id: int, skipped: bool}>
     */
    private static array $frames = [];

    /**
     * Listen once for this application instance.
     */
    public static function listen(): void
    {
        if (app()->bound(self::LISTENER_KEY)) {
            return;
        }

        app()->instance(self::LISTENER_KEY, true);

        Event::listen(function (UniqueJobSkipped $event): void {
            $job = $event->job;

            if (! $job instanceof SendOutboxEmail) {
                return;
            }

            foreach (self::$frames as $index => $frame) {
                if ($frame['id'] !== $job->email_outbox_id) {
                    continue;
                }

                self::$frames[$index]['skipped'] = true;
            }
        });
    }

    /**
     * Queue one send. False means the unique lock dropped it.
     */
    public static function dispatch(int $email_outbox_id, bool $after_commit): bool
    {
        self::listen();

        $index = count(self::$frames);
        self::$frames[] = [
            'id' => $email_outbox_id,
            'skipped' => false,
        ];

        try {
            if ($after_commit) {
                SendOutboxEmail::dispatch($email_outbox_id)->afterCommit();
            } else {
                SendOutboxEmail::dispatch($email_outbox_id);
            }

            return ! self::$frames[$index]['skipped'];
        } finally {
            array_pop(self::$frames);
        }
    }
}
