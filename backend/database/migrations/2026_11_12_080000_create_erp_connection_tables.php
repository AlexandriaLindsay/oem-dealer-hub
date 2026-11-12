<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other direction of an ERP integration: OEM Dealer Hub calls the
 * manufacturer's ERP API itself, instead of waiting for the ERP to call us.
 * Both tenant-owned.
 *
 * - erp_connections: one per manufacturer. Their ERP's address, how to sign
 *   in (a header and a token, the token encrypted), and for each sync where
 *   to find things in their JSON. `state` keeps where each pull got to.
 * - erp_sync_runs: each sync and each order sent, with what it changed and
 *   what went wrong - so they can see it working and fix their mapping.
 *   Kept 30 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('base_url', 500);
            $table->string('auth_header', 60)->default('Authorization');
            $table->string('auth_prefix', 30)->nullable();
            $table->text('token')->nullable(); // encrypted
            $table->json('syncs');
            $table->json('state')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('erp_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->string('sync', 20); // orders_push | order_status | prices | machines
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('running'); // running | ok | partial | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('counts')->nullable();
            $table->string('message', 1000)->nullable();
            $table->json('problems')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['manufacturer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sync_runs');
        Schema::dropIfExists('erp_connections');
    }
};
