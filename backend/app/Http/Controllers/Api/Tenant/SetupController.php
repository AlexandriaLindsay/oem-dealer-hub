<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Support\Onboarding;
use App\Support\Uploads;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Setting up a manufacturer: the "Getting started" checklist, and the logo.
 * The checklist and logo changes are for owners and admins (feature: setup);
 * the logo itself is shown to everyone who works with the brand, dealers too.
 */
class SetupController extends Controller
{
    public function onboarding(): JsonResponse
    {
        $manufacturer = Tenant::current()->manufacturer();
        $steps = Onboarding::steps($manufacturer);

        return response()->json([
            'steps' => $steps,
            'complete' => collect($steps)->every('done'),
            'dismissed' => Onboarding::dismissed($manufacturer),
        ]);
    }

    /** Hide the checklist (it can be shown again from Settings). */
    public function dismiss(Request $request): JsonResponse
    {
        $hide = $request->validate(['dismissed' => ['required', 'boolean']])['dismissed'];
        Onboarding::setDismissed(Tenant::current()->manufacturer(), $hide);

        return $this->onboarding();
    }

    public function logo(): StreamedResponse
    {
        $manufacturer = Tenant::current()->manufacturer();
        abort_unless($manufacturer->logo_path, 404);
        Uploads::abortIfMissing($manufacturer->logo_path);

        return Uploads::disk()->response($manufacturer->logo_path, null, [
            'Content-Type' => $manufacturer->logo_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            // The URL changes with every new logo (?v=), so it can be cached a while.
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }

    /**
     * A new logo: PNG or JPG only - SVG can carry scripts - small, and scanned
     * like every upload. The old file goes once the new one is saved.
     */
    public function storeLogo(Request $request, Uploads $uploads): JsonResponse
    {
        $file = $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:min_width=32,min_height=32,max_width=4000,max_height=4000'],
        ], [
            'logo.mimes' => 'Upload a PNG or JPG. (SVG isn\'t accepted - it can carry scripts.)',
            'logo.max' => 'Keep the logo under 1 MB.',
            'logo.dimensions' => 'Use an image between 32 and 4,000 pixels on each side.',
        ])['logo'];

        $manufacturer = Tenant::current()->manufacturer();
        $scan = $uploads->check(['logo' => $file])['logo'];
        $meta = $uploads->put($file, 'branding', $scan);
        $old = $manufacturer->logo_path;

        try {
            $manufacturer->forceFill([
                'logo_path' => $meta['path'],
                'logo_mime' => $meta['mime'],
                'logo_updated_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            Uploads::forget([$meta['path']]);
            throw $e;
        }

        if ($old) {
            Uploads::forget([$old]);
        }

        return response()->json(['logo_url' => $manufacturer->logoUrl()], 201);
    }

    public function destroyLogo(): JsonResponse
    {
        $manufacturer = Tenant::current()->manufacturer();
        $old = $manufacturer->logo_path;
        $manufacturer->forceFill(['logo_path' => null, 'logo_mime' => null, 'logo_updated_at' => now()])->save();
        if ($old) {
            Uploads::forget([$old]);
        }

        return response()->json(['logo_url' => null]);
    }
}
