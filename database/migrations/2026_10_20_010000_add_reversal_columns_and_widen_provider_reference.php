<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record a reversal and widen the provider reference.
     *
     * Reversed requires a reason, the acting user, and a time. Any other
     * status keeps those three empty. reversed_by is restrict (ADR-026).
     * provider_reference grows to 191 for a provider transaction id.
     */
    public function up(): void
    {
        DB::statement('alter table `payments` modify `provider_reference` varchar(191) null');

        Schema::table('payments', function (Blueprint $table): void {
            $table->text('reversed_reason')->nullable()->after('paid_at');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_reason');
            $table->dateTime('reversed_at')->nullable()->after('reversed_by');
            $table->index('reversed_by', 'payments_reversed_by_index');
            $table->foreign('reversed_by', 'payments_reversed_by_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `payments` add constraint `payments_reversal_fields_check` check (('
            ."`status` = 'Reversed' and `reversed_at` is not null and `reversed_by` is not null and char_length(trim(`reversed_reason`)) > 0"
            .') or ('
            ."`status` <> 'Reversed' and `reversed_reason` is null and `reversed_by` is null and `reversed_at` is null"
            .'))',
        );
    }

    /**
     * Remove the reversal columns and restore the shorter provider reference.
     */
    public function down(): void
    {
        DB::statement('alter table `payments` drop check `payments_reversal_fields_check`');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign('payments_reversed_by_foreign');
            $table->dropIndex('payments_reversed_by_index');
            $table->dropColumn(['reversed_reason', 'reversed_by', 'reversed_at']);
        });

        DB::statement('alter table `payments` modify `provider_reference` varchar(64) null');
    }
};
