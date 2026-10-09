<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create role assignments.
     *
     * faculty_scope and department_scope replace NULL with 0 so the unique
     * index can see duplicate scopes. They are stored generated columns, not
     * foreign keys. TASK-027 adds the foreign keys on faculty_id and
     * department_id and must use ON DELETE RESTRICT. MySQL rejects ON DELETE
     * CASCADE and ON DELETE SET NULL on those base columns.
     */
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role', 32);
            $table->unsignedBigInteger('faculty_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('faculty_scope')->nullable(false)->storedAs('IFNULL(faculty_id, 0)');
            $table->unsignedBigInteger('department_scope')->nullable(false)->storedAs('IFNULL(department_id, 0)');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('user_id', 'role_assignments_user_id_index');
            $table->index('role', 'role_assignments_role_index');
            $table->index('faculty_id', 'role_assignments_faculty_id_index');
            $table->index('department_id', 'role_assignments_department_id_index');
            $table->unique(
                ['user_id', 'role', 'faculty_scope', 'department_scope'],
                'role_assignments_user_role_scope_unique',
            );
            $table->foreign('user_id', 'role_assignments_user_id_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table `role_assignments` add constraint `role_assignments_role_check` check (`role` in ('SuperAdmin', 'Registrar', 'Bursar', 'FacultyAdmin', 'DepartmentOfficer', 'Lecturer', 'ExamOfficer', 'Student'))",
        );
    }

    /**
     * Drop role assignments.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
