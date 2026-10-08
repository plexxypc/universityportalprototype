<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the course catalogue. Level, semester and core/elective live on
     * programme_courses, not on this table.
     */
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->string('code', 32);
            $table->string('title');
            $table->unsignedTinyInteger('credit_units');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('code', 'courses_code_unique');
            $table->index('department_id', 'courses_department_id_index');
            $table->index('title', 'courses_title_index');
            $table->foreign('department_id', 'courses_department_id_foreign')
                ->references('id')
                ->on('departments')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `courses` add constraint `courses_credit_units_positive_check` check (`credit_units` >= 1)',
        );
    }

    /**
     * Drop courses.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
