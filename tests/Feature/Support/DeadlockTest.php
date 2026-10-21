<?php

declare(strict_types=1);

use App\Enums\EmailTemplate;
use App\Enums\NotificationType;
use App\Models\Counter;
use App\Models\EmailOutbox;
use App\Models\Notification;
use App\Models\User;
use App\Services\MailService;
use App\Services\NotificationService;
use App\Services\RegistrationService;
use App\Support\Deadlock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * A database exception with the driver code the retry looks for.
 */
function deadlock_query_exception(int $driver_code = 1213, string $state = '40001'): QueryException
{
    $previous = new PDOException('simulated database error');
    $previous->errorInfo = [$state, $driver_code, 'simulated'];

    return new QueryException('mysql', 'select 1', [], $previous);
}

/**
 * Run at the root transaction so a committed attempt can queue its mail job.
 *
 * @param  callable(User): void  $test
 */
function deadlock_at_root(callable $test): void
{
    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    while ($connection->transactionLevel() > 0) {
        $connection->commit();
    }

    config(['queue.default' => 'database']);
    $user = User::factory()->create();

    try {
        $test($user);
    } finally {
        DB::table('jobs')->delete();
        EmailOutbox::query()->where('user_id', $user->id)->delete();
        Notification::query()->where('user_id', $user->id)->delete();
        Counter::query()->where('key', 'deadlock-probe')->delete();
        $user->delete();

        if ($wrapped && $connection->transactionLevel() === 0) {
            $connection->beginTransaction();
        }
    }
}

/**
 * Write a counter, a notice, and an outbox row inside one transaction.
 *
 * A deadlock thrown here is raised before commit, so the mail job stays unsent.
 */
function deadlock_write(User $user, int $value, ?QueryException $exception = null): void
{
    DB::transaction(function () use ($user, $value, $exception): void {
        Counter::query()->create([
            'key' => 'deadlock-probe',
            'value' => $value,
        ]);
        app(NotificationService::class)->create($user, NotificationType::RegistrationConfirmed->value, [
            'title' => RegistrationService::NOTICE_CONFIRMED_TITLE,
            'message' => RegistrationService::NOTICE_CONFIRMED_MESSAGE,
            'link' => RegistrationService::NOTICE_LINK,
        ]);
        app(MailService::class)->send(
            EmailTemplate::Test->value,
            (string) $user->email,
            ['message' => 'Hello'],
            (int) $user->id,
        );

        if ($exception instanceof QueryException) {
            throw $exception;
        }
    });
}

it('retries a simulated deadlock and keeps only the committed attempt', function () {
    deadlock_at_root(function (User $user): void {
        $calls = 0;

        $saved = Deadlock::attempt(function () use (&$calls, $user): string {
            $calls++;

            if ($calls === 1) {
                deadlock_write($user, 1, deadlock_query_exception());
            }

            deadlock_write($user, 2);

            return 'saved';
        });

        expect($saved)->toBe('saved')
            ->and($calls)->toBe(2)
            ->and(Counter::query()->where('key', 'deadlock-probe')->count())->toBe(1)
            ->and((int) Counter::query()->where('key', 'deadlock-probe')->value('value'))->toBe(2)
            ->and(Notification::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and(EmailOutbox::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and(DB::table('jobs')->count())->toBe(1);
    });
});

it('does not retry a database error that is not a deadlock', function () {
    deadlock_at_root(function (User $user): void {
        $calls = 0;
        $run = function () use (&$calls, $user): void {
            Deadlock::attempt(function () use (&$calls, $user): void {
                $calls++;
                deadlock_write($user, 1, deadlock_query_exception(1062, '23000'));
            });
        };

        expect($run)->toThrow(QueryException::class);

        expect($calls)->toBe(1)
            ->and(Counter::query()->where('key', 'deadlock-probe')->count())->toBe(0)
            ->and(Notification::query()->where('user_id', $user->id)->count())->toBe(0)
            ->and(EmailOutbox::query()->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('jobs')->count())->toBe(0);
    });
});

it('gives up after 3 deadlocks with a clear message and no partial data', function () {
    deadlock_at_root(function (User $user): void {
        $calls = 0;
        $run = function () use (&$calls, $user): void {
            Deadlock::attempt(function () use (&$calls, $user): void {
                $calls++;
                deadlock_write($user, 1, deadlock_query_exception());
            });
        };

        expect($run)->toThrow(ValidationException::class, Deadlock::MESSAGE);

        expect($calls)->toBe(3)
            ->and(Counter::query()->where('key', 'deadlock-probe')->count())->toBe(0)
            ->and(Notification::query()->where('user_id', $user->id)->count())->toBe(0)
            ->and(EmailOutbox::query()->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('jobs')->count())->toBe(0);
    });
});
