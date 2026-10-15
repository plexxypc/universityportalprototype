<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link an outbox row to the record that caused it.
     *
     * Admission mail uses related_type applicants and the applicant id.
     * Both columns stay nullable so older rows remain valid. There is no
     * foreign key, because the type names the table.
     */
    public function up(): void
    {
        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->string('related_type', 64)->nullable()->after('user_id');
            $table->unsignedBigInteger('related_id')->nullable()->after('related_type');
            $table->index(
                ['related_type', 'related_id', 'template'],
                'email_outbox_related_type_related_id_template_index',
            );
        });
    }

    /**
     * Remove the related columns.
     */
    public function down(): void
    {
        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->dropIndex('email_outbox_related_type_related_id_template_index');
            $table->dropColumn(['related_type', 'related_id']);
        });
    }
};
