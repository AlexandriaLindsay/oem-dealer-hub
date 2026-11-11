<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: who agreed to which version of the terms and privacy policy, and
 * when (config dealerdock.legal.version). Additive. Everyone agrees - the
 * owner at sign-up, invited staff and dealers at their first sign-in - and
 * again whenever the version changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('terms_version', 20)->nullable()->after('tours_seen');
            $table->timestamp('terms_accepted_at')->nullable()->after('terms_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_version', 'terms_accepted_at']);
        });
    }
};
