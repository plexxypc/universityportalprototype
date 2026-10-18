<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Notification;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * A student who can open the student area.
 */
function announcement_student(string $matric, string $first): Student
{
    $user = User::factory()->create([
        'status' => UserStatus::Active,
        'must_change_password' => false,
    ]);

    return Student::factory()->create([
        'user_id' => $user->id,
        'matric_no' => $matric,
        'first_name' => $first,
        'last_name' => 'Adeyemi',
    ]);
}

test('the announcements page is an empty state and does not read announcements', function () {
    $student = announcement_student('CSC/2026/0401', 'Chioma');
    Announcement::factory()->create([
        'title' => 'Secret audience notice',
        'body' => 'Only some students should see this later.',
    ]);
    $queried = false;

    DB::listen(function (object $query) use (&$queried): void {
        if (preg_match('/\bannouncements\b/i', $query->sql) === 1) {
            $queried = true;
        }
    });

    $this->actingAs($student->user)
        ->get(route('student.announcements'))
        ->assertOk()
        ->assertSee('There are no announcements yet.')
        ->assertDontSee('Secret audience notice');

    expect($queried)->toBeFalse();
});

test('student b does not see student a notification and cannot mark it read', function () {
    $first = announcement_student('CSC/2026/0402', 'Chioma');
    $second = announcement_student('CSC/2026/0403', 'Tunde');
    $notice = Notification::factory()->create([
        'user_id' => $first->user_id,
        'data' => [
            'title' => 'Only for Chioma',
            'message' => 'Private notice',
        ],
    ]);

    $this->actingAs($second->user)
        ->get(route('student.notifications'))
        ->assertOk()
        ->assertDontSee('Only for Chioma')
        ->assertDontSee('Private notice');

    $this->actingAs($second->user)
        ->post(route('student.notifications.read', $notice))
        ->assertNotFound();

    expect($notice->fresh()?->read_at)->toBeNull();

    $this->actingAs($first->user)
        ->post(route('student.notifications.read', $notice))
        ->assertRedirect(route('student.notifications'));

    expect($notice->fresh()?->read_at)->not->toBeNull();
});
