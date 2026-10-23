<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\EmailOutbox;
use App\Models\Notification;
use App\Models\Result;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\ResultService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\ResultFixture;

uses(RefreshDatabase::class);

/**
 * Publish the fixture's passing draft and return that row.
 */
function result_amend_published(ResultFixture $fixture): Result
{
    $service = app(ResultService::class);
    result_workflow_draft($fixture);
    $service->submit($fixture->lecturer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $service->approve($fixture->examOfficer, (int) $fixture->course->id, (int) $fixture->semester->id, result_draft_request());
    $service->publish($fixture->examOfficer, (int) $fixture->semester->id, [(int) $fixture->course->id], result_draft_request());
    $result = Result::query()->first();
    expect($result)->not->toBeNull();

    return $result;
}

it('records an amendment\'s old and new scores and reason', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    $result = result_amend_published($fixture);
    $published_at = $result->published_at?->format('Y-m-d H:i:s');
    $version = $result->updated_at?->format('Y-m-d H:i:s') ?? '';
    $service->amend($fixture->examOfficer, (int) $result->id, [
        (int) $fixture->ca->id => '20.00',
        (int) $fixture->exam->id => '40.00',
    ], 'Marking review', $version, result_draft_request());
    $result->refresh();
    $history = $service->history($fixture->examOfficer, (int) $result->id);
    $amended = collect($history)->firstWhere('action', ResultService::ACTION_AMENDED);
    $scoped = $service->history($fixture->departmentOfficer, (int) $result->id);
    $mail = EmailOutbox::query()->latest('id')->first();

    expect($result->status)->toBe(ResultStatus::Published)
        ->and($result->published_at?->format('Y-m-d H:i:s'))->toBe($published_at)
        ->and($result->grade)->toBe('B')
        ->and((string) $result->total)->toBe('60.00')
        ->and($amended['reason'] ?? null)->toBe('Marking review')
        ->and(collect($amended['before_scores'] ?? [])->pluck('score')->all())->toContain('50.00')
        ->and(collect($amended['after_scores'] ?? [])->pluck('score')->all())->toContain('40.00')
        ->and($scoped)->toBe($history)
        ->and(Notification::query()->where('type', NotificationType::ResultPublished)->count())->toBe(2)
        ->and((string) $mail?->body_text)->toContain('have been updated')
        ->and(json_encode($history))->not->toContain((string) $fixture->student->matric_no);
});

it('rejects a stale amendment token and writes no audit row', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    $result = result_amend_published($fixture);
    $audits = AuditLog::query()->count();

    expect(fn () => $service->amend(
        $fixture->examOfficer,
        (int) $result->id,
        $fixture->passingScores(),
        'Too late',
        '2000-01-01 00:00:00',
        result_draft_request(),
    ))->toThrow(ValidationException::class, ResultService::MESSAGE_VERSION);

    expect(AuditLog::query()->count())->toBe($audits)
        ->and((string) $result->refresh()->total)->toBe('70.00');
});

it('hides result history from the wrong role and the wrong department', function () {
    $fixture = ResultFixture::make();
    $service = app(ResultService::class);
    $result = result_amend_published($fixture);
    $other = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $other->id,
        'role' => Role::DepartmentOfficer,
        'department_id' => Department::factory()->create()->id,
    ]);

    expect(fn () => $service->history($fixture->lecturer, (int) $result->id))->toThrow(AuthorizationException::class);
    expect(fn () => $service->history($fixture->registrar, (int) $result->id))->toThrow(AuthorizationException::class);
    expect(fn () => $service->history($fixture->bursar, (int) $result->id))->toThrow(AuthorizationException::class);
    expect(fn () => $service->history($fixture->studentUser, (int) $result->id))->toThrow(AuthorizationException::class);
    expect(fn () => $service->history($other, (int) $result->id))->toThrow(NotFoundHttpException::class);
    expect(fn () => $service->history($fixture->examOfficer, 999999999))->toThrow(NotFoundHttpException::class);
});
