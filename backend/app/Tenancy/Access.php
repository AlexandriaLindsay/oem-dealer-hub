<?php

namespace App\Tenancy;

use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\User;
use App\Support\Features;
use Illuminate\Support\Collection;

/**
 * Where a user can work: every manufacturer they're staff at, plus every
 * manufacturer their dealer has an active link with. One login across brands
 * is the network (docs/PLAN.md section 1).
 */
final class Access
{
    /**
     * Keyed by manufacturer id.
     *
     * @return Collection<int, array{manufacturer: Manufacturer, membership: Membership, link: ?DealerLink}>
     */
    public static function for(User $user): Collection
    {
        $memberships = $user->memberships()->get();
        $contexts = collect();

        // Staff first, so someone who is both staff and a dealer at the same
        // manufacturer works there as staff.
        $staff = $memberships->where('org_type', Membership::MANUFACTURER);
        foreach (Manufacturer::whereIn('id', $staff->pluck('org_id'))->get() as $manufacturer) {
            $contexts[$manufacturer->id] = [
                'manufacturer' => $manufacturer,
                'membership' => $staff->firstWhere('org_id', $manufacturer->id),
                'link' => null,
            ];
        }

        $dealer = $memberships->where('org_type', Membership::DEALER);
        if ($dealer->isNotEmpty()) {
            // Across manufacturers on purpose: this is the one place that looks
            // at every manufacturer's links - only the user's own dealers' links.
            $links = DealerLink::withoutTenant()
                ->whereIn('dealer_id', $dealer->pluck('org_id'))
                ->where('status', DealerLink::ACTIVE)
                ->with('manufacturer')
                ->get();

            foreach ($links as $link) {
                $contexts[$link->manufacturer_id] ??= [
                    'manufacturer' => $link->manufacturer,
                    'membership' => $dealer->firstWhere('org_id', $link->dealer_id),
                    'link' => $link,
                ];
            }
        }

        return $contexts->sortBy(fn ($c) => $c['manufacturer']->name);
    }

    /** The user's context at $manufacturer, or null when they have none. */
    public static function at(User $user, Manufacturer $manufacturer): ?array
    {
        return self::for($user)->get($manufacturer->id);
    }

    /** What /api/me and the brand switcher show for one context. */
    public static function describe(array $context): array
    {
        $m = $context['manufacturer'];

        return [
            'id' => $m->id,
            'name' => $m->name,
            'slug' => $m->slug,
            'plan' => $m->plan,
            'trial_ends_at' => $m->trial_ends_at?->toIso8601String(),
            'logo_url' => $m->logoUrl(),
            'acting_as' => $context['link'] ? 'dealer' : 'manufacturer',
            'dealer' => $context['link'] ? ['id' => $context['link']->dealer_id, 'name' => $context['membership']->dealer?->name] : null,
            'role' => $context['membership']->role,
            'features' => Features::for($m, $context['membership']),
        ];
    }
}
