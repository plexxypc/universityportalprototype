<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create semesters.
     *
     * active_flag is a stored generated column, unique across the whole table,
     * so at most one semester is active. The application writes is_active.
     * Switching the active semester has to clear the old row first, inside a
     * transaction. That switch is enforced in a service later.
     */
    public function up(): void
    {
        Schema::create('semesters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('name', 32);
            $table->boolean('is_active')->default(false);
            $table->unsignedTinyInteger('active_flag')->nullable()->storedAs('IF(is_active = 1, 1, NULL)');
            $table->dateTime('registration_deadline')->nullable();
            $table->dateTime('add_drop_deadline')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['session_id', 'name'], 'semesters_session_id_name_unique');
            $table->unique('active_flag', 'semesters_active_flag_unique');
            $table->index('session_id', 'semesters_session_id_index');
            $table->index('is_active', 'semesters_is_active_index');
            $table->foreign('session_id', 'semesters_session_id_foreign')
                ->references('id')
                ->on('academic_sessions')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `semesters` add constraint `semesters_add_drop_after_registration_check` check (`add_drop_deadline` is null or `registration_deadline` is null or `add_drop_deadline` >= `registration_deadline`)',
        );
    }

    /**
     * Drop semesters.
     */
    public function down(): void
    {
        Schema::dropIfExists('semesters');
    }
};
