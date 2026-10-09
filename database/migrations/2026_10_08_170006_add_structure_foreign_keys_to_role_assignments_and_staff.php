<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the foreign keys deferred by TASK-026.
     *
     * faculty_id and department_id feed stored generated columns on
     * role_assignments. MySQL rejects ON DELETE CASCADE and ON DELETE SET NULL
     * on those base columns, so each foreign key is ON DELETE RESTRICT.
     * staff.department_id uses RESTRICT for the same rule (ADR-026).
     */
    public function up(): void
    {
        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->foreign('faculty_id', 'role_assignments_faculty_id_foreign')
                ->references('id')
                ->on('faculties')
                ->restrictOnDelete();
            $table->foreign('department_id', 'role_assignments_department_id_foreign')
                ->references('id')
                ->on('departments')
                ->restrictOnDelete();
        });

        Schema::table('staff', function (Blueprint $table): void {
            $table->foreign('department_id', 'staff_department_id_foreign')
                ->references('id')
                ->on('departments')
                ->restrictOnDelete();
        });
    }

    /**
     * Remove the deferred faculty and department foreign keys.
     */
    public function down(): void
    {
        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->dropForeign('role_assignments_faculty_id_foreign');
            $table->dropForeign('role_assignments_department_id_foreign');
        });

        Schema::table('staff', function (Blueprint $table): void {
            $table->dropForeign('staff_department_id_foreign');
        });
    }
};
