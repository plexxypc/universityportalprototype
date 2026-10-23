<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\ResultService;
use Illuminate\Http\Request;
use Tests\Support\ResultFixture;

/**
 * A request with a fixed audit address.
 */
function result_draft_request(): Request
{
    return Request::create('/staff/results', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.40',
    ]);
}

/**
 * @param  array<int, string|null>  $scores
 * @return array{student_id: int, scores: array<int, string|null>, version: ?string}
 */
function result_draft_row(int $student_id, array $scores, ?string $version): array
{
    return [
        'student_id' => $student_id,
        'scores' => $scores,
        'version' => $version,
    ];
}

/**
 * Save a passing draft for the fixture's only student.
 *
 * @return list<array{result_id: int, student_id: int, updated_at: string}>
 */
function result_workflow_draft(ResultFixture $fixture, ?User $actor = null): array
{
    return app(ResultService::class)->saveDraft(
        $actor ?? $fixture->lecturer,
        (int) $fixture->course->id,
        (int) $fixture->semester->id,
        [result_draft_row((int) $fixture->student->id, $fixture->passingScores(), null)],
        result_draft_request(),
    );
}
