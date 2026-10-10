<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the encrypted secrets payload column.
     *
     * The column is nullable text. Laravel encrypts the array with the app
     * key. Credential and reset bodies stay placeholders; this column holds
     * the render payload until send. No other column changes.
     */
    public function up(): void
    {
        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->text('secrets')->nullable()->after('body_text');
        });
    }

    /**
     * Remove the secrets payload column.
     */
    public function down(): void
    {
        Schema::table('email_outbox', function (Blueprint $table): void {
            $table->dropColumn('secrets');
        });
    }
};
