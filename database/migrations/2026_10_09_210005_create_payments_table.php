<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create payments.
     *
     * reference is the internal id. gateway is plain varchar so a new provider
     * needs no migration (PAY-10). provider_reference is nullable until the
     * gateway returns an RRR or transaction reference. MySQL allows many nulls
     * in payments_gateway_provider_reference_unique, which is that "not issued
     * yet" case. amount_kobo must be at least 1. An invoice may have several
     * payments. Successful requires paid_at. (status, created_at) serves the
     * poller. Both foreign keys are restrict.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 64);
            $table->string('gateway', 32);
            $table->string('provider_reference', 64)->nullable();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status', 32)->default('Pending');
            $table->dateTime('expires_at');
            $table->dateTime('last_checked_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('reference', 'payments_reference_unique');
            $table->unique(
                ['gateway', 'provider_reference'],
                'payments_gateway_provider_reference_unique',
            );
            $table->index('invoice_id', 'payments_invoice_id_index');
            $table->index('student_id', 'payments_student_id_index');
            $table->index('provider_reference', 'payments_provider_reference_index');
            $table->index(['status', 'created_at'], 'payments_status_created_at_index');
            $table->index('created_at', 'payments_created_at_index');
            $table->index('paid_at', 'payments_paid_at_index');
            $table->foreign('invoice_id', 'payments_invoice_id_foreign')
                ->references('id')
                ->on('invoices')
                ->restrictOnDelete();
            $table->foreign('student_id', 'payments_student_id_foreign')
                ->references('id')
                ->on('students')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table `payments` add constraint `payments_status_check` check (`status` in ('Pending', 'Successful', 'Failed', 'Cancelled', 'Expired', 'Reversed'))",
        );
        DB::statement(
            'alter table `payments` add constraint `payments_amount_kobo_positive_check` check (`amount_kobo` >= 1)',
        );
        DB::statement(
            "alter table `payments` add constraint `payments_paid_at_check` check (`status` <> 'Successful' or `paid_at` is not null)",
        );
    }

    /**
     * Drop payments.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
