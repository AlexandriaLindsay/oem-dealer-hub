<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1: products and machines (docs/PLAN.md section 3). All tenant-owned.
 *
 * - product_models: a model within a product line. Called product_models, not
 *   models, so the class (ProductModel) can't be confused with Eloquent's Model.
 * - product_options: options a model can be ordered with.
 * - machines: one physical machine, by serial number. dealer_link_id is the
 *   dealer it's with right now; null means it's still at the manufacturer.
 * - machine_events: the machine's history - every status change and transfer
 *   is a new row, never an overwritten column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_line_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 120)->nullable();
            $table->json('specs')->nullable();
            $table->timestamps();
            $table->unique(['manufacturer_id', 'name']);
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_model_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['product_model_id', 'name']);
        });

        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            // Restrict, not cascade: a model with machines can't vanish and take their history with it.
            $table->foreignId('product_model_id')->constrained()->restrictOnDelete();
            $table->string('serial_no', 80);
            $table->string('production_no', 80)->nullable();
            $table->foreignId('dealer_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('inventory');
            $table->date('built_at')->nullable();
            $table->date('shipped_at')->nullable();
            $table->timestamps();
            $table->unique(['manufacturer_id', 'serial_no']);
            $table->index(['manufacturer_id', 'status']);
            $table->index(['manufacturer_id', 'dealer_link_id']);
        });

        Schema::create('machine_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // created | status | transfer
            // For status: the old and new status. For transfer: the old and new dealer_link_id.
            $table->string('from', 80)->nullable();
            $table->string('to', 80)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['machine_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_events');
        Schema::dropIfExists('machines');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('product_models');
    }
};
