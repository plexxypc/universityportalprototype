<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the exam timetable.
     *
     * Informational only. This table has no link to any CBT concept (ADR-004).
     * start_time and end_time are clock times on exam_date. An overnight exam
     * is rejected. published_at is null until the timetable is published.
     * Clash detection, limiting a student to registered courses, and the
     * publication notification are enforced in a service later. Both foreign
     * keys are restrict.
     */
    public function up(): void
    {
        Schema::create('exam_timetable', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('semester_id');
            $table->date('exam_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('venue', 128);
            $table->text('notes')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('course_id', 'exam_timetable_course_id_index');
            $table->index('semester_id', 'exam_timetable_semester_id_index');
            $table->index('exam_date', 'exam_timetable_exam_date_index');
            $table->index('published_at', 'exam_timetable_published_at_index');
            $table->foreign('course_id', 'exam_timetable_course_id_foreign')
                ->references('id')
                ->on('courses')
                ->restrictOnDelete();
            $table->foreign('semester_id', 'exam_timetable_semester_id_foreign')
                ->references('id')
                ->on('semesters')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `exam_timetable` add constraint `exam_timetable_end_time_after_start_time_check` check (`end_time` > `start_time`)',
        );
        DB::statement(
            'alter table `exam_timetable` add constraint `exam_timetable_venue_not_blank_check` check (char_length(trim(`venue`)) > 0)',
        );
    }

    /**
     * Drop the exam timetable.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_timetable');
    }
};
