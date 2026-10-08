<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Map each course into a programme once, with level, semester number and type.
     */
    public function up(): void
    {
        Schema::create('programme_courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('programme_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedSmallInteger('level');
            $table->unsignedTinyInteger('semester_no');
            $table->string('type', 32);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['programme_id', 'course_id'],
                'programme_courses_programme_id_course_id_unique',
            );
            $table->index('course_id', 'programme_courses_course_id_index');
            $table->index('level', 'programme_courses_level_index');
            $table->index('semester_no', 'programme_courses_semester_no_index');
            $table->index('type', 'programme_courses_type_index');
            $table->foreign('programme_id', 'programme_courses_programme_id_foreign')
                ->references('id')
                ->on('programmes')
                ->restrictOnDelete();
            $table->foreign('course_id', 'programme_courses_course_id_foreign')
                ->references('id')
                ->on('courses')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `programme_courses` add constraint `programme_courses_level_check` check (`level` in (100, 200, 300, 400, 500, 600))',
        );
        DB::statement(
            'alter table `programme_courses` add constraint `programme_courses_semester_no_check` check (`semester_no` in (1, 2))',
        );
        DB::statement(
            "alter table `programme_courses` add constraint `programme_courses_type_check` check (`type` in ('Core', 'Elective'))",
        );
    }

    /**
     * Drop programme courses.
     */
    public function down(): void
    {
        Schema::dropIfExists('programme_courses');
    }
};
