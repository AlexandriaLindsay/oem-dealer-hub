<?php

namespace App\Http\Middleware;

use App\Models\Manufacturer;
use App\Tenancy\Access;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For every /api/m/{tenant}/... route: looks up the manufacturer by slug and
 * checks the signed-in user works there (as staff, or as an actively linked
 * dealer), then sets it as the current Tenant.
 *
 * An unknown slug and a manufacturer the user has no access to get the same
 * 404, so slugs can't be probed.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $manufacturer = Manufacturer::where('slug', (string) $request->route('tenant'))->first();
        $context = $manufacturer ? Access::at($request->user(), $manufacturer) : null;

        if (! $context) {
            abort(404);
        }

        Tenant::current()->set($manufacturer, $context['membership'], $context['link']);
        $request->route()->forgetParameter('tenant');

        return $next($request);
    }
}
