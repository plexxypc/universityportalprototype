<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create receipts.
     *
     * One receipt per payment. number is unique on its own. The amount lives
     * on the payment. The foreign key is restrict. A service creates the row
     * only after the payment is successful.
     */
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->string('number', 32);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('payment_id', 'receipts_payment_id_unique');
            $table->unique('number', 'receipts_number_unique');
            $table->foreign('payment_id', 'receipts_payment_id_foreign')
                ->references('id')
                ->on('payments')
                ->restrictOnDelete();
        });
    }

    /**
     * Drop receipts.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
