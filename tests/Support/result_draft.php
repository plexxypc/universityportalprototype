<?php

declare(strict_types=1);

use Illuminate\Http\Request;

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
