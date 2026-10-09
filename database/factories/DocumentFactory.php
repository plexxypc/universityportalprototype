<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentOwner;
use App\Models\Applicant;
use App\Models\Document;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The default owner is a student. The path is a local-disk location.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => DocumentOwner::Student,
            'owner_id' => Student::factory(),
            'kind' => 'Admission letter',
            'path' => 'documents/example.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
        ];
    }

    /**
     * Own the file by an applicant.
     */
    public function forApplicant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'owner_type' => DocumentOwner::Applicant,
            'owner_id' => Applicant::factory(),
        ]);
    }
}
