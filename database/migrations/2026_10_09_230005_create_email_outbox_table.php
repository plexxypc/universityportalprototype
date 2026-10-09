<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the email outbox.
     *
     * body_html and body_text hold the rendered message. A temporary password
     * is not its own column. After a credentials email is sent, and also when
     * its attempts are exhausted or the credentials are re-issued, a service
     * replaces both bodies with a redacted placeholder and sets redacted_at
     * (ADR-008). Subject and last_error must not contain the password. Template
     * names, retry limits, and that redaction are enforced in a service later.
     * sent_at is set exactly when the status is Sent. redacted_at requires
     * Sent, because only a finished credentials email is cleared. The user
     * foreign key is restrict and nullable.
     */
    public function up(): void
    {
        Schema::create('email_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('recipient_email');
            $table->string('template', 64);
            $table->string('subject');
            $table->longText('body_html');
            $table->longText('body_text');
            $table->string('status', 32)->default('Queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('redacted_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('user_id', 'email_outbox_user_id_index');
            $table->index('recipient_email', 'email_outbox_recipient_email_index');
            $table->index('template', 'email_outbox_template_index');
            $table->index('status', 'email_outbox_status_index');
            $table->index('sent_at', 'email_outbox_sent_at_index');
            $table->foreign('user_id', 'email_outbox_user_id_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table `email_outbox` add constraint `email_outbox_status_check` check (`status` in ('Queued', 'Sent', 'Failed'))",
        );
        DB::statement(
            "alter table `email_outbox` add constraint `email_outbox_sent_at_only_when_sent_check` check (`sent_at` is null or `status` = 'Sent')",
        );
        DB::statement(
            "alter table `email_outbox` add constraint `email_outbox_sent_requires_sent_at_check` check (`status` <> 'Sent' or `sent_at` is not null)",
        );
        DB::statement(
            "alter table `email_outbox` add constraint `email_outbox_redacted_at_only_when_sent_check` check (`redacted_at` is null or `status` = 'Sent')",
        );
        DB::statement(
            'alter table `email_outbox` add constraint `email_outbox_attempts_non_negative_check` check (`attempts` >= 0)',
        );
    }

    /**
     * Drop the email outbox.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_outbox');
    }
};
