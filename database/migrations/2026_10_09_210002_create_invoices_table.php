<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create invoices.
     *
     * total_kobo, adjustments_kobo, and paid_kobo are stored totals. A service
     * keeps them equal to the item lines, the reductions, and the successful
     * payments. The balance check is a sum because these columns are unsigned
     * and MySQL unsigned subtraction wraps. Paid means the sums match the
     * total. Unpaid means nothing has been paid. Several invoices per student
     * per session are allowed, so a cancelled invoice can be replaced.
     * Both foreign keys are restrict.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number', 32);
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('total_kobo')->default(0);
            $table->unsignedBigInteger('adjustments_kobo')->default(0);
            $table->unsignedBigInteger('paid_kobo')->default(0);
            $table->string('status', 32)->default('Unpaid');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('number', 'invoices_number_unique');
            $table->index('student_id', 'invoices_student_id_index');
            $table->index('session_id', 'invoices_session_id_index');
            $table->index('status', 'invoices_status_index');
            $table->foreign('student_id', 'invoices_student_id_foreign')
                ->references('id')
                ->on('students')
                ->restrictOnDelete();
            $table->foreign('session_id', 'invoices_session_id_foreign')
                ->references('id')
                ->on('academic_sessions')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table `invoices` add constraint `invoices_status_check` check (`status` in ('Unpaid', 'Part-paid', 'Paid', 'Cancelled'))",
        );
        DB::statement(
            'alter table `invoices` add constraint `invoices_total_kobo_non_negative_check` check (`total_kobo` >= 0)',
        );
        DB::statement(
            'alter table `invoices` add constraint `invoices_adjustments_kobo_non_negative_check` check (`adjustments_kobo` >= 0)',
        );
        DB::statement(
            'alter table `invoices` add constraint `invoices_paid_kobo_non_negative_check` check (`paid_kobo` >= 0)',
        );
        DB::statement(
            'alter table `invoices` add constraint `invoices_paid_within_balance_check` check (`paid_kobo` + `adjustments_kobo` <= `total_kobo`)',
        );
        DB::statement(
            "alter table `invoices` add constraint `invoices_paid_status_check` check (`status` <> 'Paid' or (`paid_kobo` + `adjustments_kobo` = `total_kobo`))",
        );
        DB::statement(
            "alter table `invoices` add constraint `invoices_unpaid_status_check` check (`status` <> 'Unpaid' or `paid_kobo` = 0)",
        );
    }

    /**
     * Drop invoices.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
