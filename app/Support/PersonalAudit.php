<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Audit snapshots that keep personal values off the row.
 *
 * A changed personal field contributes its name only. Address, date of
 * birth, and phone values are never stored. Email and the non-sensitive
 * fields passed in value_fields store the old and new values.
 */
final class PersonalAudit
{
    /**
     * Build the before and after arrays for one change.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $personal_fields
     * @param  list<string>  $value_fields
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public static function diff(array $before, array $after, array $personal_fields, array $value_fields): array
    {
        $changed = [];

        foreach ($personal_fields as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        $before_row = [];
        $after_row = [];

        if ($changed !== []) {
            $before_row['changed'] = $changed;
            $after_row['changed'] = $changed;
        }

        foreach ($value_fields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            if ($old !== $new) {
                $before_row[$field] = $old;
                $after_row[$field] = $new;
            }
        }

        return [
            'before' => $before_row,
            'after' => $after_row,
        ];
    }
}
