<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create invoice items.
     *
     * description is the category label copied when the line is created.
     * A later category rename must not rewrite this row. The same category
     * cannot appear twice on one invoice. amount_kobo must be at least 1.
     * Both foreign keys are restrict. A service later keeps the invoice
     * total equal to these lines.
     */
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('fee_category_id');
            $table->string('description');
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['invoice_id', 'fee_category_id'],
                'invoice_items_invoice_id_fee_category_id_unique',
            );
            $table->index('fee_category_id', 'invoice_items_fee_category_id_index');
            $table->foreign('invoice_id', 'invoice_items_invoice_id_foreign')
                ->references('id')
                ->on('invoices')
                ->restrictOnDelete();
            $table->foreign('fee_category_id', 'invoice_items_fee_category_id_foreign')
                ->references('id')
                ->on('fee_categories')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table `invoice_items` add constraint `invoice_items_amount_kobo_positive_check` check (`amount_kobo` >= 1)',
        );
    }

    /**
     * Drop invoice items.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
