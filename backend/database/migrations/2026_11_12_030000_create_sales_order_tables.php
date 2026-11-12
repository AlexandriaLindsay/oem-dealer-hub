<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales orders: what a won quote becomes. Tenant-owned, and each belongs to
 * the dealer whose quote it was (VisibleToOwnDealer, so a rep limited to
 * territories sees only their dealers' orders).
 *
 * - sales_orders: numbered per manufacturer (SO-1001...), one per quote. The
 *   customer, currency and totals are copied from the quote when it's won, so
 *   the order stays what was agreed even if the quote's dealer later changes
 *   their prices.
 * - sales_order_lines: the quote's lines, copied. Money in cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dealer_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 15)->default('received'); // received | in_production | shipped | delivered | cancelled
            $table->char('currency', 3);
            $table->json('customer');
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->string('po_number', 60)->nullable();
            $table->date('requested_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('cancel_reason', 2000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['manufacturer_id', 'number']);
            $table->index(['manufacturer_id', 'status']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 10); // model | option | custom
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedBigInteger('line_total_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
