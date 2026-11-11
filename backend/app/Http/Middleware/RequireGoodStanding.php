<?php

namespace App\Http\Middleware;

use App\Support\Features;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `standing` - after ResolveTenant. A lapsed account (trial over with no plan
 * chosen, or a subscription that ended) is paused: 402 with code
 * account_lapsed, for staff and dealers alike, so the app can say so plainly
 * instead of failing oddly. Nothing is deleted, and Billing stays open (its
 * routes don't use this middleware) so the owner can pick a plan.
 */
class RequireGoodStanding
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::current();
        $manufacturer = $tenant->manufacturer();

        if (Plan::standing($manufacturer) === Plan::LAPSED) {
            $canPay = Features::allows($manufacturer, $tenant->membership(), 'billing');

            return response()->json([
                'message' => $canPay
                    ? "The DealerDock account for {$manufacturer->name} is paused. Choose a plan to pick up where you left off - nothing has been deleted."
                    : "The DealerDock account for {$manufacturer->name} is paused. Nothing has been deleted; it'll be back as soon as {$manufacturer->name} chooses a plan.",
                'code' => 'account_lapsed',
                'can_manage_billing' => $canPay,
            ], 402);
        }

        return $next($request);
    }
}
