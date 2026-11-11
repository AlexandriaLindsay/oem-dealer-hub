<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: price lists (docs/PLAN.md section 1). Tenant-owned.
 *
 * - price_lists: a manufacturer's prices for a group of dealers, in one
 *   currency. One is the default - dealers without a list of their own get it.
 * - price_list_items: one price per model or option on a list, in cents
 *   (whole numbers - no rounding surprises). No row: "price on request".
 * - dealer_links.price_list_id: the list a dealer buys from (null: the
 *   default). Additive column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->char('currency', 3); // USD | CAD
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['manufacturer_id', 'name']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 10); // model | option
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('price_cents');
            $table->timestamps();
            $table->unique(['price_list_id', 'item_type', 'item_id']);
        });

        Schema::table('dealer_links', function (Blueprint $table) {
            $table->foreignId('price_list_id')->nullable()->after('dealer_code')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dealer_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_id');
        });
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
