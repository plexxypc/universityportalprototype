<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create students.
     *
     * Name parts, gender, date of birth, state of origin, and address live
     * here. Email and phone stay on users. users.name is filled from the name
     * parts in a service later. Faculty and department come from the programme.
     * matric_no is supplied by the caller. Generation waits for counters.
     * applicant_id is unique and nullable. MySQL allows many nulls in that
     * unique index, which is the "no applicant" case. This is not the
     * one-current-row flag column.
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('applicant_id')->nullable();
            $table->string('matric_no', 32);
            $table->unsignedBigInteger('programme_id');
            $table->unsignedSmallInteger('level');
            $table->unsignedBigInteger('entry_session_id');
            $table->string('status', 32)->default('Active');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('other_names')->nullable();
            $table->string('gender', 32)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('state_of_origin', 64)->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('user_id', 'students_user_id_unique');
            $table->unique('applicant_id', 'students_applicant_id_unique');
            $table->unique('matric_no', 'students_matric_no_unique');
            $table->index('programme_id', 'students_programme_id_index');
            $table->index('level', 'students_level_index');
            $table->index('entry_session_id', 'students_entry_session_id_index');
            $table->index('status', 'students_status_index');
            $table->index('import_batch_id', 'students_import_batch_id_index');
            $table->index(['last_name', 'first_name'], 'students_last_name_first_name_index');
            $table->foreign('user_id', 'students_user_id_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('applicant_id', 'students_applicant_id_foreign')
                ->references('id')
                ->on('applicants')
                ->restrictOnDelete();
            $table->foreign('programme_id', 'students_programme_id_foreign')
                ->references('id')
                ->on('programmes')
                ->restrictOnDelete();
            $table->foreign('entry_session_id', 'students_entry_session_id_foreign')
                ->references('id')
                ->on('academic_sessions')
                ->restrictOnDelete();
            $table->foreign('import_batch_id', 'students_import_batch_id_foreign')
                ->references('id')
                ->on('import_batches')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `students` add constraint `students_level_check` check (`level` in (100, 200, 300, 400, 500, 600))',
        );
        DB::statement(
            "alter table `students` add constraint `students_gender_check` check (`gender` is null or `gender` in ('Male', 'Female', 'Other'))",
        );
        DB::statement(
            "alter table `students` add constraint `students_status_check` check (`status` in ('Active', 'Suspended', 'Deferred', 'Graduated', 'Withdrawn'))",
        );
    }

    /**
     * Drop students.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
