<?php

namespace App\Tenancy;

use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\User;
use App\Support\Features;
use App\Support\Plan;
use App\Support\Territories;
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

        // OEM Dealer Hub staff can open every other manufacturer as well, to see
        // what the customer sees. Read-only: ResolveTenant refuses changes in
        // these, and logs each one opened (StaffAction).
        if ($user->isActiveStaff()) {
            foreach (Manufacturer::whereNotIn('id', $contexts->keys())->get() as $manufacturer) {
                $contexts[$manufacturer->id] = [
                    'manufacturer' => $manufacturer,
                    'membership' => self::staffView($user, $manufacturer),
                    'link' => null,
                    'staff_view' => true,
                ];
            }
        }

        return $contexts->sortBy(fn ($c) => $c['manufacturer']->name);
    }

    /**
     * A stand-in membership for a staff view, never saved: the owner's view, so
     * every page the manufacturer has shows. It has no id, so nothing can hang
     * off it (territories, overrides).
     */
    private static function staffView(User $user, Manufacturer $manufacturer): Membership
    {
        $membership = new Membership([
            'user_id' => $user->id,
            'org_type' => Membership::MANUFACTURER,
            'org_id' => $manufacturer->id,
            'role' => 'owner',
        ]);

        return $membership->setRelation('user', $user);
    }

    /** Whether $context is OEM Dealer Hub staff looking in, read-only. */
    public static function isStaffView(array $context): bool
    {
        return $context['staff_view'] ?? false;
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
            // Their own colour (Pro), shown to staff and dealers alike; null: OEM Dealer Hub's blue.
            // Kept on a downgrade, just not shown, so it comes back with Pro.
            'brand_color' => Plan::includes($m, 'custom_branding') ? $m->brand_color : null,
            // trialing | active | past_due | lapsed (Plan::standing) - the app pauses a lapsed account.
            'standing' => Plan::standing($m),
            'acting_as' => $context['link'] ? 'dealer' : 'manufacturer',
            'dealer' => $context['link'] ? ['id' => $context['link']->dealer_id, 'name' => $context['membership']->dealer?->name] : null,
            'role' => $context['membership']->role,
            'features' => Features::for($m, $context['membership']),
            // The territories this person is limited to (Territories), or null: every dealer.
            'territories' => $context['link'] ? null : Territories::namesFor($m, $context['membership']),
            // OEM Dealer Hub staff looking in, read-only (the app shows a banner).
            'staff_view' => self::isStaffView($context),
        ];
    }
}
