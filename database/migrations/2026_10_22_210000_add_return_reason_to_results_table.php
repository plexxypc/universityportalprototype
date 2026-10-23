<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the reason an approver sent a result back to draft.
     *
     * The column is cleared when the lecturer submits again. The audit row
     * keeps the same text. The in-app notice is shortened to fit.
     */
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table): void {
            $table->text('return_reason')->nullable()->after('approved_by');
        });
    }

    /**
     * Remove the return reason.
     */
    public function down(): void
    {
        Schema::table('results', function (Blueprint $table): void {
            $table->dropColumn('return_reason');
        });
    }
};
