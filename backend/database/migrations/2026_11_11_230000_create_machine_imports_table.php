<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1: machine imports (docs/PLAN.md section 5, "CSV import with an audit
 * report"). One row per import that was applied: who ran it, which file, what
 * happened to every row. Tenant-owned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name');
            $table->char('sha256', 64);
            $table->string('mode', 12); // add | add_update
            $table->unsignedInteger('rows_total');
            $table->unsignedInteger('created_count');
            $table->unsignedInteger('updated_count');
            $table->unsignedInteger('unchanged_count');
            $table->unsignedInteger('skipped_count');
            $table->unsignedInteger('failed_count');
            // Every row's outcome - the audit report is built from this.
            $table->json('results');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_imports');
    }
};
