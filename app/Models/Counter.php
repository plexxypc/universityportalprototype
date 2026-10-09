<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CounterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One named sequence.
 *
 * The matric generator reads the row with lockForUpdate. That generator is
 * enforced in a service later.
 */
#[Fillable([
    'key',
    'value',
])]
class Counter extends Model
{
    /** @use HasFactory<CounterFactory> */
    use HasFactory;

    /**
     * Cast the sequence value.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }
}
