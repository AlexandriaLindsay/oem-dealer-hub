<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: the manufacturer's own colour (Pro), as "#rrggbb". Null: OEM Dealer Hub's
 * blue. The app adjusts it for readable text (frontend/src/lib/brand.ts), so
 * any colour is accepted. Additive column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manufacturers', function (Blueprint $table) {
            $table->string('brand_color', 7)->nullable()->after('logo_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('manufacturers', function (Blueprint $table) {
            $table->dropColumn('brand_color');
        });
    }
};
