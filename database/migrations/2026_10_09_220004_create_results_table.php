<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create results.
     *
     * One row per student, course, and semester. A repeated course is another
     * row in a later semester. Both rows stay. Which attempt counts is enforced
     * in a service later. total, grade, and points are stored together: all
     * null or all set. published_at is set exactly when the status is Published:
     * it is null for every other status, and a Published row requires it.
     * Approved and Published rows require approved_by. Every foreign key is
     * restrict. grading_scheme_id points at the scheme row used. scheme_version
     * copies that row's version. A service later keeps the copy equal to the row.
     */
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('semester_id');
            $table->unsignedBigInteger('grading_scheme_id');
            $table->unsignedInteger('scheme_version');
            $table->decimal('total', 5, 2)->nullable();
            $table->string('grade', 8)->nullable();
            $table->decimal('points', 4, 2)->nullable();
            $table->string('status', 32)->default('Draft');
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['student_id', 'course_id', 'semester_id'],
                'results_student_id_course_id_semester_id_unique',
            );
            $table->index('course_id', 'results_course_id_index');
            $table->index('semester_id', 'results_semester_id_index');
            $table->index('grading_scheme_id', 'results_grading_scheme_id_index');
            $table->index('status', 'results_status_index');
            $table->index('entered_by', 'results_entered_by_index');
            $table->index('approved_by', 'results_approved_by_index');
            $table->index('published_at', 'results_published_at_index');
            $table->foreign('student_id', 'results_student_id_foreign')
                ->references('id')
                ->on('students')
                ->restrictOnDelete();
            $table->foreign('course_id', 'results_course_id_foreign')
                ->references('id')
                ->on('courses')
                ->restrictOnDelete();
            $table->foreign('semester_id', 'results_semester_id_foreign')
                ->references('id')
                ->on('semesters')
                ->restrictOnDelete();
            $table->foreign('grading_scheme_id', 'results_grading_scheme_id_foreign')
                ->references('id')
                ->on('grading_schemes')
                ->restrictOnDelete();
            $table->foreign('entered_by', 'results_entered_by_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('approved_by', 'results_approved_by_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table `results` add constraint `results_status_check` check (`status` in ('Draft', 'Submitted', 'Approved', 'Published'))",
        );
        DB::statement(
            'alter table `results` add constraint `results_total_non_negative_check` check (`total` is null or `total` >= 0)',
        );
        DB::statement(
            'alter table `results` add constraint `results_points_non_negative_check` check (`points` is null or `points` >= 0)',
        );
        DB::statement(
            'alter table `results` add constraint `results_score_fields_all_or_nothing_check` check ((`total` is null and `grade` is null and `points` is null) or (`total` is not null and `grade` is not null and `points` is not null))',
        );
        DB::statement(
            "alter table `results` add constraint `results_approved_requires_approved_by_check` check (`status` not in ('Approved', 'Published') or `approved_by` is not null)",
        );
        DB::statement(
            "alter table `results` add constraint `results_published_at_only_when_published_check` check (`published_at` is null or `status` = 'Published')",
        );
        DB::statement(
            "alter table `results` add constraint `results_published_requires_published_at_check` check (`status` <> 'Published' or `published_at` is not null)",
        );
    }

    /**
     * Drop results.
     */
    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
