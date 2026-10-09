<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create staff profiles. Faculty is derived from the department later.
     */
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('staff_no', 32);
            $table->string('title', 100);
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('status', 32)->default('Active');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('user_id', 'staff_user_id_unique');
            $table->unique('staff_no', 'staff_staff_no_unique');
            $table->index('department_id', 'staff_department_id_index');
            $table->index('status', 'staff_status_index');
            $table->foreign('user_id', 'staff_user_id_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table `staff` add constraint `staff_status_check` check (`status` in ('Active', 'Deactivated'))",
        );
    }

    /**
     * Drop staff profiles.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
