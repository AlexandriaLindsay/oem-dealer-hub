<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Easier sign-in, without giving up two-factor:
 *
 * - users.two_factor_method: how this person gets their code - 'totp' (an
 *   authenticator app) or 'email'. Null for people set up before this
 *   existed, who all use an app (User::twoFactorMethod()).
 * - trusted_browsers: "remember this browser for 30 days". A random token
 *   in a cookie on that browser; only its hash is kept here, with a short
 *   description ("Chrome on Windows") so the person can recognise and
 *   forget it. Global, like users - not tenant-owned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_method', 10)->nullable();
        });

        Schema::create('trusted_browsers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('label', 80);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_browsers');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('two_factor_method'));
    }
};
