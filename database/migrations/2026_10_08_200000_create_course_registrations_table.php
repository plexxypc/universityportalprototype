<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create course registrations.
     *
     * One row per student per semester. total_units cannot be negative.
     * decided_by stays restrict. Minimum and maximum units, and keeping
     * total_units equal to the items, are enforced in a service later.
     */
    public function up(): void
    {
        Schema::create('course_registrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('semester_id');
            $table->string('status', 32)->default('Draft');
            $table->unsignedSmallInteger('total_units')->default(0);
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['student_id', 'semester_id'],
                'course_registrations_student_id_semester_id_unique',
            );
            $table->index('semester_id', 'course_registrations_semester_id_index');
            $table->index('status', 'course_registrations_status_index');
            $table->index('decided_by', 'course_registrations_decided_by_index');
            $table->foreign('student_id', 'course_registrations_student_id_foreign')
                ->references('id')
                ->on('students')
                ->restrictOnDelete();
            $table->foreign('semester_id', 'course_registrations_semester_id_foreign')
                ->references('id')
                ->on('semesters')
                ->restrictOnDelete();
            $table->foreign('decided_by', 'course_registrations_decided_by_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table `course_registrations` add constraint `course_registrations_status_check` check (`status` in ('Draft', 'Submitted', 'Approved', 'Rejected'))",
        );
        DB::statement(
            'alter table `course_registrations` add constraint `course_registrations_total_units_non_negative_check` check (`total_units` >= 0)',
        );
    }

    /**
     * Drop course registrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_registrations');
    }
};
