<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create course registration items.
     *
     * credit_units is copied from the catalogue when the item is created.
     * A later catalogue change must not rewrite this row. Both foreign keys
     * are restrict, including the link back to the registration.
     * The full unique name course_registration_items_course_registration_id_course_id_unique
     * is 65 characters, past MySQL's 64-character limit, so the index name is shortened.
     */
    public function up(): void
    {
        Schema::create('course_registration_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_registration_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedTinyInteger('credit_units');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['course_registration_id', 'course_id'],
                'course_registration_items_registration_course_unique',
            );
            $table->index('course_id', 'course_registration_items_course_id_index');
            $table->foreign('course_registration_id', 'course_registration_items_course_registration_id_foreign')
                ->references('id')
                ->on('course_registrations')
                ->restrictOnDelete();
            $table->foreign('course_id', 'course_registration_items_course_id_foreign')
                ->references('id')
                ->on('courses')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `course_registration_items` add constraint `course_registration_items_credit_units_positive_check` check (`credit_units` >= 1)',
        );
    }

    /**
     * Drop course registration items.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_registration_items');
    }
};
