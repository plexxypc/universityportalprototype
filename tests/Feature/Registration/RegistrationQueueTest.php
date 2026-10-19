<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\StudentStatus;
use App\Filament\Staff\Pages\RegistrationQueuePage;
use App\Models\CourseRegistration;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'Africa/Lagos'));
    app(SettingsService::class)->forgetCache();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows the courses and total before approval', function () {
    $shell = registration_decision_shell();
    $programme = Programme::factory()->create();
    $case = registration_decision_submitted($shell, $programme);
    $item = $case['registration']->items()->with('course')->first();
    $code = $item?->course?->code;

    Livewire::actingAs(registration_decision_registrar())
        ->test(RegistrationQueuePage::class)
        ->assertSee($code)
        ->assertSee((string) $case['registration']->total_units);
});

it('approves with the token from the queue and rejects a stale row', function () {
    $shell = registration_decision_shell();
    $programme = Programme::factory()->create();
    $case = registration_decision_submitted($shell, $programme);
    $registration = $case['registration'];
    $registrar = registration_decision_registrar();

    Livewire::actingAs($registrar)->test(RegistrationQueuePage::class)
        ->call('approveOne', (int) $registration->id);

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Approved);
    Notification::assertNotified('Registration approved');

    $second = registration_decision_submitted($shell, Programme::factory()->create());
    $changed = $second['registration'];
    $page = Livewire::actingAs($registrar)->test(RegistrationQueuePage::class);
    $item = $changed->items()->first();
    $item?->forceFill(['credit_units' => 9])->save();
    $changed->forceFill(['total_units' => 9])->save();
    $page->call('approveOne', (int) $changed->id);

    expect($changed->refresh()->status)->toBe(RegistrationStatus::Submitted);
    Notification::assertNotified(RegistrationService::MESSAGE_RELOAD);
});

it('confirms a bulk approve and reports counts only', function () {
    $shell = registration_decision_shell();
    $programme = Programme::factory()->create();
    $case = registration_decision_submitted($shell, $programme);
    $registration = $case['registration'];
    $registrar = registration_decision_registrar();

    $page = Livewire::actingAs($registrar)->test(RegistrationQueuePage::class)
        ->mountTableBulkAction('approve', [$registration]);

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Submitted);

    $page->callMountedTableBulkAction();

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Approved);
    Notification::assertNotified('1 approved, 0 skipped');

    $other = registration_decision_submitted($shell, Programme::factory()->create());
    Livewire::actingAs($registrar)->test(RegistrationQueuePage::class)
        ->call('approveBulk', [(int) $other['registration']->id, 999999]);

    Notification::assertNotified('1 approved, 1 skipped');
    expect(json_encode(session()->all()))->not->toContain('999999');
});

it('stops a bulk approve above 100 and names nobody', function () {
    $shell = registration_decision_shell();
    $programme = Programme::factory()->create();
    $case = registration_decision_submitted($shell, $programme);
    $ids = array_fill(0, 101, (int) $case['registration']->id);

    Livewire::actingAs(registration_decision_registrar())->test(RegistrationQueuePage::class)
        ->call('approveBulk', $ids);

    expect($case['registration']->refresh()->status)->toBe(RegistrationStatus::Submitted);
    Notification::assertNotified(RegistrationService::MESSAGE_BULK_CAP);
});

it('lets a registrar reopen a student and refuses a faculty admin', function () {
    $shell = registration_decision_shell();
    $student = Student::factory()->create([
        'status' => StudentStatus::Active,
        'entry_session_id' => $shell['session']->id,
    ]);
    $faculty = User::factory()->create();
    RoleAssignment::factory()->facultyAdmin()->create([
        'user_id' => $faculty->id,
    ]);

    Livewire::actingAs($faculty)->test(RegistrationQueuePage::class)
        ->call('reopenStudent', $student->matric_no, 'Missed the window', '2026-10-20 10:00:00');

    Notification::assertNotified('You cannot reopen a registration.');
    expect(CourseRegistration::query()->where('student_id', $student->id)->exists())->toBeFalse();

    Livewire::actingAs(registration_decision_registrar())->test(RegistrationQueuePage::class)
        ->call('reopenStudent', $student->matric_no, 'Missed the window', '2026-10-20 10:00:00');

    Notification::assertNotified('Registration reopened');
    expect(CourseRegistration::query()->where('student_id', $student->id)->first()?->status)
        ->toBe(RegistrationStatus::Draft);
});
