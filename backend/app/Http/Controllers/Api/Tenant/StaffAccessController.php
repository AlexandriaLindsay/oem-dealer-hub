<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Platform\StaffWriteAccess;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OEM Dealer Hub staff, looking in on a customer, open their Integrations
 * page for changes by saying why (StaffWriteAccess). Only for staff looking
 * in: the customer's own people already can, and nobody else can at all.
 */
class StaffAccessController extends Controller
{
    public function integrations(Request $request): JsonResponse
    {
        $user = $request->user();
        // A staff view's membership is a stand-in that was never saved (Access::staffView).
        if (! $user->isActiveStaff() || Tenant::current()->membership()?->exists) {
            abort(404);
        }

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], [
            'reason.required' => 'Say why, for the activity log.',
            'reason.min' => 'Say a little more, for the activity log.',
        ]);
        $until = StaffWriteAccess::grant($user, Tenant::current()->manufacturer(), trim($data['reason']));

        return response()->json(['until' => $until->toIso8601String()]);
    }
}
