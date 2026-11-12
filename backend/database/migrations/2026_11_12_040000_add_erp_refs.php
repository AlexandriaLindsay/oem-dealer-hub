<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERP references: the number a manufacturer's own ERP uses for the same
 * thing, so the two systems can be matched up (docs/INTEGRATIONS.md).
 * Dealers already have one (dealer_links.dealer_code) and machines have their
 * serial number, so these are the rest. Nullable and additive.
 */
return new class extends Migration
{
    private const TABLES = ['product_models', 'product_options', 'sales_orders', 'parts_orders'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('erp_ref', 100)->nullable();
                $table->index(['manufacturer_id', 'erp_ref']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['manufacturer_id', 'erp_ref']);
                $table->dropColumn('erp_ref');
            });
        }
    }
};
