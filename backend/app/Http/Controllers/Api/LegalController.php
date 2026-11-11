<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Features;
use App\Support\Plan;
use App\Support\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The public site's facts (plans and prices from Plan, the legal details),
 * and recording that someone agreed to the current terms.
 */
class LegalController extends Controller
{
    /** Public: everything the landing, pricing and legal pages show, from one source. */
    public function site(): JsonResponse
    {
        $legal = config('dealerdock.legal');

        return response()->json([
            'trial_days' => Plan::TRIAL_DAYS,
            'plans' => collect(Plan::PLANS)->map(fn ($p, $key) => [
                'key' => $key,
                'label' => $p['label'],
                'monthly_usd' => $p['monthly_usd'],
                'annual_usd' => $p['annual_usd'],
                'limits' => $p['limits'],
                'extras' => self::extras($p['excludes']),
            ])->values(),
            'legal' => [
                'version' => $legal['version'],
                'reviewed' => $legal['reviewed'],
                'company_name' => $legal['company_name'],
                'company_address' => $legal['company_address'],
                'contact_email' => $legal['contact_email'],
                'governing_law' => $legal['governing_law'],
            ],
            // The bot check's public key, when it's switched on.
            'turnstile_site_key' => Turnstile::enabled() ? config('services.turnstile.site_key') : null,
        ]);
    }

    /**
     * What a plan adds over Starter, in words, each marked when it isn't
     * built yet ("coming soon") - a plan is never sold on a feature that
     * isn't there.
     *
     * @return list<array{label: string, coming: bool}>
     */
    public static function extras(array $excludes): array
    {
        return collect(Plan::PLANS[Plan::STARTER]['excludes'])
            ->reject(fn ($f) => in_array($f, $excludes, true))
            ->map(fn ($f) => ['label' => Features::LIST[$f]['label'], 'coming' => (bool) (Features::LIST[$f]['coming'] ?? false)])
            ->values()->all();
    }

    /** "I agree" to the current version. Saying yes to an old version (a stale page) doesn't count. */
    public function accept(Request $request): JsonResponse
    {
        $version = config('dealerdock.legal.version');
        $data = $request->validate(['version' => ['required', 'string'], 'accept' => ['accepted']]);

        if ($data['version'] !== $version) {
            throw ValidationException::withMessages(['version' => 'The terms have changed since this page loaded. Reload to read the current version.']);
        }

        $request->user()->forceFill(['terms_version' => $version, 'terms_accepted_at' => now()])->save();

        return response()->json(['terms_version' => $version]);
    }
}
