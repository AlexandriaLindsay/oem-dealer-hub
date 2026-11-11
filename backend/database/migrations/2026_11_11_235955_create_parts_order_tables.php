<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: parts orders (docs/PLAN.md section 1). Tenant-owned, and each
 * belongs to one dealer (VisibleToOwnDealer).
 *
 * An order is a request to the manufacturer's parts desk, by part number - no
 * prices: there's no parts price list yet, and the parts desk invoices it
 * through its own system. Numbered per manufacturer, so the parts desk has
 * one reference across every dealer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parts_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dealer_link_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 12)->default('submitted'); // submitted | in_progress | shipped | cancelled
            $table->string('po_number', 60)->nullable();
            $table->string('machine_serial', 80)->nullable();
            $table->string('ship_method', 10); // standard | rush | pickup
            $table->json('ship_to')->nullable();
            $table->text('notes')->nullable();
            // From the parts desk: a backorder, a substitution, why it was cancelled.
            $table->text('parts_note')->nullable();
            $table->string('carrier', 60)->nullable();
            $table->string('tracking_number', 80)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['manufacturer_id', 'number']);
            $table->index(['manufacturer_id', 'status']);
        });

        Schema::create('parts_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parts_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('part_number', 60);
            $table->string('description')->nullable();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parts_order_lines');
        Schema::dropIfExists('parts_orders');
    }
};
