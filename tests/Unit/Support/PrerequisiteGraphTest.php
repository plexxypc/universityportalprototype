<?php

declare(strict_types=1);

use App\Support\PrerequisiteGraph;

/**
 * @param  array<int, list<int>>  $requires
 * @param  array<int, string>  $names
 */
function graph_message(int $course_id, array $prerequisite_ids, array $requires, array $names): ?string
{
    return (new PrerequisiteGraph)->rejection($course_id, $prerequisite_ids, $requires, $names);
}

it('rejects a course that requires itself', function () {
    $message = graph_message(1, [1], [], [1 => 'CSC101']);

    expect($message)->toBe('CSC101 cannot require itself.');
});

it('rejects a two-course prerequisite cycle', function () {
    $message = graph_message(1, [2], [2 => [1]], [1 => 'CSC101', 2 => 'CSC201']);

    expect($message)->toBe('CSC201 and CSC101 form a prerequisite cycle.');
});

it('rejects a three-course prerequisite cycle', function () {
    $message = graph_message(
        3,
        [1],
        [1 => [2], 2 => [3]],
        [1 => 'CSC101', 2 => 'CSC201', 3 => 'CSC301'],
    );

    expect($message)->toBe('CSC101, CSC201, and CSC301 form a prerequisite cycle.');
});

it('rejects a longer prerequisite cycle', function () {
    $message = graph_message(
        4,
        [1],
        [1 => [2], 2 => [3], 3 => [4]],
        [1 => 'CSC101', 2 => 'CSC201', 3 => 'CSC301', 4 => 'CSC401'],
    );

    expect($message)->toBe('CSC101, CSC201, CSC301, and CSC401 form a prerequisite cycle.');
});

it('allows a diamond of prerequisites', function () {
    $message = graph_message(
        1,
        [2, 3],
        [2 => [4], 3 => [4]],
        [1 => 'CSC101', 2 => 'CSC201', 3 => 'CSC301', 4 => 'MTH101'],
    );

    expect($message)->toBeNull();
});

it('allows an empty prerequisite graph', function () {
    expect(graph_message(1, [], [], []))->toBeNull();
});

it('stops when stored prerequisites already contain a cycle', function () {
    $message = graph_message(
        3,
        [1],
        [1 => [2], 2 => [1]],
        [1 => 'CSC101', 2 => 'CSC201', 3 => 'CSC301'],
    );

    expect($message)->toBeNull();
});
