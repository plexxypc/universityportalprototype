<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create course prerequisites.
     *
     * A course cannot require itself. A cycle of two or more courses cannot
     * be rejected with a CHECK. That rule is enforced in a service later.
     */
    public function up(): void
    {
        Schema::create('course_prerequisites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('prerequisite_course_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['course_id', 'prerequisite_course_id'],
                'course_prerequisites_course_id_prerequisite_course_id_unique',
            );
            $table->index(
                'prerequisite_course_id',
                'course_prerequisites_prerequisite_course_id_index',
            );
            $table->foreign('course_id', 'course_prerequisites_course_id_foreign')
                ->references('id')
                ->on('courses')
                ->restrictOnDelete();
            $table->foreign('prerequisite_course_id', 'course_prerequisites_prerequisite_course_id_foreign')
                ->references('id')
                ->on('courses')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `course_prerequisites` add constraint `course_prerequisites_not_self_check` check (`course_id` <> `prerequisite_course_id`)',
        );
    }

    /**
     * Drop course prerequisites.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_prerequisites');
    }
};
