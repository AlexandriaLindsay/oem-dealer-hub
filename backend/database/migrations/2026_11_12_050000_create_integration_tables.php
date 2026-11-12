<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connecting a manufacturer's own systems (their ERP), self-serve. All tenant-owned.
 *
 * - api_keys: keys their systems use to read from OEM Dealer Hub. Only a hash
 *   of the key is kept; the key itself is shown once, when it's made.
 * - webhooks: addresses of theirs that OEM Dealer Hub calls when something
 *   happens (a sales order is made, say). The signing secret is encrypted.
 * - webhook_deliveries: each call, with how it went - so they can see what
 *   was sent and retry one that failed. Kept 30 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // The start of the key ("odh_3f9a1c2b"), to tell keys apart; the rest is only ever hashed.
            $table->string('prefix', 20);
            $table->char('key_hash', 64)->unique();
            $table->json('abilities');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->json('events');
            $table->text('secret'); // encrypted
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('event', 60);
            $table->json('payload');
            $table->string('status', 10)->default('pending'); // pending | delivered | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['manufacturer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('api_keys');
    }
};
