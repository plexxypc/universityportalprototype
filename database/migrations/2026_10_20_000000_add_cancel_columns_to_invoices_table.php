<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record why an invoice was cancelled.
     *
     * A cancelled row needs a reason and a time. Any other status keeps
     * the three columns empty. cancelled_by is restrict (ADR-026).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->text('cancelled_reason')->nullable()->after('status');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_reason');
            $table->dateTime('cancelled_at')->nullable()->after('cancelled_by');
            $table->index('cancelled_by', 'invoices_cancelled_by_index');
            $table->foreign('cancelled_by', 'invoices_cancelled_by_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `invoices` add constraint `invoices_cancel_fields_check` check (('
            ."`status` = 'Cancelled' and `cancelled_at` is not null and char_length(trim(`cancelled_reason`)) > 0"
            .') or ('
            ."`status` <> 'Cancelled' and `cancelled_reason` is null and `cancelled_by` is null and `cancelled_at` is null"
            .'))',
        );
    }

    /**
     * Remove the cancellation columns.
     */
    public function down(): void
    {
        DB::statement('alter table `invoices` drop check `invoices_cancel_fields_check`');

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign('invoices_cancelled_by_foreign');
            $table->dropIndex('invoices_cancelled_by_index');
            $table->dropColumn(['cancelled_reason', 'cancelled_by', 'cancelled_at']);
        });
    }
};
