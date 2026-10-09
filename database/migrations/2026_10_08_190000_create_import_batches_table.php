<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create import batches.
     *
     * source is csv, xlsx, or google_sheet. A manual add does not create a batch.
     * target includes Staff for the later staff import. failure_report_path is a
     * path on the local demo disk (ADR-021). Counts are unsigned.
     * processed_rows cannot pass total_rows.
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('source', 32);
            $table->string('target', 32);
            $table->string('status', 32)->default('Processing');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('original_name')->nullable();
            $table->string('failure_report_path')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index('user_id', 'import_batches_user_id_index');
            $table->index('source', 'import_batches_source_index');
            $table->index('target', 'import_batches_target_index');
            $table->index('status', 'import_batches_status_index');
            $table->index('created_at', 'import_batches_created_at_index');
            $table->foreign('user_id', 'import_batches_user_id_foreign')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table `import_batches` add constraint `import_batches_source_check` check (`source` in ('csv', 'xlsx', 'google_sheet'))",
        );
        DB::statement(
            "alter table `import_batches` add constraint `import_batches_target_check` check (`target` in ('Applicant', 'Student', 'Staff'))",
        );
        DB::statement(
            "alter table `import_batches` add constraint `import_batches_status_check` check (`status` in ('Processing', 'Completed', 'Failed'))",
        );
        DB::statement(
            'alter table `import_batches` add constraint `import_batches_processed_rows_check` check (`processed_rows` <= `total_rows`)',
        );
    }

    /**
     * Drop import batches.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
