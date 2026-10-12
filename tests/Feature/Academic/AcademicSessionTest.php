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

it('accepts only a session name whose second year is the next year', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);

    $session = $service->createSession($registrar, '2026/2027', true, session_request());

    expect($session->name)->toBe('2026/2027')
        ->and($session->is_current)->toBeTrue();

    expect(fn () => $service->createSession($registrar, '2026/2028', false, session_request()))
        ->toThrow(ValidationException::class, AcademicService::MESSAGE_NAME);

    expect(fn () => $service->createSession($registrar, '2026-2027', false, session_request()))
        ->toThrow(ValidationException::class);
});

it('switches the current session inside one transaction', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(AcademicService::class);
    $service->createSession($registrar, '2026/2027', true, session_request());
    $next = $service->createSession($registrar, '2027/2028', false, session_request());

    $service->updateSession($registrar, (int) $next->getKey(), '2027/2028', true, session_request());

    expect(AcademicSession::query()->where('is_current', true)->count())->toBe(1)
        ->and((bool) AcademicSession::query()->where('name', '2027/2028')->value('is_current'))->toBeTrue()
        ->and((bool) AcademicSession::query()->where('name', '2026/2027')->value('is_current'))->toBeFalse()
        ->and(AuditLog::query()->where('action', AcademicService::ACTION_UPDATED)->count())->toBe(1);
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

    expect(AcademicSession::query()->whereKey($session->id)->exists())->toBeTrue();
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
        ->call('deleteSession', 999999);

    expect(AcademicSession::query()->whereKey($session->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', AcademicService::ACTION_DELETED)->count())->toBe(0);
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
