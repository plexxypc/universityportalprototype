<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the identity columns from ARCHITECTURE sections 6 and 10.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable();
            $table->string('status', 32)->default('Active');
            $table->boolean('must_change_password')->default(false);
            $table->dateTime('temp_password_expires_at')->nullable();
            $table->dateTime('last_login_at')->nullable();

            $table->index('status', 'users_status_index');
        });

        DB::statement(
            "alter table `users` add constraint `users_status_check` check (`status` in ('Active', 'Suspended', 'Deactivated'))",
        );
    }

    /**
     * Remove the identity columns added to users.
     */
    public function down(): void
    {
        DB::statement('alter table `users` drop check `users_status_check`');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_status_index');
            $table->dropColumn([
                'phone',
                'status',
                'must_change_password',
                'temp_password_expires_at',
                'last_login_at',
            ]);
        });
    }
};
