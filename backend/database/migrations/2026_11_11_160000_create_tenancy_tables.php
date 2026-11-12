<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenancy core (docs/PLAN.md section 3):
 *
 * - manufacturers: the paying tenants.
 * - dealers: global - one dealer carries several manufacturers' brands.
 * - dealer_links: a manufacturer's own relationship with a dealer. Tenant-owned.
 * - memberships: which org a user belongs to (a manufacturer's staff, or a
 *   dealer's people), with their role and per-user feature overrides.
 * - product_lines: the first tenant-owned table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            // OEM Dealer Hub's own staff (support and billing) - not a tenant role.
            $table->boolean('is_platform_staff')->default(false)->after('two_factor_confirmed_at');
        });

        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan', 20)->default('trial');
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('dealers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website')->nullable();
            $table->timestamps();
        });

        Schema::create('dealer_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            // The manufacturer's own code for this dealer (e.g. its ERP customer code).
            $table->string('dealer_code', 50)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['manufacturer_id', 'dealer_id']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('org_type', 20); // manufacturer | dealer
            $table->unsignedBigInteger('org_id');
            $table->string('role', 30);
            // {"feature_key": true|false} - wins over the role's default.
            $table->json('feature_overrides')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'org_type', 'org_id']);
            $table->index(['org_type', 'org_id']);
        });

        Schema::create('product_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['manufacturer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_lines');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('dealer_links');
        Schema::dropIfExists('dealers');
        Schema::dropIfExists('manufacturers');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'is_platform_staff']);
        });
    }
};
