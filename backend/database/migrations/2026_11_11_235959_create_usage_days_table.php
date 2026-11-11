<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: usage analytics (Pro). Tenant-owned.
 *
 * Deliberately coarse: per person, per day, per area of the app, how many
 * times - not a log of every click. No IP addresses, no pages. Recorded only
 * for accounts whose plan includes the report, kept 13 months (usage:prune).
 * Described in the privacy policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The dealer they were acting for; null for the manufacturer's own staff.
            $table->foreignId('dealer_link_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('area', 30);
            $table->unsignedInteger('count')->default(0);
            $table->unique(['manufacturer_id', 'user_id', 'day', 'area']);
            $table->index(['manufacturer_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_days');
    }
};
