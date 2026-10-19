<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Staff\Pages\AcademicSessionsPage;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\RoleAssignment;
use App\Models\Semester;
use App\Models\User;
use App\Services\AcademicService;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function session_user(Role $role): User
{
    $user = User::factory()->create();

    if ($role === Role::FacultyAdmin) {
        RoleAssignment::factory()->facultyAdmin()->create([
            'user_id' => $user->id,
        ]);

        return $user;
    }

    if ($role === Role::DepartmentOfficer) {
        RoleAssignment::factory()->departmentOfficer()->create([
            'user_id' => $user->id,
        ]);

        return $user;
    }

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

function session_request(): Request
{
    return Request::create('/staff/academic-sessions', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.10',
    ]);
}

it('stores a session whose second year is the next year', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);

    $session = $service->createSession($registrar, '2026/2027', true, session_request());

    expect($session->name)->toBe('2026/2027')
        ->and($session->is_current)->toBeTrue();
});

it('switches the current session inside one transaction', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $service->createSession($registrar, '2026/2027', true, session_request());
    $next = $service->createSession($registrar, '2027/2028', false, session_request());

    $service->updateSession($registrar, (int) $next->getKey(), '2027/2028', true, session_request());

    $updated = AuditLog::query()->where('action', AcademicService::ACTION_UPDATED)->first();

    expect(AcademicSession::query()->where('is_current', true)->count())->toBe(1)
        ->and((bool) AcademicSession::query()->where('name', '2027/2028')->value('is_current'))->toBeTrue()
        ->and((bool) AcademicSession::query()->where('name', '2026/2027')->value('is_current'))->toBeFalse()
        ->and(AuditLog::query()->where('action', AcademicService::ACTION_UPDATED)->count())->toBe(1)
        ->and($updated?->before)->toMatchArray(['name' => '2027/2028', 'is_current' => false])
        ->and($updated?->after)->toMatchArray(['name' => '2027/2028', 'is_current' => true]);
});

it('rolls back both session flags when a switch fails', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $service->createSession($registrar, '2026/2027', true, session_request());
    $next = $service->createSession($registrar, '2027/2028', false, session_request());

    try {
        AcademicSession::saving(function (AcademicSession $session): void {
            if ($session->name === '2027/2028' && $session->is_current) {
                throw new RuntimeException('roll back');
            }
        });

        expect(fn () => $service->updateSession($registrar, (int) $next->getKey(), '2027/2028', true, session_request()))
            ->toThrow(RuntimeException::class);

        expect(AcademicSession::query()->where('name', '2026/2027')->value('is_current'))->toBeTruthy()
            ->and(AcademicSession::query()->where('name', '2027/2028')->value('is_current'))->toBeFalsy()
            ->and(AuditLog::query()->where('action', AcademicService::ACTION_UPDATED)->count())->toBe(0);
    } finally {
        AcademicSession::flushEventListeners();
        AcademicSession::clearBootedModels();
    }
});

it('keeps a current session whenever sessions exist', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);

    expect(fn () => $service->createSession($registrar, '2026/2027', false, session_request()))
        ->toThrow(ValidationException::class, AcademicService::MESSAGE_FIRST);

    $session = $service->createSession($registrar, '2026/2027', true, session_request());

    expect(fn () => $service->updateSession($registrar, (int) $session->getKey(), '2026/2027', false, session_request()))
        ->toThrow(ValidationException::class, AcademicService::MESSAGE_CURRENT);

    expect($session->refresh()->is_current)->toBeTrue();
});

it('refuses to delete a session that is still in use', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $session = $service->createSession($registrar, '2026/2027', true, session_request());

    Semester::factory()->create([
        'session_id' => $session->id,
        'name' => 'First',
    ]);

    expect(fn () => $service->deleteSession($registrar, (int) $session->getKey(), session_request()))
        ->toThrow(ValidationException::class, AcademicService::MESSAGE_IN_USE);

    Livewire::actingAs($registrar)
        ->test(AcademicSessionsPage::class)
        ->call('deleteSession', (int) $session->getKey());

    Notification::assertNotified(AcademicService::MESSAGE_IN_USE);

    expect(AcademicSession::query()->whereKey($session->id)->exists())->toBeTrue()
        ->and($session->refresh()->is_current)->toBeTrue();
});

it('refuses to delete the current session while other sessions exist', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $current = $service->createSession($registrar, '2026/2027', true, session_request());
    $service->createSession($registrar, '2025/2026', false, session_request());

    expect(fn () => $service->deleteSession($registrar, (int) $current->getKey(), session_request()))
        ->toThrow(ValidationException::class, AcademicService::MESSAGE_CURRENT);

    Livewire::actingAs($registrar)
        ->test(AcademicSessionsPage::class)
        ->call('deleteSession', (int) $current->getKey());

    Notification::assertNotified(AcademicService::MESSAGE_CURRENT);

    expect(AcademicSession::query()->count())->toBe(2)
        ->and($current->refresh()->is_current)->toBeTrue();
});

it('deletes a session that nothing references', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $service->createSession($registrar, '2026/2027', true, session_request());
    $older = $service->createSession($registrar, '2025/2026', false, session_request());

    $service->deleteSession($registrar, (int) $older->getKey(), session_request());

    expect(AcademicSession::query()->whereKey($older->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', AcademicService::ACTION_DELETED)->count())->toBe(1);
});

it('refuses a tampered session id', function () {
    $registrar = session_user(Role::Registrar);
    $session = app(AcademicService::class)->createSession($registrar, '2026/2027', true, session_request());

    Livewire::actingAs($registrar)
        ->test(AcademicSessionsPage::class)
        ->call('saveSession', 999999, '2028/2029', false);

    Notification::assertNotified(AcademicService::MESSAGE_MISSING);

    Livewire::actingAs($registrar)
        ->test(AcademicSessionsPage::class)
        ->call('saveSession', 999999, '2028/2029', true);

    Livewire::actingAs($registrar)
        ->test(AcademicSessionsPage::class)
        ->call('deleteSession', 999999);

    Notification::assertNotified(AcademicService::MESSAGE_MISSING);

    expect($session->refresh()->name)->toBe('2026/2027')
        ->and($session->is_current)->toBeTrue()
        ->and(AuditLog::query()->where('action', AcademicService::ACTION_UPDATED)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', AcademicService::ACTION_DELETED)->count())->toBe(0);
});

it('lets a registrar save a session and refuses a faculty admin or bursar', function () {
    $registrar = session_user(Role::Registrar);
    $admin = session_user(Role::FacultyAdmin);
    $bursar = session_user(Role::Bursar);
    $session = app(AcademicService::class)->createSession($registrar, '2026/2027', true, session_request());

    Livewire::actingAs($registrar)
        ->test(AcademicSessionsPage::class)
        ->call('saveSession', (int) $session->getKey(), '2026/2027', true);

    $updated = AuditLog::query()->where('action', AcademicService::ACTION_UPDATED)->first();

    expect($session->refresh()->is_current)->toBeTrue()
        ->and($updated?->before)->toMatchArray(['name' => '2026/2027', 'is_current' => true])
        ->and($updated?->after)->toMatchArray(['name' => '2026/2027', 'is_current' => true]);

    foreach ([$admin, $bursar] as $actor) {
        try {
            Livewire::actingAs($actor)
                ->test(AcademicSessionsPage::class)
                ->call('saveSession', (int) $session->getKey(), '2027/2028', true);
        } catch (AuthorizationException) {
            // A view-only account is refused before the row is written.
        }

        expect($session->refresh()->name)->toBe('2026/2027');
    }
});

it('writes a before and after audit row for each session save', function () {
    $registrar = session_user(Role::Registrar);
    $session = app(AcademicService::class)->createSession($registrar, '2026/2027', true, session_request());

    $created = AuditLog::query()
        ->where('action', AcademicService::ACTION_CREATED)
        ->where('entity_id', $session->id)
        ->first();

    expect($created?->before)->toBeNull()
        ->and($created?->after)->toMatchArray([
            'name' => '2026/2027',
            'is_current' => true,
        ]);
});

it('refuses a faculty admin write on academic sessions', function () {
    $admin = session_user(Role::FacultyAdmin);
    $registrar = session_user(Role::Registrar);
    $session = app(AcademicService::class)->createSession($registrar, '2026/2027', true, session_request());

    $this->actingAs($admin)->get('/staff/academic-sessions')->assertOk();

    try {
        Livewire::actingAs($admin)
            ->test(AcademicSessionsPage::class)
            ->call('deleteSession', (int) $session->getKey());
    } catch (AuthorizationException) {
        // The test client may also turn this into a forbidden response.
    }

    expect(AcademicSession::query()->whereKey($session->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', AcademicService::ACTION_DELETED)->count())->toBe(0);
});
