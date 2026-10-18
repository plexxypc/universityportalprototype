<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store one reopen window on the registration row.
     *
     * reopened_until is a Lagos wall-clock DATETIME. The window is open
     * while now is strictly before that instant. reopened_by is restrict,
     * the same delete rule as decided_by (ADR-026). The reason stays on
     * the row and is not copied into the audit snapshot.
     */
    public function up(): void
    {
        Schema::table('course_registrations', function (Blueprint $table): void {
            $table->dateTime('reopened_until')->nullable()->after('rejection_reason');
            $table->text('reopen_reason')->nullable()->after('reopened_until');
            $table->unsignedBigInteger('reopened_by')->nullable()->after('reopen_reason');
            $table->index('reopened_until', 'course_registrations_reopened_until_index');
            $table->foreign('reopened_by', 'course_registrations_reopened_by_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    /**
     * Remove the reopen window.
     */
    public function down(): void
    {
        Schema::table('course_registrations', function (Blueprint $table): void {
            $table->dropForeign('course_registrations_reopened_by_foreign');
            $table->dropIndex('course_registrations_reopened_until_index');
            $table->dropColumn(['reopened_until', 'reopen_reason', 'reopened_by']);
        });
    }
};
