<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create audit logs.
     *
     * Append-only is a service rule: only the audit service inserts, and
     * nothing updates or deletes. There is no database trigger. Database-level
     * protection is revisited in Phase 19 once the production host is chosen.
     * actor_id is nullable and restrict, so a row can exist with no actor and
     * deleting an actor is blocked. entity_id is nullable because a failed
     * login for an unknown identifier has no entity. entity stays required.
     * There is no foreign key on entity_id because the table depends on entity.
     * Removing secrets from before and after is enforced in a service later.
     * created_at is the only timestamp.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 64);
            $table->string('entity', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index('actor_id', 'audit_logs_actor_id_index');
            $table->index(['entity', 'entity_id'], 'audit_logs_entity_entity_id_index');
            $table->index('created_at', 'audit_logs_created_at_index');
            $table->foreign('actor_id', 'audit_logs_actor_id_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    /**
     * Drop audit logs.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
