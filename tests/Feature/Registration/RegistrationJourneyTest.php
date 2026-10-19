<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\Role;
use App\Filament\Staff\Pages\RegistrationQueuePage;
use App\Livewire\Student\CourseRegistration as RegistrationPage;
use App\Models\CourseRegistration;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;
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

it('takes a student from course selection to an approved slip', function () {
    $world = registration_rules_world();
    registration_rules_limits(2, 24);
    $student = $world['student'];
    $user = $student->user;
    $officer = User::factory()->create();
    RoleAssignment::factory()->departmentOfficer()->create([
        'user_id' => $officer->id,
        'department_id' => $world['programme']->department_id,
    ]);

    $this->actingAs($user)->get('/student/course-registration')->assertOk();

    Livewire::actingAs($user)->test(RegistrationPage::class)
        ->set('elective_ids', [$world['elective']->id])
        ->call('submitRegistration')
        ->assertHasNoErrors();

    $registration = CourseRegistration::query()->first();

    expect($registration?->status)->toBe(RegistrationStatus::Submitted);

    Livewire::actingAs($officer)->test(RegistrationQueuePage::class)
        ->assertSee('CSC101')
        ->assertSee('CSC102')
        ->call('approveOne', (int) $registration?->id);

    expect($registration?->refresh()->status)->toBe(RegistrationStatus::Approved);

    $slip = $this->actingAs($user)->get('/student/course-registration/slip');

    expect($slip->status())->toBe(200)
        ->and(str_starts_with((string) $slip->getContent(), '%PDF'))->toBeTrue();

    $lecturer = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $lecturer->id,
        'role' => Role::Lecturer,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    $this->actingAs($lecturer)
        ->get('/staff/downloads/registrations/'.$registration?->id)
        ->assertNotFound();
});
