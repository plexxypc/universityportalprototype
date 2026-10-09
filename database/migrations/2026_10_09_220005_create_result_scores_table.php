<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create result scores.
     *
     * One score per result and component. score cannot be negative. Both
     * foreign keys are restrict, so a result stays while a score still points
     * at it. A service later checks the score against the component maximum
     * and that the component belongs to the result's scheme.
     */
    public function up(): void
    {
        Schema::create('result_scores', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('result_id');
            $table->unsignedBigInteger('component_id');
            $table->decimal('score', 5, 2);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['result_id', 'component_id'],
                'result_scores_result_id_component_id_unique',
            );
            $table->index('component_id', 'result_scores_component_id_index');
            $table->foreign('result_id', 'result_scores_result_id_foreign')
                ->references('id')
                ->on('results')
                ->restrictOnDelete();
            $table->foreign('component_id', 'result_scores_component_id_foreign')
                ->references('id')
                ->on('assessment_components')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `result_scores` add constraint `result_scores_score_non_negative_check` check (`score` >= 0)',
        );
    }

    /**
     * Drop result scores.
     */
    public function down(): void
    {
        Schema::dropIfExists('result_scores');
    }
};
