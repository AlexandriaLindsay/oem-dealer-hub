<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock checks: once a month (or when the manufacturer asks), each dealer
 * confirms which of their unsold machines are really on their lot - the way
 * a floor plan lender audits a dealer, but one click. Tenant-owned; each
 * check belongs to one dealer (VisibleToOwnDealer).
 *
 * - stock_checks: one per dealer per round. token_hash is the one-click link
 *   in the email (only a hash is kept); it stops working when the check closes.
 * - stock_check_items: the machines asked about, and the answer for each.
 * - machines.confirmed_at: the last time a dealer said "it's here".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dealer_link_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('open'); // open | done | expired
            $table->char('token_hash', 64)->unique();
            $table->date('due_on');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['manufacturer_id', 'status']);
        });

        Schema::create('stock_check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('answer', 10)->nullable(); // here | not_here; null: not answered
            $table->string('note', 500)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->unique(['stock_check_id', 'machine_id']);
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn('confirmed_at'));
        Schema::dropIfExists('stock_check_items');
        Schema::dropIfExists('stock_checks');
    }
};
