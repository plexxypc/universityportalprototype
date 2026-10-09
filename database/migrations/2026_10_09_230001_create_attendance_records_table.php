<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create attendance records.
     *
     * One mark per session and student. Status labels match DESIGN.md.
     * Percentage and the attendance threshold are enforced in a service later.
     * Both foreign keys are restrict because these rows are student records.
     */
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('attendance_session_id');
            $table->unsignedBigInteger('student_id');
            $table->string('status', 32);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['attendance_session_id', 'student_id'],
                'attendance_records_attendance_session_id_student_id_unique',
            );
            $table->index('student_id', 'attendance_records_student_id_index');
            $table->foreign('attendance_session_id', 'attendance_records_attendance_session_id_foreign')
                ->references('id')
                ->on('attendance_sessions')
                ->restrictOnDelete();
            $table->foreign('student_id', 'attendance_records_student_id_foreign')
                ->references('id')
                ->on('students')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table `attendance_records` add constraint `attendance_records_status_check` check (`status` in ('Present', 'Absent', 'Late', 'Excused'))",
        );
    }

    /**
     * Drop attendance records.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
