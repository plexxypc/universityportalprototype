<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create fee structures.
     *
     * One price per programme, level, session, and fee category. The category
     * is part of the unique key so tuition and ICT can both be priced.
     * The full name with every column would pass MySQL's 64-character limit,
     * so the unique index name is shortened. amount_kobo must be at least 1.
     * Every foreign key is restrict.
     */
    public function up(): void
    {
        Schema::create('fee_structures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fee_category_id');
            $table->unsignedBigInteger('programme_id');
            $table->unsignedSmallInteger('level');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('amount_kobo');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['programme_id', 'level', 'session_id', 'fee_category_id'],
                'fee_structures_programme_level_session_category_unique',
            );
            $table->index('fee_category_id', 'fee_structures_fee_category_id_index');
            $table->index('session_id', 'fee_structures_session_id_index');
            $table->index('level', 'fee_structures_level_index');
            $table->foreign('fee_category_id', 'fee_structures_fee_category_id_foreign')
                ->references('id')
                ->on('fee_categories')
                ->restrictOnDelete();
            $table->foreign('programme_id', 'fee_structures_programme_id_foreign')
                ->references('id')
                ->on('programmes')
                ->restrictOnDelete();
            $table->foreign('session_id', 'fee_structures_session_id_foreign')
                ->references('id')
                ->on('academic_sessions')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `fee_structures` add constraint `fee_structures_level_check` check (`level` in (100, 200, 300, 400, 500, 600))',
        );
        DB::statement(
            'alter table `fee_structures` add constraint `fee_structures_amount_kobo_positive_check` check (`amount_kobo` >= 1)',
        );
    }

    /**
     * Drop fee structures.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_structures');
    }
};
