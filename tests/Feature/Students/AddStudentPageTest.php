<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Staff\Pages\AddStudentPage;
use App\Models\AcademicSession;
use App\Models\Department;
use App\Models\EmailOutbox;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config(['queue.default' => 'database', 'mail.default' => 'array']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function add_student_form(Programme $programme, array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'other_names' => 'Mary',
        'email' => 'ada.form@example.com',
        'phone' => '08012345678',
        'gender' => 'Female',
        'date_of_birth' => '2004-05-01',
        'state_of_origin' => 'Lagos',
        'address' => '12 Garden Close',
        'programme_id' => $programme->id,
        'level' => 100,
        'entry_session_id' => null,
    ], $overrides);
}

function add_student_registrar(): User
{
    $user = User::factory()->create();

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => Role::Registrar,
    ]);

    return $user;
}

function add_student_programme(): Programme
{
    $department = Department::factory()->create([
        'code' => 'CSC',
        'name' => 'Computer Science',
    ]);

    return Programme::factory()->create([
        'department_id' => $department->id,
        'code' => 'CSC',
        'name' => 'Computer Science',
        'duration_years' => 4,
    ]);
}

it('lets a registrar add a student and does not show the temporary password', function () {
    AcademicSession::factory()->current()->create(['name' => '2026/2027']);
    $programme = add_student_programme();
    $registrar = add_student_registrar();

    $page = Livewire::actingAs($registrar)
        ->test(AddStudentPage::class)
        ->set('data', add_student_form($programme))
        ->call('save')
        ->assertHasNoErrors();

    $student = Student::query()->first();
    $row = EmailOutbox::query()->where('user_id', $student?->user_id)->first();
    $secrets = $row?->secrets;
    $password = is_array($secrets) ? ($secrets['temporary_password'] ?? '') : '';
    $password = is_string($password) ? $password : '';
    $html = $page->html();
    $shown = $password !== '' && str_contains($html, $password);

    expect($student)->not->toBeNull()
        ->and($student?->matric_no)->toBe('CSC/2026/0001')
        ->and($student?->user->phone)->toBe('+2348012345678')
        ->and(strlen($password))->toBe(12)
        ->and($shown)->toBeFalse();

    Notification::assertNotified('Student CSC/2026/0001 was created. The welcome email is queued.');
});

it('refuses a faculty admin on the add student form', function () {
    $faculty_admin = User::factory()->create();
    RoleAssignment::factory()->facultyAdmin()->create([
        'user_id' => $faculty_admin->id,
    ]);

    Livewire::actingAs($faculty_admin)
        ->test(AddStudentPage::class)
        ->assertForbidden();

    $this->actingAs($faculty_admin)->get('/staff/students/create')->assertForbidden();

    expect(Student::query()->count())->toBe(0);
});

it('shows a validation error and creates nothing when the level is not allowed', function () {
    AcademicSession::factory()->current()->create(['name' => '2026/2027']);
    $programme = add_student_programme();

    Livewire::actingAs(add_student_registrar())
        ->test(AddStudentPage::class)
        ->set('data', add_student_form($programme, [
            'email' => 'level.wrong@example.com',
            'level' => 500,
        ]))
        ->call('save')
        ->assertHasErrors(['data.level']);

    expect(User::query()->where('email', 'level.wrong@example.com')->exists())->toBeFalse()
        ->and(Student::query()->count())->toBe(0);
});

it('tells the registrar when the welcome email was not sent', function () {
    $this->app['env'] = 'production';
    config(['mail.default' => 'log', 'queue.default' => 'sync']);
    AcademicSession::factory()->current()->create(['name' => '2026/2027']);
    $programme = add_student_programme();

    Livewire::actingAs(add_student_registrar())
        ->test(AddStudentPage::class)
        ->set('data', add_student_form($programme, ['email' => 'not.sent@example.com']))
        ->call('save')
        ->assertHasNoErrors();

    $student = Student::query()->first();

    expect($student)->not->toBeNull();

    Notification::assertNotified('Student '.$student?->matric_no.' was created. The welcome email was not sent.');
});
