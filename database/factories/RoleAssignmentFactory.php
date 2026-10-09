<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Department;
use App\Models\Faculty;
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

    /**
     * Assign FacultyAdmin, scoped to one faculty.
     */
    public function facultyAdmin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => Role::FacultyAdmin,
            'faculty_id' => Faculty::factory(),
            'department_id' => null,
        ]);
    }

    /**
     * Assign DepartmentOfficer, scoped to one department.
     */
    public function departmentOfficer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => Role::DepartmentOfficer,
            'faculty_id' => null,
            'department_id' => Department::factory(),
        ]);
    }
}
