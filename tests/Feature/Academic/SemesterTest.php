<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Staff\Pages\SemestersPage;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Semester;
use App\Services\AcademicService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('switches the active semester inside one transaction', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $session = $service->createSession($registrar, '2026/2027', true, session_request());
    $first = $service->createSemester($registrar, (int) $session->getKey(), 'First', true, null, null, session_request());
    $second = $service->createSemester($registrar, (int) $session->getKey(), 'Second', false, null, null, session_request());

    $service->updateSemester($registrar, (int) $second->getKey(), 'Second', true, null, null, session_request());

    expect(Semester::query()->where('is_active', true)->count())->toBe(1)
        ->and($second->refresh()->is_active)->toBeTrue()
        ->and($first->refresh()->is_active)->toBeFalse();
});

it('keeps an active semester whenever semesters exist', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $session = $service->createSession($registrar, '2026/2027', true, session_request());

    expect(fn () => $service->createSemester($registrar, (int) $session->getKey(), 'First', false, null, null, session_request()))
        ->toThrow(ValidationException::class, AcademicService::MESSAGE_SEMESTER_FIRST);

    $semester = $service->createSemester($registrar, (int) $session->getKey(), 'First', true, null, null, session_request());

    expect(fn () => $service->updateSemester($registrar, (int) $semester->getKey(), 'First', false, null, null, session_request()))
        ->toThrow(ValidationException::class, AcademicService::MESSAGE_SEMESTER_ACTIVE);

    expect($semester->refresh()->is_active)->toBeTrue();
});

it('activates a semester and its session in one transaction', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $current = $service->createSession($registrar, '2026/2027', true, session_request());
    $next = $service->createSession($registrar, '2027/2028', false, session_request());
    $old = $service->createSemester($registrar, (int) $current->getKey(), 'First', true, null, null, session_request());
    $coming = $service->createSemester($registrar, (int) $next->getKey(), 'First', false, null, null, session_request());

    $service->updateSemester($registrar, (int) $coming->getKey(), 'First', true, null, null, session_request());

    expect($coming->refresh()->is_active)->toBeTrue()
        ->and($old->refresh()->is_active)->toBeFalse()
        ->and($next->refresh()->is_current)->toBeTrue()
        ->and($current->refresh()->is_current)->toBeFalse()
        ->and(AcademicSession::query()->where('is_current', true)->count())->toBe(1)
        ->and(Semester::query()->where('is_active', true)->count())->toBe(1);
});

it('stores semester deadlines as Africa Lagos wall time', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $session = $service->createSession($registrar, '2026/2027', true, session_request());

    $semester = $service->createSemester(
        $registrar,
        (int) $session->getKey(),
        'First',
        true,
        '2026-10-12 14:30:00',
        '2026-10-20 09:00:00',
        session_request(),
    );

    expect($semester->refresh()->registration_deadline?->format('Y-m-d H:i:s'))->toBe('2026-10-12 14:30:00')
        ->and($semester->add_drop_deadline?->format('Y-m-d H:i:s'))->toBe('2026-10-20 09:00:00');

    expect(fn () => $service->updateSemester(
        $registrar,
        (int) $semester->getKey(),
        'First',
        true,
        '2026-10-20 09:00:00',
        '2026-10-12 14:30:00',
        session_request(),
    ))->toThrow(ValidationException::class, AcademicService::MESSAGE_ADD_DROP);
});

it('refuses a tampered semester id', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $session = $service->createSession($registrar, '2026/2027', true, session_request());
    $semester = $service->createSemester($registrar, (int) $session->getKey(), 'First', true, null, null, session_request());

    Livewire::actingAs($registrar)
        ->test(SemestersPage::class)
        ->call('deleteSemester', 999999);

    expect(Semester::query()->whereKey($semester->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', AcademicService::SEMESTER_DELETED)->count())->toBe(0);
});

it('refuses a faculty admin write on semesters', function () {
    $admin = session_user(Role::FacultyAdmin);
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $session = $service->createSession($registrar, '2026/2027', true, session_request());
    $semester = $service->createSemester($registrar, (int) $session->getKey(), 'First', true, null, null, session_request());

    $this->actingAs($admin)->get('/staff/semesters')->assertOk();

    try {
        Livewire::actingAs($admin)
            ->test(SemestersPage::class)
            ->call('deleteSemester', (int) $semester->getKey());
    } catch (AuthorizationException) {
        // The test client may also turn this into a forbidden response.
    }

    expect(Semester::query()->whereKey($semester->id)->exists())->toBeTrue();
});
