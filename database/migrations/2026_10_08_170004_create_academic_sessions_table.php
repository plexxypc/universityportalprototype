<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create academic sessions.
     *
     * current_flag is a stored generated column. A unique index allows many
     * non-current rows (NULL) and one current row (1). MySQL has no partial
     * unique index. The application writes is_current. Switching the current
     * session has to clear the old row first, inside a transaction. That
     * switch is enforced in a service later.
     */
    public function up(): void
    {
        Schema::create('academic_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 32);
            $table->boolean('is_current')->default(false);
            $table->unsignedTinyInteger('current_flag')->nullable()->storedAs('IF(is_current = 1, 1, NULL)');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('name', 'academic_sessions_name_unique');
            $table->unique('current_flag', 'academic_sessions_current_flag_unique');
            $table->index('is_current', 'academic_sessions_is_current_index');
        });
    }

    /**
     * Drop academic sessions.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_sessions');
    }
};
