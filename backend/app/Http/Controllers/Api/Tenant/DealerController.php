<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Membership;
use App\Support\Invite;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The manufacturer's dealer network - its links, with each dealer's public details. */
class DealerController extends Controller
{
    public function index(): JsonResponse
    {
        $links = DealerLink::with('dealer')->get()->sortBy('dealer.name')->values();

        return response()->json($links->map(fn (DealerLink $link) => self::row($link)));
    }

    /** Adds a dealer, and invites its first person as the dealer's admin when an email is given. */
    public function store(Request $request): JsonResponse
    {
        $manufacturer = Tenant::current()->manufacturer();

        if (! Plan::allows($manufacturer, 'dealers', DealerLink::count())) {
            throw ValidationException::withMessages([
                'name' => 'Your plan includes up to '.Plan::limit($manufacturer, 'dealers').' dealers. Upgrade to Pro for unlimited dealers.',
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'dealer_code' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:191'],
            'contact_name' => ['required_with:contact_email', 'nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:191'],
        ]);

        $link = DB::transaction(function () use ($data) {
            $dealer = Dealer::create($data);
            $link = DealerLink::create([
                'dealer_id' => $dealer->id,
                'dealer_code' => $data['dealer_code'] ?? null,
                'status' => DealerLink::ACTIVE,
            ]);

            if (! empty($data['contact_email'])) {
                $user = Invite::user($data['contact_name'], $data['contact_email']);
                Membership::firstOrCreate(
                    ['user_id' => $user->id, 'org_type' => Membership::DEALER, 'org_id' => $dealer->id],
                    ['role' => 'dealer_admin'],
                );
            }

            return $link;
        });

        return response()->json(self::row($link->load('dealer')), 201);
    }

    private static function row(DealerLink $link): array
    {
        return [
            'id' => $link->id,
            'dealer_id' => $link->dealer_id,
            'name' => $link->dealer->name,
            'dealer_code' => $link->dealer_code,
            'status' => $link->status,
            'city' => $link->dealer->city,
            'region' => $link->dealer->region,
            'country' => $link->dealer->country,
            'phone' => $link->dealer->phone,
            'website' => $link->dealer->website,
        ];
    }
}
