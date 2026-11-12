<?php

namespace App\Providers\Filament;

use App\Filament\Support\InitialsAvatar;
use App\Http\Middleware\AuthenticatePlatformStaff;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The staff console for OEM Dealer Hub's own people, at /admin: every
 * manufacturer, person and dealer, plus the few account changes support needs
 * (App\Platform\AccountTools).
 *
 * There's no sign-in page here on purpose. Staff sign in through the app, and
 * AuthenticatePlatformStaff lets in only platform staff with two-factor.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // Staff work in Eastern time; the database stays in UTC.
        FilamentTimezone::set('America/New_York');

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('OEM Dealer Hub staff')
            // Nothing leaves our servers: no avatar service (InitialsAvatar), and
            // Filament's fonts are served from public/fonts, not a font CDN.
            ->defaultAvatarProvider(InitialsAvatar::class)
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Persistent: Livewire runs it again on every button press and
            // table update, not only when the page first loads.
            ->authMiddleware([
                AuthenticatePlatformStaff::class,
            ], isPersistent: true);
    }
}
