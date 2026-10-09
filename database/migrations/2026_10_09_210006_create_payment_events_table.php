<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create payment events.
     *
     * The unique key is (provider, event_key), named
     * payment_events_provider_event_key_unique (ADR-026). provider is plain
     * varchar so a new provider needs no migration (PAY-10). payload and
     * source record the trigger. payment_id may be null when the event cannot
     * be matched yet. updated_at stays because ADR-026 puts it on every table.
     * A service inserts these rows and does not update them. The foreign key
     * is restrict.
     */
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_key', 191);
            $table->json('payload');
            $table->string('source', 32);
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(
                ['provider', 'event_key'],
                'payment_events_provider_event_key_unique',
            );
            $table->index('payment_id', 'payment_events_payment_id_index');
            $table->index('source', 'payment_events_source_index');
            $table->index('created_at', 'payment_events_created_at_index');
            $table->foreign('payment_id', 'payment_events_payment_id_foreign')
                ->references('id')
                ->on('payments')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table `payment_events` add constraint `payment_events_source_check` check (`source` in ('callback', 'notification', 'poll', 'manual'))",
        );
    }

    /**
     * Drop payment events.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
