<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: the product lines each dealer carries. Tenant-owned.
 *
 * A dealer with no rows here carries every line, including lines added
 * later, so existing dealers keep seeing everything. Rows limit what the
 * dealer can price and quote (CatalogController, QuoteController). Machines,
 * manuals and registrations aren't limited: a dealer can still take another
 * line's machine in as used stock and service it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealer_product_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dealer_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_line_id')->constrained()->cascadeOnDelete();
            $table->unique(['dealer_link_id', 'product_line_id']);
            $table->index('manufacturer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealer_product_lines');
    }
};
