<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Detects a prerequisite cycle without reading the database.
 *
 * Edges point from a course to the courses it requires. A diamond is allowed.
 * A course that can reach itself is refused. A visited set stops an existing
 * cycle from being walked forever.
 */
final class PrerequisiteGraph
{
    /**
     * The sentence to show, or null when the new edges do not close a cycle.
     *
     * The stored edges may still list this course. Those outgoing edges are
     * replaced by $prerequisite_ids before the walk.
     *
     * @param  list<int>  $prerequisite_ids
     * @param  array<int, list<int>>  $requires
     * @param  array<int, string>  $names
     */
    public function rejection(int $course_id, array $prerequisite_ids, array $requires, array $names): ?string
    {
        foreach ($prerequisite_ids as $prerequisite_id) {
            if ($prerequisite_id === $course_id) {
                return $this->name($names, $course_id).' cannot require itself.';
            }
        }

        $graph = $requires;
        $graph[$course_id] = $prerequisite_ids;

        foreach ($prerequisite_ids as $prerequisite_id) {
            $path = $this->pathTo($prerequisite_id, $course_id, $graph);

            if ($path !== null) {
                $labels = [];

                foreach ($path as $id) {
                    $labels[] = $this->name($names, $id);
                }

                return $this->cycleSentence($labels);
            }
        }

        return null;
    }

    /**
     * Walk from $start along requirement edges until $target, or give up.
     *
     * @param  array<int, list<int>>  $graph
     * @return list<int>|null
     */
    private function pathTo(int $start, int $target, array $graph): ?array
    {
        $stack = [[$start, [$start]]];
        $visited = [];

        while ($stack !== []) {
            [$node, $path] = array_pop($stack);

            if (isset($visited[$node])) {
                continue;
            }

            $visited[$node] = true;

            foreach ($graph[$node] ?? [] as $next) {
                if ($next === $target) {
                    return [...$path, $next];
                }

                if (! isset($visited[$next])) {
                    $stack[] = [$next, [...$path, $next]];
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $labels
     */
    private function cycleSentence(array $labels): string
    {
        if (count($labels) < 2) {
            return ($labels[0] ?? 'This course').' cannot require itself.';
        }

        if (count($labels) === 2) {
            return $labels[0].' and '.$labels[1].' form a prerequisite cycle.';
        }

        $last = array_pop($labels);

        return implode(', ', $labels).', and '.$last.' form a prerequisite cycle.';
    }

    /**
     * @param  array<int, string>  $names
     */
    private function name(array $names, int $course_id): string
    {
        $name = $names[$course_id] ?? null;

        return is_string($name) && $name !== '' ? $name : (string) $course_id;
    }
}
