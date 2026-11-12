<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person. Global - their memberships say which manufacturers and dealers they
 * belong to (App\Tenancy\Access works out where they can sign in to).
 *
 * is_platform_staff marks OEM Dealer Hub's own people, who can open the staff
 * console (/admin). It isn't fillable and is set only by
 * `php artisan platform:staff`, never from the API or the console itself.
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'is_platform_staff' => 'boolean',
            'tours_seen' => 'array',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function trustedBrowsers(): HasMany
    {
        return $this->hasMany(TrustedBrowser::class);
    }

    /** Two-factor sign-in is on - by authenticator app or by email. */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * How they get their code: 'totp' (an authenticator app) or 'email'.
     * Null for anyone set up before email codes existed - they all use an app.
     */
    public function twoFactorMethod(): ?string
    {
        if (! $this->hasTwoFactor()) {
            return null;
        }

        return $this->two_factor_method ?? 'totp';
    }

    /** Two-factor by authenticator app specifically - the stronger kind, which the staff console needs. */
    public function hasAuthenticatorApp(): bool
    {
        return $this->twoFactorMethod() === 'totp' && $this->two_factor_secret !== null;
    }

    /**
     * Turns two-factor off and forgets every remembered browser - after which
     * the next sign-in needs a new setup. For "lost my phone" resets.
     */
    public function clearTwoFactor(): void
    {
        $this->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_method' => null])->save();
        $this->trustedBrowsers()->delete();
    }

    /**
     * The staff console: platform staff only, and only with an authenticator
     * app - not email codes, because the console reaches every customer's
     * account. The one exception is local development with
     * DEALERDOCK_REQUIRE_TWO_FACTOR=false (consoleSkipsTwoFactor).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isActiveStaff();
    }

    /**
     * OEM Dealer Hub staff who can use their staff powers right now: the
     * console, and a read-only view of every manufacturer in the app
     * (App\Tenancy\Access). Same two-factor rule for both.
     */
    public function isActiveStaff(): bool
    {
        return $this->is_platform_staff && (self::consoleSkipsTwoFactor() || $this->hasAuthenticatorApp());
    }

    /**
     * Two-factor off for the console: only on a developer's machine (APP_ENV
     * local) with DEALERDOCK_REQUIRE_TWO_FACTOR=false. Never in production,
     * whatever the setting says.
     */
    public static function consoleSkipsTwoFactor(): bool
    {
        return app()->environment('local') && ! config('dealerdock.require_two_factor');
    }
}
