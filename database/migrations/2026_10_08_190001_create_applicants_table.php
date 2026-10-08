<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create applicants.
     *
     * Names, email, phone, gender, date of birth, state of origin, and address
     * live here because an applicant has no user yet. Status text is the
     * DESIGN.md label (Under review, Converted). Email is unique on this table
     * only. The same email on users is enforced in a service later.
     */
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('other_names')->nullable();
            $table->string('email');
            $table->string('phone', 32)->nullable();
            $table->string('gender', 32)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('state_of_origin', 64)->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('programme_id');
            $table->unsignedSmallInteger('level');
            $table->unsignedBigInteger('entry_session_id')->nullable();
            $table->string('status', 32)->default('Applied');
            $table->string('source', 32);
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('email', 'applicants_email_unique');
            $table->index('programme_id', 'applicants_programme_id_index');
            $table->index('level', 'applicants_level_index');
            $table->index('entry_session_id', 'applicants_entry_session_id_index');
            $table->index('status', 'applicants_status_index');
            $table->index('source', 'applicants_source_index');
            $table->index('import_batch_id', 'applicants_import_batch_id_index');
            $table->index(['last_name', 'first_name'], 'applicants_last_name_first_name_index');
            $table->foreign('programme_id', 'applicants_programme_id_foreign')
                ->references('id')
                ->on('programmes')
                ->restrictOnDelete();
            $table->foreign('entry_session_id', 'applicants_entry_session_id_foreign')
                ->references('id')
                ->on('academic_sessions')
                ->restrictOnDelete();
            $table->foreign('import_batch_id', 'applicants_import_batch_id_foreign')
                ->references('id')
                ->on('import_batches')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `applicants` add constraint `applicants_level_check` check (`level` in (100, 200, 300, 400, 500, 600))',
        );
        DB::statement(
            "alter table `applicants` add constraint `applicants_gender_check` check (`gender` is null or `gender` in ('Male', 'Female', 'Other'))",
        );
        DB::statement(
            "alter table `applicants` add constraint `applicants_status_check` check (`status` in ('Applied', 'Under review', 'Admitted', 'Rejected', 'Converted'))",
        );
        DB::statement(
            "alter table `applicants` add constraint `applicants_source_check` check (`source` in ('manual', 'csv', 'xlsx', 'google_sheet'))",
        );
    }

    /**
     * Drop applicants.
     */
    public function down(): void
    {
        Schema::dropIfExists('applicants');
    }
};
