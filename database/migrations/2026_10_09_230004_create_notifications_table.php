<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create in-app notifications.
     *
     * This is the portal table, not Laravel's polymorphic notification table.
     * User::notifications() points here. NotificationService writes the rows.
     * The portal does not use Laravel's database notification channel. type is
     * an open string; known types are enforced in a service later. The foreign
     * key is restrict. (user_id, read_at) serves the unread count, and
     * (user_id, created_at) serves the bell list.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 64);
            $table->json('data');
            $table->dateTime('read_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['user_id', 'read_at'], 'notifications_user_id_read_at_index');
            $table->index(['user_id', 'created_at'], 'notifications_user_id_created_at_index');
            $table->foreign('user_id', 'notifications_user_id_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    /**
     * Drop notifications.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
