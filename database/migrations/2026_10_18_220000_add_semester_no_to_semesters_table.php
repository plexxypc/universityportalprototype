<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Map a free-text semester name to programme course semester 1 or 2.
     *
     * The number stays nullable so rows saved before this column existed
     * remain valid. Registration treats a null as closed. A session can
     * hold each number once. Nulls are not unique with each other.
     */
    public function up(): void
    {
        Schema::table('semesters', function (Blueprint $table): void {
            $table->unsignedTinyInteger('semester_no')->nullable()->after('name');
            $table->unique(
                ['session_id', 'semester_no'],
                'semesters_session_id_semester_no_unique',
            );
        });

        DB::statement(
            'alter table `semesters` add constraint `semesters_semester_no_check` check (`semester_no` is null or `semester_no` in (1, 2))',
        );
    }

    /**
     * Remove the semester number.
     */
    public function down(): void
    {
        DB::statement('alter table `semesters` drop check `semesters_semester_no_check`');

        Schema::table('semesters', function (Blueprint $table): void {
            $table->dropUnique('semesters_session_id_semester_no_unique');
            $table->dropColumn('semester_no');
        });
    }
};
