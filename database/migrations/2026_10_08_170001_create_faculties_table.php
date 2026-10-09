<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create faculties. The faculty code is unique across the institution.
     */
    public function up(): void
    {
        Schema::create('faculties', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 32);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('code', 'faculties_code_unique');
            $table->index('name', 'faculties_name_index');
        });
    }

    /**
     * Drop faculties.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculties');
    }
};
