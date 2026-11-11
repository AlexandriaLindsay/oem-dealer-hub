<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Support\Features;
use App\Support\Invite;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The manufacturer's own staff and their access. */
class StaffController extends Controller
{
    public function index(): JsonResponse
    {
        $manufacturer = Tenant::current()->manufacturer();
        $staff = $manufacturer->staff()->with('user')->get()->sortBy('user.name')->values();

        return response()->json($staff->map(fn (Membership $m) => [
            'id' => $m->id,
            'name' => $m->user->name,
            'email' => $m->user->email,
            'role' => $m->role,
            'two_factor' => $m->user->hasTwoFactor(),
            'features' => Features::for($manufacturer, $m),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $manufacturer = Tenant::current()->manufacturer();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191'],
            // Only one owner, set at sign-up.
            'role' => ['required', Rule::in(array_diff(Features::MANUFACTURER_ROLES, ['owner']))],
        ]);

        if (! Plan::allows($manufacturer, 'staff', $manufacturer->staff()->count())) {
            throw ValidationException::withMessages([
                'email' => 'Your plan includes up to '.Plan::limit($manufacturer, 'staff').' staff users. Upgrade to Pro for unlimited staff.',
            ]);
        }

        $user = Invite::user($data['name'], $data['email']);
        $membership = Membership::firstOrCreate(
            ['user_id' => $user->id, 'org_type' => Membership::MANUFACTURER, 'org_id' => $manufacturer->id],
            ['role' => $data['role']],
        );

        if (! $membership->wasRecentlyCreated) {
            throw ValidationException::withMessages(['email' => 'That person is already on your staff.']);
        }

        return response()->json(['id' => $membership->id], 201);
    }
}
