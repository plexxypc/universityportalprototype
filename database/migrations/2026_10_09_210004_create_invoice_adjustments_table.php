<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create invoice adjustments.
     *
     * Discounts, waivers, and scholarships only. amount_kobo is a reduction
     * and must be at least 1. reason and created_by are required. A blank
     * reason is rejected. A service later adds this amount into
     * invoices.adjustments_kobo. Both foreign keys are restrict.
     */
    public function up(): void
    {
        Schema::create('invoice_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->string('type', 32);
            $table->unsignedBigInteger('amount_kobo');
            $table->text('reason');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('invoice_id', 'invoice_adjustments_invoice_id_index');
            $table->index('type', 'invoice_adjustments_type_index');
            $table->index('created_by', 'invoice_adjustments_created_by_index');
            $table->foreign('invoice_id', 'invoice_adjustments_invoice_id_foreign')
                ->references('id')
                ->on('invoices')
                ->restrictOnDelete();
            $table->foreign('created_by', 'invoice_adjustments_created_by_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table `invoice_adjustments` add constraint `invoice_adjustments_type_check` check (`type` in ('Discount', 'Waiver', 'Scholarship'))",
        );
        DB::statement(
            'alter table `invoice_adjustments` add constraint `invoice_adjustments_amount_kobo_positive_check` check (`amount_kobo` >= 1)',
        );
        DB::statement(
            'alter table `invoice_adjustments` add constraint `invoice_adjustments_reason_not_blank_check` check (char_length(trim(`reason`)) > 0)',
        );
    }

    /**
     * Drop invoice adjustments.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_adjustments');
    }
};
