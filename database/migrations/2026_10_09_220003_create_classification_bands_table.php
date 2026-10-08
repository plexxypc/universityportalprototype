<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create classification bands.
     *
     * Name is unique per scheme. min_cgpa cannot exceed max_cgpa. Overlap and
     * gaps are enforced in a service later. The foreign key is restrict.
     */
    public function up(): void
    {
        Schema::create('classification_bands', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scheme_id');
            $table->string('name', 64);
            $table->decimal('min_cgpa', 4, 2);
            $table->decimal('max_cgpa', 4, 2);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['scheme_id', 'name'],
                'classification_bands_scheme_id_name_unique',
            );
            $table->foreign('scheme_id', 'classification_bands_scheme_id_foreign')
                ->references('id')
                ->on('grading_schemes')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `classification_bands` add constraint `classification_bands_cgpa_range_check` check (`min_cgpa` >= 0 and `min_cgpa` <= `max_cgpa`)',
        );
    }

    /**
     * Drop classification bands.
     */
    public function down(): void
    {
        Schema::dropIfExists('classification_bands');
    }
};
