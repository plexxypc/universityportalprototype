<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create academic standing thresholds.
     *
     * One row per scheme version. No row means standing is off. Each value
     * may be null, and a set value is at least 0. When both are set, probation
     * is below warning. The service refuses a row that sets neither. There is
     * no deleted_at. The foreign key is restrict.
     */
    public function up(): void
    {
        Schema::create('academic_standing_thresholds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scheme_id');
            $table->decimal('warning_cgpa', 4, 2)->nullable();
            $table->decimal('probation_cgpa', 4, 2)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('scheme_id', 'academic_standing_thresholds_scheme_id_unique');
            $table->foreign('scheme_id', 'academic_standing_thresholds_scheme_id_foreign')
                ->references('id')
                ->on('grading_schemes')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `academic_standing_thresholds` add constraint `academic_standing_thresholds_warning_check` check (`warning_cgpa` is null or `warning_cgpa` >= 0)',
        );
        DB::statement(
            'alter table `academic_standing_thresholds` add constraint `academic_standing_thresholds_probation_check` check (`probation_cgpa` is null or `probation_cgpa` >= 0)',
        );
        DB::statement(
            'alter table `academic_standing_thresholds` add constraint `academic_standing_thresholds_order_check` check (`warning_cgpa` is null or `probation_cgpa` is null or `probation_cgpa` < `warning_cgpa`)',
        );
    }

    /**
     * Drop academic standing thresholds.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_standing_thresholds');
    }
};
