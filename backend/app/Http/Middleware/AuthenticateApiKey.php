<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api-key:<ability>` - for the integration API (/api/v1). The key in
 * "Authorization: Bearer odh_..." decides the manufacturer: the request then
 * works inside it (Tenant, with no person or dealer, so it sees every dealer
 * - like background work). A key can't name another manufacturer, because
 * there's no manufacturer in the address to name.
 *
 * Refused: a missing, unknown or revoked key (401), a key without the
 * ability (403), a plan without ERP integration (403), and a paused account (402).
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $plain = (string) $request->bearerToken();
        $key = $plain === '' ? null : ApiKey::withoutTenant()->with('manufacturer')->where('key_hash', ApiKey::hash($plain))->first();

        if (! $key || $key->isRevoked() || ! $key->manufacturer) {
            return response()->json(['message' => 'Unknown or revoked API key.', 'code' => 'invalid_key'], 401);
        }
        $manufacturer = $key->manufacturer;

        if (! Plan::includes($manufacturer, 'erp_integration')) {
            return response()->json(['message' => 'The API is part of the Pro plan.', 'code' => 'upgrade_required'], 403);
        }
        if (Plan::standing($manufacturer) === Plan::LAPSED) {
            return response()->json(['message' => "The OEM Dealer Hub account for {$manufacturer->name} is paused.", 'code' => 'account_lapsed'], 402);
        }
        if (! $key->allows($ability)) {
            return response()->json(['message' => "This key isn't allowed to do that. It needs \"{$ability}\".", 'code' => 'missing_ability'], 403);
        }

        Tenant::current()->set($manufacturer);
        // Once a minute is plenty to show "last used" - not a write on every call.
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
