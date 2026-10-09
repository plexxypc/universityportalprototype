<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create documents.
     *
     * owner_type and owner_id point at an applicant or a student. One column
     * cannot have a foreign key to both tables, so there is no foreign key.
     * A document cannot outlive its owner. Deleting an applicant or student
     * must be refused, or the documents removed first, in a service later.
     * kind is free text. path is on the local demo disk (ADR-021). File type
     * and the 5 MB limit are enforced in a service later.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('kind', 100);
            $table->string('path');
            $table->string('mime', 127);
            $table->unsignedInteger('size');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['owner_type', 'owner_id'], 'documents_owner_type_owner_id_index');
            $table->index('kind', 'documents_kind_index');
        });

        DB::statement(
            "alter table `documents` add constraint `documents_owner_type_check` check (`owner_type` in ('Applicant', 'Student'))",
        );
    }

    /**
     * Drop documents.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
