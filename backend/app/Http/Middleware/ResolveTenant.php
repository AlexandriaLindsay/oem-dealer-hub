<?php

namespace App\Http\Middleware;

use App\Models\Manufacturer;
use App\Models\StaffAction;
use App\Platform\StaffWriteAccess;
use App\Tenancy\Access;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * For every /api/m/{tenant}/... route: looks up the manufacturer by slug and
 * checks the signed-in user works there (as staff, or as an actively linked
 * dealer), then sets it as the current Tenant.
 *
 * An unknown slug and a manufacturer the user has no access to get the same
 * 404, so slugs can't be probed.
 *
 * OEM Dealer Hub staff can open any manufacturer (Access), read-only: any
 * change is refused, except on the Integrations page after giving a reason
 * (StaffWriteAccess), so we can set a customer's integrations up for them.
 * Opening a manufacturer goes into the staff activity log (once a working
 * day per manufacturer), and so does every change staff make.
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

        $staffWrite = false;
        if (Access::isStaffView($context)) {
            if (! $request->isMethodSafe()) {
                // The one exception: the Integrations page, for setting a customer up (StaffWriteAccess).
                if (! StaffWriteAccess::covers($request)) {
                    return response()->json([
                        'message' => 'You\'re viewing '.$manufacturer->name.' as OEM Dealer Hub staff, read-only. Nothing was changed.',
                        'code' => 'staff_read_only',
                    ], 403);
                }
                if (! str_contains($request->path(), '/staff-access/') && ! StaffWriteAccess::current($request->user(), $manufacturer)) {
                    return response()->json([
                        'message' => 'Say why you\'re changing '.$manufacturer->name.'\'s integrations first. Nothing was changed.',
                        'code' => 'staff_reason_needed',
                    ], 403);
                }
                $staffWrite = true;
            }
            self::logFirstLook($request, $manufacturer);
        }

        Tenant::current()->set($manufacturer, $context['membership'], $context['link']);
        $request->route()->forgetParameter('tenant');

        $response = $next($request);

        // Only a change that went through goes into the log and onto the customer's page.
        if ($staffWrite && $response->isSuccessful()) {
            StaffWriteAccess::record($request, $request->user(), $manufacturer);
        }

        return $response;
    }

    /**
     * Once per staff member and manufacturer every 8 hours (about a working
     * day), so the log shows who looked at which customer, and when, without
     * a line per click.
     */
    private static function logFirstLook(Request $request, Manufacturer $manufacturer): void
    {
        // add() only writes when the key is missing, so two requests at once log once.
        if (! Cache::add("staff-viewed:{$request->user()->id}:{$manufacturer->id}", true, now()->addHours(8))) {
            return;
        }

        StaffAction::record($request->user(), 'opened_in_app', $manufacturer, $manufacturer->name, 'Opened in the app, read-only.');
    }
}
