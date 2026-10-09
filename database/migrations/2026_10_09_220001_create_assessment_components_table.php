<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create assessment components.
     *
     * Name and sort are unique per scheme. max_score is above 0 and at most 100.
     * The components of one scheme totalling 100 is enforced in a service later.
     * The foreign key is restrict.
     */
    public function up(): void
    {
        Schema::create('assessment_components', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scheme_id');
            $table->string('name', 64);
            $table->decimal('max_score', 5, 2);
            $table->unsignedSmallInteger('sort');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['scheme_id', 'name'],
                'assessment_components_scheme_id_name_unique',
            );
            $table->unique(
                ['scheme_id', 'sort'],
                'assessment_components_scheme_id_sort_unique',
            );
            $table->foreign('scheme_id', 'assessment_components_scheme_id_foreign')
                ->references('id')
                ->on('grading_schemes')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `assessment_components` add constraint `assessment_components_max_score_range_check` check (`max_score` > 0 and `max_score` <= 100)',
        );
    }

    /**
     * Drop assessment components.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_components');
    }
};
