<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: broadcasts - announcements from a manufacturer to its dealers
 * (docs/PLAN.md section 1; Pro only). Tenant-owned.
 *
 * - broadcasts: what was said, to whom (all dealers or chosen ones), whether
 *   it was also emailed, and if it was withdrawn.
 * - broadcast_recipients: the dealers it went to, fixed when it was sent - a
 *   dealer who joins later doesn't get old announcements.
 * - broadcast_reads: who has opened it, per person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->string('title', 191);
            $table->text('body');
            $table->string('audience', 10); // all | selected
            $table->boolean('emailed')->default(false);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->index(['manufacturer_id', 'created_at']);
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dealer_link_id')->constrained()->cascadeOnDelete();
            $table->unique(['broadcast_id', 'dealer_link_id']);
            $table->index(['manufacturer_id', 'dealer_link_id']);
        });

        Schema::create('broadcast_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dealer_link_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('read_at');
            $table->unique(['broadcast_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_reads');
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');
    }
};
