<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the singleton institution settings row.
     *
     * singleton_key is always 1, and that column is unique, so the table holds
     * zero rows or one row. A second row fails. No row is seeded. Reads fall
     * back to config/portal.php while the table is empty. That fallback is
     * enforced in a service later (Phase 6).
     */
    public function up(): void
    {
        Schema::create('institution_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('singleton_key')->default(1);
            $table->string('name');
            $table->string('code', 32);
            $table->string('logo_path')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('motto')->nullable();
            $table->string('matric_pattern', 64)->default('{DEPT}/{YEAR}/{SEQ4}');
            $table->unsignedTinyInteger('min_units')->default(15);
            $table->unsignedTinyInteger('max_units')->default(24);
            $table->boolean('approval_required')->default(true);
            $table->boolean('withhold_results_for_debt')->default(false);
            $table->unsignedTinyInteger('attendance_threshold')->default(75);
            // REG-10 is one on/off switch. The PRD states no amount and no percentage.
            $table->boolean('require_minimum_payment')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('singleton_key', 'institution_settings_singleton_key_unique');
        });

        DB::statement(
            'alter table `institution_settings` add constraint `institution_settings_singleton_key_check` check (`singleton_key` = 1)',
        );
        DB::statement(
            'alter table `institution_settings` add constraint `institution_settings_units_check` check (`min_units` >= 1 and `max_units` >= `min_units`)',
        );
        DB::statement(
            'alter table `institution_settings` add constraint `institution_settings_attendance_threshold_check` check (`attendance_threshold` <= 100)',
        );
    }

    /**
     * Drop institution settings.
     */
    public function down(): void
    {
        Schema::dropIfExists('institution_settings');
    }
};
