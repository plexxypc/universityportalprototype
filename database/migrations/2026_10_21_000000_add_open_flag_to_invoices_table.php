<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One open invoice per student and session.
     *
     * open_flag is 1 when the status is not Cancelled, and NULL when it is.
     * A unique index allows many cancelled rows and one open row. Existing
     * duplicates fail this migration instead of being removed.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedTinyInteger('open_flag')->nullable()->storedAs("IF(`status` <> 'Cancelled', 1, NULL)");
            $table->unique(['student_id', 'session_id', 'open_flag'], 'invoices_student_session_open_unique');
        });
    }

    /**
     * Remove the open-invoice flag and its unique index.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_student_session_open_unique');
            $table->dropColumn('open_flag');
        });
    }
};
