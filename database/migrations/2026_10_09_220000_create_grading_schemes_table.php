<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create grading schemes.
     *
     * Each edit inserts a new row. version is unique. active_flag is a stored
     * generated column so many inactive rows are NULL and one active row is 1.
     * MySQL has no partial unique index. The application writes is_active.
     * Switching the active scheme clears the old row first, inside a transaction.
     * That switch is enforced in a service later. resit_points_cap is null when
     * a repeat has no grade-point cap.
     */
    public function up(): void
    {
        Schema::create('grading_schemes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64);
            $table->decimal('pass_mark', 5, 2);
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(false);
            $table->unsignedTinyInteger('active_flag')->nullable()->storedAs('IF(is_active = 1, 1, NULL)');
            $table->string('repeat_policy', 32)->default('Latest');
            $table->decimal('resit_points_cap', 4, 2)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('version', 'grading_schemes_version_unique');
            $table->unique('active_flag', 'grading_schemes_active_flag_unique');
            $table->index('name', 'grading_schemes_name_index');
            $table->index('is_active', 'grading_schemes_is_active_index');
        });

        DB::statement(
            'alter table `grading_schemes` add constraint `grading_schemes_pass_mark_range_check` check (`pass_mark` >= 0 and `pass_mark` <= 100)',
        );
        DB::statement(
            "alter table `grading_schemes` add constraint `grading_schemes_repeat_policy_check` check (`repeat_policy` in ('Latest', 'Best'))",
        );
        DB::statement(
            'alter table `grading_schemes` add constraint `grading_schemes_resit_points_cap_non_negative_check` check (`resit_points_cap` is null or `resit_points_cap` >= 0)',
        );
    }

    /**
     * Drop grading schemes.
     */
    public function down(): void
    {
        Schema::dropIfExists('grading_schemes');
    }
};
