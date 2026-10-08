<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create departments. The department code is unique across the institution
     * because the matric pattern uses {DEPT} alone.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('faculty_id');
            $table->string('name');
            $table->string('code', 32);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('code', 'departments_code_unique');
            $table->index('faculty_id', 'departments_faculty_id_index');
            $table->index('name', 'departments_name_index');
            $table->foreign('faculty_id', 'departments_faculty_id_foreign')
                ->references('id')
                ->on('faculties')
                ->restrictOnDelete();
        });
    }

    /**
     * Drop departments.
     */
    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
