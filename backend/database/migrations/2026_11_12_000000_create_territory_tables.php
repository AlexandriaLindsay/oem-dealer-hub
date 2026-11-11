<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: territories (Pro). Tenant-owned.
 *
 * - territories: a named group of dealers ("Prairies", "Upper Midwest").
 * - territory_reps: the sales staff covering a territory (their staff
 *   membership, so leaving the company removes them). A rep in at least one
 *   territory sees only those territories' dealers (App\Support\Territories).
 * - dealer_links.territory_id: the territory a dealer is in (null: none).
 *   Additive column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();
            $table->unique(['manufacturer_id', 'name']);
        });

        Schema::create('territory_reps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->unique(['territory_id', 'membership_id']);
            $table->index(['manufacturer_id', 'membership_id']);
        });

        Schema::table('dealer_links', function (Blueprint $table) {
            $table->foreignId('territory_id')->nullable()->after('price_list_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dealer_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('territory_id');
        });
        Schema::dropIfExists('territory_reps');
        Schema::dropIfExists('territories');
    }
};
