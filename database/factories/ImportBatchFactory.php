<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportSource;
use App\Enums\ImportTarget;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => ImportSource::Csv,
            'target' => ImportTarget::Student,
            'status' => ImportBatchStatus::Processing,
            'total_rows' => 0,
            'processed_rows' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'original_name' => null,
            'failure_report_path' => null,
            'completed_at' => null,
        ];
    }
}
