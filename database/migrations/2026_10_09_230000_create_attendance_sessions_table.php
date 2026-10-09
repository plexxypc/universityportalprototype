<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create attendance sessions.
     *
     * One class meeting for a course in a semester. Two meetings on the same
     * date stay allowed. The marked student must be registered for that course,
     * and a lecturer may mark only assigned courses. Both are enforced in a
     * service later. Every foreign key is restrict.
     */
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('semester_id');
            $table->date('session_date');
            $table->string('topic');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('course_id', 'attendance_sessions_course_id_index');
            $table->index('semester_id', 'attendance_sessions_semester_id_index');
            $table->index('session_date', 'attendance_sessions_session_date_index');
            $table->index('created_by', 'attendance_sessions_created_by_index');
            $table->foreign('course_id', 'attendance_sessions_course_id_foreign')
                ->references('id')
                ->on('courses')
                ->restrictOnDelete();
            $table->foreign('semester_id', 'attendance_sessions_semester_id_foreign')
                ->references('id')
                ->on('semesters')
                ->restrictOnDelete();
            $table->foreign('created_by', 'attendance_sessions_created_by_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `attendance_sessions` add constraint `attendance_sessions_topic_not_blank_check` check (char_length(trim(`topic`)) > 0)',
        );
    }

    /**
     * Drop attendance sessions.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_sessions');
    }
};
