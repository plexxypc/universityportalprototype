<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * before and after are ordinary snapshots. A service later removes secrets.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory(),
            'action' => 'student.created',
            'entity' => 'students',
            'entity_id' => 1,
            'before' => null,
            'after' => ['status' => 'Active'],
            'ip' => '127.0.0.1',
        ];
    }
}
