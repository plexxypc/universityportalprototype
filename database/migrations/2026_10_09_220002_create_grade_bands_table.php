<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create grade bands.
     *
     * letter is unique per scheme. min_score cannot exceed max_score, and both
     * sit between 0 and 100. Overlap, gaps, and covering 0 to 100 are enforced
     * in a service later. The foreign key is restrict.
     */
    public function up(): void
    {
        Schema::create('grade_bands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scheme_id');
            $table->decimal('min_score', 5, 2);
            $table->decimal('max_score', 5, 2);
            $table->string('letter', 8);
            $table->decimal('points', 4, 2);
            $table->string('remark', 64);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['scheme_id', 'letter'],
                'grade_bands_scheme_id_letter_unique',
            );
            $table->foreign('scheme_id', 'grade_bands_scheme_id_foreign')
                ->references('id')
                ->on('grading_schemes')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `grade_bands` add constraint `grade_bands_score_range_check` check (`min_score` >= 0 and `min_score` <= `max_score` and `max_score` <= 100)',
        );
        DB::statement(
            'alter table `grade_bands` add constraint `grade_bands_points_non_negative_check` check (`points` >= 0)',
        );
    }

    /**
     * Drop grade bands.
     */
    public function down(): void
    {
        Schema::dropIfExists('grade_bands');
    }
};
