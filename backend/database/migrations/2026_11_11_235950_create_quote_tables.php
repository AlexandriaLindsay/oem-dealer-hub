<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: quotes (docs/PLAN.md section 1). Tenant-owned, and each belongs to
 * one dealer (VisibleToOwnDealer).
 *
 * - quotes: a dealer's quote to a customer. Numbered per dealer (Q-1001...),
 *   so a number never reveals how many quotes other dealers write.
 * - quote_lines: what's quoted. Model and option lines copy the dealer's list
 *   price when added (list_price_cents) - a later price change never alters
 *   a quote - and unit_price_cents is what's actually quoted.
 * All money in cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dealer_link_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 10)->default('draft'); // draft | sent | won | lost
            $table->char('currency', 3);
            $table->json('customer');
            $table->text('notes')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['dealer_link_id', 'number']);
            $table->index(['manufacturer_id', 'status']);
        });

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 10); // model | option | custom
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('description');
            $table->unsignedInteger('quantity');
            // The list price when it was added (null: custom line, or price on request).
            $table->unsignedBigInteger('list_price_cents')->nullable();
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedBigInteger('line_total_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
    }
};
