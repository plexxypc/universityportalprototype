<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create announcements.
     *
     * The audience is one exclusive shape: all, one faculty, one department,
     * one programme, or one level. Level means every student at that level.
     * Which accounts match that shape, and the optional email, are enforced
     * in a service later. published_at is null until the announcement is
     * published. Every foreign key is restrict.
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('author_id');
            $table->string('title');
            $table->text('body');
            $table->string('audience', 32);
            $table->unsignedBigInteger('faculty_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->unsignedSmallInteger('level')->nullable();
            $table->boolean('send_email')->default(false);
            $table->dateTime('published_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('author_id', 'announcements_author_id_index');
            $table->index('audience', 'announcements_audience_index');
            $table->index('faculty_id', 'announcements_faculty_id_index');
            $table->index('department_id', 'announcements_department_id_index');
            $table->index('programme_id', 'announcements_programme_id_index');
            $table->index('level', 'announcements_level_index');
            $table->index('published_at', 'announcements_published_at_index');
            $table->foreign('author_id', 'announcements_author_id_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('faculty_id', 'announcements_faculty_id_foreign')
                ->references('id')
                ->on('faculties')
                ->restrictOnDelete();
            $table->foreign('department_id', 'announcements_department_id_foreign')
                ->references('id')
                ->on('departments')
                ->restrictOnDelete();
            $table->foreign('programme_id', 'announcements_programme_id_foreign')
                ->references('id')
                ->on('programmes')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table `announcements` add constraint `announcements_audience_check` check (`audience` in ('All', 'Faculty', 'Department', 'Programme', 'Level'))",
        );
        DB::statement(
            "alter table `announcements` add constraint `announcements_audience_scope_check` check ((`audience` = 'All' and `faculty_id` is null and `department_id` is null and `programme_id` is null and `level` is null) or (`audience` = 'Faculty' and `faculty_id` is not null and `department_id` is null and `programme_id` is null and `level` is null) or (`audience` = 'Department' and `department_id` is not null and `faculty_id` is null and `programme_id` is null and `level` is null) or (`audience` = 'Programme' and `programme_id` is not null and `faculty_id` is null and `department_id` is null and `level` is null) or (`audience` = 'Level' and `level` is not null and `faculty_id` is null and `department_id` is null and `programme_id` is null))",
        );
        DB::statement(
            'alter table `announcements` add constraint `announcements_level_check` check (`level` is null or `level` in (100, 200, 300, 400, 500, 600))',
        );
        DB::statement(
            'alter table `announcements` add constraint `announcements_title_not_blank_check` check (char_length(trim(`title`)) > 0)',
        );
    }

    /**
     * Drop announcements.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
