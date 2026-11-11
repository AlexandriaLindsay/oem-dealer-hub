<?php

namespace App\Http\Middleware;

use App\Support\Features;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:<key>` - after ResolveTenant. 403 unless the plan includes the
 * feature (code upgrade_required) and the user's role or override allows it
 * (code feature_not_allowed).
 */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = Tenant::current();

        if (! Plan::includes($tenant->manufacturer(), $feature)) {
            return response()->json([
                'message' => 'This is part of the Pro plan.',
                'code' => 'upgrade_required',
                'feature' => $feature,
            ], 403);
        }

        if (! Features::allows($tenant->manufacturer(), $tenant->membership(), $feature)) {
            return response()->json([
                'message' => 'You don\'t have access to this.',
                'code' => 'feature_not_allowed',
                'feature' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
