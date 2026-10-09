<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create counters.
     *
     * One row per sequence, read with lockForUpdate inside the creating
     * transaction. Matric generation is enforced in a service later. key is
     * quoted because it is a reserved word. The key is required, so this
     * unique index does not rely on MySQL's treatment of nulls.
     */
    public function up(): void
    {
        Schema::create('counters', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64);
            $table->unsignedBigInteger('value')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('key', 'counters_key_unique');
        });

        DB::statement(
            'alter table `counters` add constraint `counters_value_non_negative_check` check (`value` >= 0)',
        );
    }

    /**
     * Drop counters.
     */
    public function down(): void
    {
        Schema::dropIfExists('counters');
    }
};
