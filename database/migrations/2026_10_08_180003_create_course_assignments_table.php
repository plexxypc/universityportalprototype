<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assign a staff member to a course for one semester.
     */
    public function up(): void
    {
        Schema::create('course_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('semester_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['staff_id', 'course_id', 'semester_id'],
                'course_assignments_staff_id_course_id_semester_id_unique',
            );
            $table->index('course_id', 'course_assignments_course_id_index');
            $table->index('semester_id', 'course_assignments_semester_id_index');
            $table->foreign('staff_id', 'course_assignments_staff_id_foreign')
                ->references('id')
                ->on('staff')
                ->restrictOnDelete();
            $table->foreign('course_id', 'course_assignments_course_id_foreign')
                ->references('id')
                ->on('courses')
                ->restrictOnDelete();
            $table->foreign('semester_id', 'course_assignments_semester_id_foreign')
                ->references('id')
                ->on('semesters')
                ->restrictOnDelete();
        });
    }

    /**
     * Drop course assignments.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_assignments');
    }
};
