<?php

declare(strict_types=1);

use App\Enums\AnnouncementAudience;
use App\Enums\EmailStatus;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Counter;
use App\Models\EmailOutbox;
use App\Models\Faculty;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('rejects an announcement whose scope does not match its audience', function () {
    $faculty = Faculty::factory()->create();

    expect(fn () => Announcement::factory()->create([
        'audience' => AnnouncementAudience::Faculty,
    ]))->toThrow(QueryException::class);

    expect(fn () => Announcement::factory()->create([
        'audience' => AnnouncementAudience::All,
        'faculty_id' => $faculty->id,
    ]))->toThrow(QueryException::class);
});

it('stores a faculty announcement and a level announcement', function () {
    $faculty = Faculty::factory()->create();

    $faculty_announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::Faculty,
        'faculty_id' => $faculty->id,
    ]);
    $level_announcement = Announcement::factory()->create([
        'audience' => AnnouncementAudience::Level,
        'level' => 200,
    ]);

    expect($faculty_announcement->audience)->toBe(AnnouncementAudience::Faculty)
        ->and($level_announcement->level)->toBe(200);
});

it('rejects a blank announcement title', function () {
    expect(fn () => Announcement::factory()->create(['title' => '   ']))
        ->toThrow(QueryException::class);
});

it('returns portal notifications for the user', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'type' => 'announcement',
        'data' => ['announcement_id' => 1],
    ]);
    Notification::factory()->create();

    $relation = $user->notifications();
    $loaded = $relation->get();

    expect($relation)->toBeInstanceOf(HasMany::class)
        ->and($relation->getRelated())->toBeInstanceOf(Notification::class)
        ->and($relation->getRelated()->getTable())->toBe('notifications')
        ->and($relation->getForeignKeyName())->toBe('user_id')
        ->and($relation->toSql())->not->toContain('notifiable')
        ->and($loaded)->toHaveCount(1)
        ->and($loaded->first()?->is($notification))->toBeTrue();
});

it('filters unread portal notifications through the user relation', function () {
    $user = User::factory()->create();
    $unread = Notification::factory()->create([
        'user_id' => $user->id,
        'read_at' => null,
    ]);
    Notification::factory()->create([
        'user_id' => $user->id,
        'read_at' => now(),
    ]);

    expect($user->unreadNotifications()->get())->toHaveCount(1)
        ->and($user->unreadNotifications()->first()?->is($unread))->toBeTrue()
        ->and($user->readNotifications()->get())->toHaveCount(1);
});

it('rejects a sent email without sent_at', function () {
    expect(fn () => EmailOutbox::factory()->create([
        'status' => EmailStatus::Sent,
        'sent_at' => null,
    ]))->toThrow(QueryException::class);
});

it('rejects sent_at while the email is still queued', function () {
    expect(fn () => EmailOutbox::factory()->create([
        'status' => EmailStatus::Queued,
        'sent_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects redacted_at while the email is still queued', function () {
    expect(fn () => EmailOutbox::factory()->create([
        'status' => EmailStatus::Queued,
        'redacted_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate counter key', function () {
    Counter::factory()->create(['key' => 'matric:csc:2026']);

    expect(fn () => Counter::factory()->create(['key' => 'matric:csc:2026']))
        ->toThrow(QueryException::class);
});

it('keeps an audit log whose actor and entity are unknown', function () {
    $log = AuditLog::factory()->create([
        'actor_id' => null,
        'action' => 'login.failed',
        'entity' => 'users',
        'entity_id' => null,
        'before' => null,
        'after' => null,
        'ip' => '127.0.0.1',
    ]);

    $log->refresh();

    expect($log->actor_id)->toBeNull()
        ->and($log->entity_id)->toBeNull()
        ->and($log->entity)->toBe('users')
        ->and(DB::table('audit_logs')->where('id', $log->id)->exists())->toBeTrue();
});

it('blocks deleting a user who is an audit actor and keeps the log', function () {
    $user = User::factory()->create();
    $log = AuditLog::factory()->create([
        'actor_id' => $user->id,
        'entity' => 'users',
        'entity_id' => $user->id,
    ]);

    expect(fn () => DB::table('users')->where('id', $user->id)->delete())
        ->toThrow(QueryException::class);

    expect(DB::table('audit_logs')->where('id', $log->id)->exists())->toBeTrue()
        ->and((int) DB::table('audit_logs')->where('id', $log->id)->value('actor_id'))->toBe($user->id);
});

it('restricts every communications and audit foreign key', function () {
    $rows = DB::select(
        'select CONSTRAINT_NAME as constraint_name, DELETE_RULE as delete_rule
         from information_schema.referential_constraints
         where CONSTRAINT_SCHEMA = ? and TABLE_NAME in (?, ?, ?, ?)',
        [
            DB::getDatabaseName(),
            'announcements',
            'notifications',
            'email_outbox',
            'audit_logs',
        ],
    );

    $delete_rules = collect($rows)->mapWithKeys(
        fn (object $row): array => [$row->constraint_name => $row->delete_rule],
    );

    expect($delete_rules)->toHaveCount(7)
        ->and($delete_rules->every(fn (string $rule): bool => $rule === 'RESTRICT'))->toBeTrue();
});

it('has the communications and audit tables', function () {
    expect(Schema::hasTable('announcements'))->toBeTrue()
        ->and(Schema::hasTable('notifications'))->toBeTrue()
        ->and(Schema::hasTable('email_outbox'))->toBeTrue()
        ->and(Schema::hasTable('counters'))->toBeTrue()
        ->and(Schema::hasTable('audit_logs'))->toBeTrue()
        ->and(Schema::hasColumns('announcements', [
            'author_id',
            'title',
            'body',
            'audience',
            'faculty_id',
            'department_id',
            'programme_id',
            'level',
            'send_email',
            'published_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('email_outbox', [
            'recipient_email',
            'template',
            'subject',
            'body_html',
            'body_text',
            'secrets',
            'status',
            'attempts',
            'last_error',
            'sent_at',
            'redacted_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('audit_logs', [
            'actor_id',
            'action',
            'entity',
            'entity_id',
            'before',
            'after',
            'ip',
            'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('audit_logs', 'updated_at'))->toBeFalse();
});
