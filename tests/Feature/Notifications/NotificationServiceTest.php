<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\Role;
use App\Exceptions\NotificationRejected;
use App\Filament\Staff\Pages\NotificationsPage;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\IsolationWorld;

uses(RefreshDatabase::class);

/**
 * A student account that can open /student.
 */
function notification_student(): User
{
    $student = Student::factory()->create();

    return User::query()->findOrFail($student->user_id);
}

/**
 * An active staff account with this role and no scope.
 */
function notification_staff(Role $role): User
{
    $user = User::factory()->create();

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    return $user;
}

/**
 * A payload the service accepts.
 *
 * @return array{title: string, message: string, link: string}
 */
function notification_payload(): array
{
    return [
        'title' => 'Result published',
        'message' => 'Your result is ready',
        'link' => '/student/notifications',
    ];
}

/**
 * Whether the fixed code was thrown and the secret was not copied into it.
 */
function notification_refused(callable $callback, string $code, string $secret): bool
{
    try {
        $callback();
    } catch (NotificationRejected $exception) {
        return $exception->getMessage() === $code
            && ! str_contains($exception->getMessage(), $secret);
    }

    return false;
}

it('creates, lists, counts unread, and marks one read', function () {
    $owner = notification_student();
    $other = notification_student();
    $service = app(NotificationService::class);
    $before = AuditLog::query()->count();

    $created = $service->create($owner, NotificationType::ResultPublished->value, notification_payload());
    $service->create($other, NotificationType::Announcement->value, [
        'title' => 'Other',
        'message' => 'Hidden',
    ]);

    $listed = array_map(
        fn (mixed $row): int => $row instanceof Notification ? (int) $row->getKey() : 0,
        $service->listFor($owner)->items(),
    );

    expect($created->type)->toBe(NotificationType::ResultPublished)
        ->and($service->unreadCount($owner))->toBe(1)
        ->and($listed)->toBe([$created->id])
        ->and($service->markRead($owner, $created))->toBeTrue()
        ->and($created->fresh()?->read_at)->not->toBeNull()
        ->and($service->unreadCount($owner))->toBe(0)
        ->and(AuditLog::query()->count())->toBe($before);
});

it('refuses an unknown notification type', function () {
    $owner = notification_student();
    $secret = 'temporary-password-value';
    $refused = notification_refused(
        fn () => app(NotificationService::class)->create($owner, 'credentials', [
            'title' => 'Hello',
            'message' => $secret,
        ]),
        NotificationRejected::UNKNOWN_TYPE,
        $secret,
    );

    expect($refused)->toBeTrue()
        ->and(Notification::query()->count())->toBe(0);
});

it('refuses a data key other than title, message, and link', function () {
    $owner = notification_student();
    $secret = 'reset-token-value';
    $refused = notification_refused(
        fn () => app(NotificationService::class)->create($owner, 'announcement', [
            'title' => 'Hello',
            'message' => 'There',
            'token' => $secret,
        ]),
        NotificationRejected::INVALID_DATA,
        $secret,
    );

    expect($refused)->toBeTrue()
        ->and(Notification::query()->count())->toBe(0);
});

it('rejects an unsafe notification link', function (string $link) {
    $owner = notification_student();
    $refused = notification_refused(
        fn () => app(NotificationService::class)->create($owner, 'announcement', [
            'title' => 'Hello',
            'message' => 'There',
            'link' => $link,
        ]),
        NotificationRejected::INVALID_LINK,
        $link,
    );

    expect($refused)->toBeTrue()
        ->and(Notification::query()->count())->toBe(0);
})->with([
    'https://example.com/student',
    'javascript:alert(1)',
    '//example.com/student',
]);

it('marks all only the owner rows', function () {
    $owner = notification_student();
    $other = notification_student();
    $service = app(NotificationService::class);
    $own_unread = $service->create($owner, 'admission', [
        'title' => 'Admitted',
        'message' => 'Welcome',
    ]);
    $other_unread = $service->create($other, 'admission', [
        'title' => 'Admitted',
        'message' => 'Welcome',
    ]);

    expect($service->markAllRead($owner))->toBe(1)
        ->and($own_unread->fresh()?->read_at)->not->toBeNull()
        ->and($other_unread->fresh()?->read_at)->toBeNull();
});

it('does not mark another user notification from the service', function () {
    $owner = notification_student();
    $other = notification_student();
    $service = app(NotificationService::class);
    $row = $service->create($other, 'announcement', [
        'title' => 'Hidden',
        'message' => 'Stay unread',
    ]);

    expect($service->markRead($owner, $row))->toBeFalse()
        ->and($row->fresh()?->read_at)->toBeNull();
});

it('lets a super admin see other models and only their own notifications', function () {
    $world = IsolationWorld::make();
    $own = Notification::factory()->create([
        'user_id' => $world->superAdmin->id,
    ]);
    $admin = $world->superAdmin;

    $notification_ids = Notification::query()->visibleTo($admin)->pluck('id')->all();
    $student_ids = Student::query()->visibleTo($admin)->pluck('id')->all();
    $invoice_ids = Invoice::query()->visibleTo($admin)->pluck('id')->all();
    $course_ids = Course::query()->visibleTo($admin)->pluck('id')->all();

    expect(Gate::forUser($admin)->allows('view', $own))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('markRead', $own))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('view', $world->notificationA))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('markRead', $world->notificationA))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewAny', Notification::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', Notification::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', $world->studentA))->toBeTrue()
        ->and($notification_ids)->toContain($own->id)
        ->and($notification_ids)->not->toContain($world->notificationA->id)
        ->and($student_ids)->toContain($world->studentA->id)
        ->and($student_ids)->toContain($world->studentB->id)
        ->and($invoice_ids)->toContain($world->invoiceA->id)
        ->and($invoice_ids)->toContain($world->invoiceB->id)
        ->and($course_ids)->toContain($world->courseAssigned->id);
});

it('returns the same 404 for a missing id and another user id', function () {
    $owner = notification_student();
    $other = notification_student();
    $foreign = app(NotificationService::class)->create($other, 'announcement', [
        'title' => 'Hidden',
        'message' => 'Stay unread',
    ]);

    $missing = $this->actingAs($owner)->post(route('student.notifications.read', [
        'notification' => 999999999,
    ]));
    $denied = $this->actingAs($owner)->post(route('student.notifications.read', [
        'notification' => $foreign->id,
    ]));

    $normalize = function (string $body): string {
        return (string) preg_replace('#/student/notifications/\d+/read#', '/student/notifications/{id}/read', $body);
    };

    expect($missing->status())->toBe(404)
        ->and($denied->status())->toBe(404)
        ->and($normalize((string) $missing->getContent()))->toBe($normalize((string) $denied->getContent()))
        ->and($foreign->fresh()?->read_at)->toBeNull();
});

it('redirects mark-read and mark-all to the notifications page', function () {
    $owner = notification_student();
    $service = app(NotificationService::class);
    $row = $service->create($owner, 'payment_confirmed', [
        'title' => 'Paid',
        'message' => 'Receipt ready',
        'link' => '/student/notifications',
    ]);
    $service->create($owner, 'timetable_published', [
        'title' => 'Timetable',
        'message' => 'Published',
    ]);

    $one = $this->actingAs($owner)
        ->from('https://evil.example/phish')
        ->post(route('student.notifications.read', ['notification' => $row->id]), [
            'redirect' => 'https://evil.example/phish',
        ]);
    $all = $this->actingAs($owner)
        ->from('/login')
        ->post(route('student.notifications.read-all'), [
            'next' => 'https://evil.example/next',
        ]);

    $one_location = (string) $one->headers->get('Location');
    $all_location = (string) $all->headers->get('Location');

    $one->assertRedirect(route('student.notifications'));
    $all->assertRedirect(route('student.notifications'));

    expect(str_contains($one_location, 'evil.example'))->toBeFalse()
        ->and(str_contains($all_location, 'evil.example'))->toBeFalse()
        ->and(str_contains($all_location, '/login'))->toBeFalse();
});

it('refuses a forged student post and a forged staff call for a super admin', function () {
    $admin = notification_staff(Role::SuperAdmin);
    Student::factory()->create(['user_id' => $admin->id]);
    $other = notification_student();
    $service = app(NotificationService::class);
    $foreign = $service->create($other, 'announcement', [
        'title' => 'Hidden',
        'message' => 'Stay unread',
    ]);
    $own = $service->create($admin, 'announcement', [
        'title' => 'Mine',
        'message' => 'Readable',
    ]);

    $this->actingAs($admin)
        ->from('https://evil.example/back')
        ->post(route('student.notifications.read', ['notification' => $foreign->id]), [
            'redirect' => 'https://evil.example/back',
        ])
        ->assertNotFound();

    expect($foreign->fresh()?->read_at)->toBeNull();

    Livewire::actingAs($admin)
        ->test(NotificationsPage::class)
        ->call('markRead', $foreign->id)
        ->assertNotFound();

    expect($foreign->fresh()?->read_at)->toBeNull();

    Livewire::actingAs($admin)
        ->test(NotificationsPage::class)
        ->call('markRead', $own->id)
        ->assertRedirect(NotificationsPage::getUrl());

    expect($own->fresh()?->read_at)->not->toBeNull();
});

it('shows the authenticated user unread count in both layouts', function () {
    $student = notification_student();
    $staff = notification_staff(Role::Lecturer);
    Notification::factory()->count(2)->create(['user_id' => $student->id]);
    Notification::factory()->count(4)->create(['user_id' => $staff->id]);

    $student_page = $this->actingAs($student)->get('/student');
    $staff_page = $this->actingAs($staff)->get('/staff');

    $student_page->assertOk();
    $staff_page->assertOk();

    expect(str_contains((string) $student_page->getContent(), 'Notifications, 2 unread'))->toBeTrue()
        ->and(str_contains((string) $student_page->getContent(), 'data-bell="live"'))->toBeTrue()
        ->and(str_contains((string) $student_page->getContent(), 'data-bell="preview"'))->toBeFalse()
        ->and(str_contains((string) $student_page->getContent(), 'Ada Okonkwo'))->toBeTrue()
        ->and(str_contains((string) $staff_page->getContent(), 'Notifications, 4 unread'))->toBeTrue()
        ->and(str_contains((string) $staff_page->getContent(), 'data-bell="preview"'))->toBeFalse();
});

it('shows 99+ when the unread count is above 99', function () {
    $student = notification_student();
    Notification::factory()->count(100)->create(['user_id' => $student->id]);

    $page = $this->actingAs($student)->get('/student');
    $html = (string) $page->getContent();

    expect(str_contains($html, 'Notifications, 99+ unread'))->toBeTrue()
        ->and(str_contains($html, '>99+<'))->toBeTrue()
        ->and(str_contains($html, 'Notifications, 100 unread'))->toBeFalse();
});

it('keeps the sample bell on the local design preview', function () {
    $preview = view('design-preview-student')->render();
    $home = view('student.home')->render();

    expect(str_contains($preview, 'data-bell="preview"'))->toBeTrue()
        ->and(str_contains($preview, 'Notifications, 2 unread'))->toBeTrue()
        ->and(str_contains($preview, 'Ada Okonkwo'))->toBeTrue()
        ->and(str_contains($home, 'data-bell="preview"'))->toBeFalse()
        ->and(str_contains($home, 'Notifications, 2 unread'))->toBeFalse();

    $this->get('/login')->assertDontSee('data-bell="preview"', false);
    $this->get('/student')->assertRedirect('/login');
    $this->get('/student/notifications')->assertRedirect('/login');
    $this->get('/staff')->assertRedirect('/login');
});

it('counts unread once for many rows', function () {
    $owner = notification_student();
    Notification::factory()->count(8)->create(['user_id' => $owner->id]);
    $counts = 0;

    DB::listen(function (object $query) use (&$counts): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'notifications') && str_contains($sql, 'count(')) {
            $counts++;
        }
    });

    $this->actingAs($owner)->get(route('student.notifications'))->assertOk();

    expect($counts)->toBe(1);
});

it('counts unread through the user and read_at index', function () {
    $owner = notification_student();
    Notification::factory()->create(['user_id' => $owner->id]);
    $sql = null;
    $bindings = [];

    DB::listen(function (object $query) use (&$sql, &$bindings): void {
        $lower = strtolower($query->sql);

        if (str_contains($lower, 'notifications') && str_contains($lower, 'count(')) {
            $sql = $query->sql;
            $bindings = $query->bindings;
        }
    });

    app(NotificationService::class)->unreadCount($owner);

    expect(is_string($sql))->toBeTrue();

    $plan = DB::select('explain '.$sql, $bindings);
    $key = $plan[0]->key ?? null;

    expect($key)->toBe('notifications_user_id_read_at_index');
});
