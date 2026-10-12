<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Staff\Pages\SemestersPage;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Semester;
use App\Services\AcademicService;
use Filament\Notifications\Notification;
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

    $updated = AuditLog::query()
        ->where('action', AcademicService::SEMESTER_UPDATED)
        ->where('entity_id', $coming->id)
        ->first();

    expect($coming->refresh()->is_active)->toBeTrue()
        ->and($old->refresh()->is_active)->toBeFalse()
        ->and($next->refresh()->is_current)->toBeTrue()
        ->and($current->refresh()->is_current)->toBeFalse()
        ->and(AcademicSession::query()->where('is_current', true)->count())->toBe(1)
        ->and(Semester::query()->where('is_active', true)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AcademicService::SEMESTER_UPDATED)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AcademicService::ACTION_UPDATED)->count())->toBe(0)
        ->and($updated?->before)->toMatchArray(['is_active' => false, 'session_is_current' => false])
        ->and($updated?->after)->toMatchArray(['is_active' => true, 'session_is_current' => true]);
});

it('rolls back the semester and the session when activation fails', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $current = $service->createSession($registrar, '2026/2027', true, session_request());
    $next = $service->createSession($registrar, '2027/2028', false, session_request());
    $old = $service->createSemester($registrar, (int) $current->getKey(), 'First', true, null, null, session_request());
    $coming = $service->createSemester($registrar, (int) $next->getKey(), 'First', false, null, null, session_request());

    try {
        AcademicSession::saving(function (AcademicSession $session): void {
            if ($session->name === '2027/2028' && $session->is_current) {
                throw new RuntimeException('roll back');
            }
        });

        expect(fn () => $service->updateSemester($registrar, (int) $coming->getKey(), 'First', true, null, null, session_request()))
            ->toThrow(RuntimeException::class);

        expect($coming->refresh()->is_active)->toBeFalse()
            ->and($old->refresh()->is_active)->toBeTrue()
            ->and($next->refresh()->is_current)->toBeFalse()
            ->and($current->refresh()->is_current)->toBeTrue()
            ->and(AuditLog::query()->where('action', AcademicService::SEMESTER_UPDATED)->count())->toBe(0);
    } finally {
        AcademicSession::flushEventListeners();
        AcademicSession::clearBootedModels();
    }
});

it('asks before a semester in another session also switches the current session', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $current = $service->createSession($registrar, '2026/2027', true, session_request());
    $next = $service->createSession($registrar, '2027/2028', false, session_request());
    $service->createSemester($registrar, (int) $current->getKey(), 'First', true, null, null, session_request());
    $coming = $service->createSemester($registrar, (int) $next->getKey(), 'First', false, null, null, session_request());
    $notice = 'This will make First the active semester and make 2027/2028 the current session instead of 2026/2027.';

    $component = Livewire::actingAs($registrar)
        ->test(SemestersPage::class)
        ->mountTableAction('edit', $coming)
        ->setTableActionData([
            'session_id' => $next->id,
            'name' => 'First',
            'is_active' => true,
            'registration_deadline' => null,
            'add_drop_deadline' => null,
        ])
        ->callMountedTableAction();

    $dialog = $component->instance()->getMountedAction();

    expect($component->instance()->mountedActions[0]['name'] ?? null)->toBe('confirmSemesterSwitch')
        ->and($dialog?->getModalHeading())->toBe('Save both changes')
        ->and($dialog?->getModalDescription())->toBe($notice)
        ->and($coming->refresh()->is_active)->toBeFalse()
        ->and($next->refresh()->is_current)->toBeFalse()
        ->and($current->refresh()->is_current)->toBeTrue();

    $component->callMountedAction();

    $updated = AuditLog::query()
        ->where('action', AcademicService::SEMESTER_UPDATED)
        ->where('entity_id', $coming->id)
        ->first();

    expect($coming->refresh()->is_active)->toBeTrue()
        ->and($next->refresh()->is_current)->toBeTrue()
        ->and($current->refresh()->is_current)->toBeFalse()
        ->and(AuditLog::query()->where('action', AcademicService::SEMESTER_UPDATED)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', AcademicService::ACTION_UPDATED)->count())->toBe(0)
        ->and($updated?->before)->toMatchArray(['is_active' => false, 'session_is_current' => false])
        ->and($updated?->after)->toMatchArray(['is_active' => true, 'session_is_current' => true]);
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
        ->and($semester->add_drop_deadline?->format('Y-m-d H:i:s'))->toBe('2026-10-20 09:00:00')
        ->and($semester->registration_deadline?->timezoneName)->toBe('Africa/Lagos');

    $converted = $service->createSemester(
        $registrar,
        (int) $session->getKey(),
        'Second',
        false,
        '2026-10-12T13:30:00+00:00',
        '2026-10-20T08:00:00+00:00',
        session_request(),
    );

    expect($converted->refresh()->registration_deadline?->format('Y-m-d H:i:s'))->toBe('2026-10-12 14:30:00')
        ->and($converted->add_drop_deadline?->format('Y-m-d H:i:s'))->toBe('2026-10-20 09:00:00');
});

it('refuses an add drop deadline before the registration deadline', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $session = $service->createSession($registrar, '2026/2027', true, session_request());
    $semester = $service->createSemester($registrar, (int) $session->getKey(), 'First', true, null, null, session_request());

    expect(fn () => $service->updateSemester(
        $registrar,
        (int) $semester->getKey(),
        'First',
        true,
        '2026-10-20 09:00:00',
        '2026-10-12 14:30:00',
        session_request(),
    ))->toThrow(ValidationException::class, AcademicService::MESSAGE_ADD_DROP);

    expect($semester->refresh()->registration_deadline)->toBeNull()
        ->and($semester->add_drop_deadline)->toBeNull();
});

it('refuses a tampered semester id', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $session = $service->createSession($registrar, '2026/2027', true, session_request());
    $semester = $service->createSemester($registrar, (int) $session->getKey(), 'First', true, null, null, session_request());

    Livewire::actingAs($registrar)
        ->test(SemestersPage::class)
        ->call('saveSemester', 999999, (int) $session->getKey(), 'Renamed', false, null, null);

    Notification::assertNotified(AcademicService::MESSAGE_SEMESTER_MISSING);

    Livewire::actingAs($registrar)
        ->test(SemestersPage::class)
        ->call('saveSemester', 999999, (int) $session->getKey(), 'First', true, null, null);

    Livewire::actingAs($registrar)
        ->test(SemestersPage::class)
        ->call('deleteSemester', 999999);

    Notification::assertNotified(AcademicService::MESSAGE_SEMESTER_MISSING);

    expect($semester->refresh()->name)->toBe('First')
        ->and($semester->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', AcademicService::SEMESTER_UPDATED)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AcademicService::SEMESTER_DELETED)->count())->toBe(0);
});

it('lets a registrar save a semester and refuses a faculty admin or bursar', function () {
    $registrar = session_user(Role::Registrar);
    $admin = session_user(Role::FacultyAdmin);
    $bursar = session_user(Role::Bursar);
    $service = app(AcademicService::class);
    $session = $service->createSession($registrar, '2026/2027', true, session_request());
    $semester = $service->createSemester($registrar, (int) $session->getKey(), 'First', true, null, null, session_request());

    Livewire::actingAs($registrar)
        ->test(SemestersPage::class)
        ->call('saveSemester', (int) $semester->getKey(), (int) $session->getKey(), 'Harmattan', true, null, null);

    $updated = AuditLog::query()
        ->where('action', AcademicService::SEMESTER_UPDATED)
        ->where('entity_id', $semester->id)
        ->first();

    expect($semester->refresh()->name)->toBe('Harmattan')
        ->and($updated?->before)->toMatchArray(['name' => 'First', 'is_active' => true])
        ->and($updated?->after)->toMatchArray(['name' => 'Harmattan', 'is_active' => true]);

    foreach ([$admin, $bursar] as $actor) {
        try {
            Livewire::actingAs($actor)
                ->test(SemestersPage::class)
                ->call('saveSemester', (int) $semester->getKey(), (int) $session->getKey(), 'Forged', true, null, null);
        } catch (AuthorizationException) {
            // A view-only account is refused before the row is written.
        }

        expect($semester->refresh()->name)->toBe('Harmattan');
    }
});

it('writes a before and after audit row for each semester save', function () {
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

    $created = AuditLog::query()
        ->where('action', AcademicService::SEMESTER_CREATED)
        ->where('entity_id', $semester->id)
        ->first();

    expect($created?->before)->toBeNull()
        ->and($created?->after)->toMatchArray([
            'name' => 'First',
            'is_active' => true,
            'session_is_current' => true,
            'registration_deadline' => '2026-10-12 14:30:00',
            'add_drop_deadline' => '2026-10-20 09:00:00',
        ]);
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
