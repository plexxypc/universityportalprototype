<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create programmes. The programme code is unique across the institution
     * because student import matches programme_code alone. degree is free text.
     */
    public function up(): void
    {
        Schema::create('programmes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->string('name');
            $table->string('code', 32);
            $table->string('degree', 64);
            $table->unsignedTinyInteger('duration_years');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('code', 'programmes_code_unique');
            $table->index('department_id', 'programmes_department_id_index');
            $table->index('name', 'programmes_name_index');
            $table->foreign('department_id', 'programmes_department_id_foreign')
                ->references('id')
                ->on('departments')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `programmes` add constraint `programmes_duration_years_check` check (`duration_years` >= 1 and `duration_years` <= 10)',
        );
    }

    /**
     * Drop programmes.
     */
    public function down(): void
    {
        Schema::dropIfExists('programmes');
    }
};
