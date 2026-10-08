<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleAssignment>
 */
class RoleAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * faculty_scope and department_scope are omitted on purpose. MySQL generates them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => Role::SuperAdmin,
            'faculty_id' => null,
            'department_id' => null,
        ];
    }
}
