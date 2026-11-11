<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1: the document library - manuals, parts books, service bulletins
 * (docs/PLAN.md section 1). Tenant-owned.
 *
 * - documents: one file each, with who can see it (everyone, or staff only).
 * - document_models: which models a document covers, each optionally only a
 *   serial range ("S/N 24001 and up"). A document with no rows here covers
 *   every machine (a warranty policy, say).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('kind', 20); // manual | parts_book | bulletin | brochure | other
            $table->text('description')->nullable();
            $table->string('audience', 10)->default('everyone'); // everyone | staff
            $table->string('original_name');
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('scan_status', 20);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['manufacturer_id', 'kind']);
        });

        Schema::create('document_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_model_id')->constrained()->cascadeOnDelete();
            // Inclusive; either end can be open. Compared "naturally" (24100 after 9999).
            $table->string('serial_from', 80)->nullable();
            $table->string('serial_to', 80)->nullable();
            $table->unique(['document_id', 'product_model_id']);
            $table->index(['manufacturer_id', 'product_model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_models');
        Schema::dropIfExists('documents');
    }
};
