<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1: onboarding (docs/PLAN.md section 6). Additive only.
 *
 * - users.tours_seen: which guided tours each person has finished or skipped,
 *   {"manufacturer-v1": "2026-11-12T...", ...}. Versioned keys, so a big new
 *   feature can get its own "What's new" tour.
 * - manufacturers.logo_*: the logo on the documents disk (never the web root),
 *   and when it last changed - the app's image URL carries that, so a new
 *   logo shows up straight away despite caching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('tours_seen')->nullable()->after('is_platform_staff');
        });

        Schema::table('manufacturers', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('trial_ends_at');
            $table->string('logo_mime', 50)->nullable()->after('logo_path');
            $table->timestamp('logo_updated_at')->nullable()->after('logo_mime');
        });
    }

    public function down(): void
    {
        Schema::table('manufacturers', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'logo_mime', 'logo_updated_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tours_seen');
        });
    }
};
