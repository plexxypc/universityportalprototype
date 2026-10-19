<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\CourseRegistration;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\RegistrationSlip;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'Africa/Lagos'));
    app(SettingsService::class)->forgetCache();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array{registrar: User, student: Student, registration: CourseRegistration}
 */
function registration_slip_approved(): array
{
    $shell = registration_decision_shell();
    $case = registration_decision_submitted($shell, Programme::factory()->create());
    $registrar = registration_decision_registrar();
    $registration = app(RegistrationService::class)->approve(
        $registrar,
        (int) $case['registration']->id,
        registration_decision_version($case['registration']),
        registration_decision_request(),
    );

    return [
        'registrar' => $registrar,
        'student' => $case['student'],
        'registration' => $registration,
    ];
}

function registration_slip_role(Role $role): User
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

it('downloads an approved slip and uses the same 404 otherwise', function () {
    $approved = registration_slip_approved();
    $student = $approved['student'];
    $user = $student->user;
    $registrar = $approved['registrar'];
    $registration = $approved['registration'];
    $shell = [
        'session' => $registration->semester->academicSession,
        'semester' => $registration->semester,
    ];
    $waiting = registration_decision_submitted($shell, Programme::factory()->create());
    $lecturer = registration_slip_role(Role::Lecturer);
    $bursar = registration_slip_role(Role::Bursar);

    expect(route('student.course-registration.slip', [], false))->toBe('/student/course-registration/slip');

    $student_slip = $this->actingAs($user)->get('/student/course-registration/slip');
    $staff_slip = $this->actingAs($registrar)->get('/staff/downloads/registrations/'.$registration->id);
    $missing = $this->actingAs($registrar)->get('/staff/downloads/registrations/999999');
    $not_approved = $this->actingAs($registrar)->get('/staff/downloads/registrations/'.$waiting['registration']->id);
    $lecturer_slip = $this->actingAs($lecturer)->get('/staff/downloads/registrations/'.$registration->id);
    $bursar_slip = $this->actingAs($bursar)->get('/staff/downloads/registrations/'.$registration->id);
    $student_waiting = $this->actingAs($waiting['user'])->get('/student/course-registration/slip');

    expect($student_slip->status())->toBe(200)
        ->and($staff_slip->status())->toBe(200)
        ->and(str_starts_with((string) $student_slip->getContent(), '%PDF'))->toBeTrue()
        ->and(strlen((string) $student_slip->getContent()))->toBeLessThan(80_000)
        ->and((string) $student_slip->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($student_slip->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $student_slip->headers->get('Cache-Control'))->toContain('no-store')
        ->and($missing->status())->toBe(404)
        ->and($not_approved->status())->toBe(404)
        ->and($lecturer_slip->status())->toBe(404)
        ->and($bursar_slip->status())->toBe(404)
        ->and($student_waiting->status())->toBe(404)
        ->and($lecturer_slip->getContent())->toBe($missing->getContent())
        ->and($bursar_slip->getContent())->toBe($missing->getContent())
        ->and($not_approved->getContent())->toBe($missing->getContent())
        ->and($student_waiting->getContent())->not->toContain($waiting['student']->matric_no);
});

it('keeps the slip small and blocks remote files', function () {
    $approved = registration_slip_approved();
    $before = memory_get_peak_usage(true);
    $bytes = app(RegistrationSlip::class)->bytes($approved['registration']);
    $used = memory_get_peak_usage(true) - $before;
    $html = view('pdf.registration-slip', [
        'institution_name' => 'University Portal',
        'student_name' => 'Chioma Adeyemi',
        'matric_no' => 'CSC/2026/0001',
        'programme_name' => 'Computer Science',
        'session_name' => '2026/2027',
        'semester_name' => 'First',
        'total_units' => 2,
        'courses' => [
            ['code' => 'CSC101', 'title' => 'Introduction', 'credit_units' => 2],
        ],
    ])->render();

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(strlen($bytes))->toBeLessThan(80_000)
        ->and($used)->toBeLessThan(32 * 1024 * 1024)
        ->and(str_contains($html, '<img'))->toBeFalse()
        ->and(str_contains($html, '<svg'))->toBeFalse()
        ->and(preg_match('/https?:/i', $html))->not->toBe(1);
});

it('limits slip downloads', function () {
    $approved = registration_slip_approved();
    $user = $approved['student']->user;
    $key = RegistrationSlip::rateKey($user);
    RateLimiter::clear($key);

    for ($attempt = 0; $attempt < RegistrationSlip::RATE_LIMIT; $attempt++) {
        RateLimiter::hit($key, 60);
    }

    $this->actingAs($user)->get('/student/course-registration/slip')->assertStatus(429);
});
