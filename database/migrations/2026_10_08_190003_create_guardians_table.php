<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create guardians.
     *
     * student_id is ON DELETE CASCADE (ADR-027). A guardian row is only a
     * child of that student. Every other foreign key stays restrict.
     */
    public function up(): void
    {
        Schema::create('guardians', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('relationship', 64)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('student_id', 'guardians_student_id_index');
            $table->foreign('student_id', 'guardians_student_id_foreign')
                ->references('id')
                ->on('students')
                ->cascadeOnDelete();
        });
    }

    /**
     * Drop guardians.
     */
    public function down(): void
    {
        Schema::dropIfExists('guardians');
    }
};
