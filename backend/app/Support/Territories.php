<?php

namespace App\Support;

use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\Territory;
use Illuminate\Support\Facades\DB;

/**
 * Who territories narrow down, and to what (Pro).
 *
 * A sales rep or inside sales person who covers at least one territory sees
 * only the dealers in their territories - and those dealers' machines,
 * registrations, quotes and parts orders - plus the manufacturer's own stock.
 * Everyone else sees every dealer: owners and admins always, other roles
 * always, and reps who aren't in any territory (so nothing changes until
 * someone is put in one). On a plan without territories nobody is narrowed;
 * the territories are kept for when the plan includes them again.
 *
 * Applied by global scopes (DealerLink, VisibleToOwnDealer) through
 * Tenant::territoryLinkIds(), so a forgotten filter can't show a rep the rest
 * of the network.
 */
final class Territories
{
    /**
     * The dealer link ids $membership is limited to at $manufacturer, or null
     * when they see every dealer. Plain queries (no models), because this runs
     * inside the very scopes it feeds.
     *
     * @return list<int>|null
     */
    public static function linkIdsFor(Manufacturer $manufacturer, ?Membership $membership): ?array
    {
        $territoryIds = self::territoryIdsFor($manufacturer, $membership);
        if ($territoryIds === null) {
            return null;
        }

        return DB::table('dealer_links')
            ->where('manufacturer_id', $manufacturer->id)
            ->whereIn('territory_id', $territoryIds)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The territories $membership covers, when they narrow what they see; null otherwise.
     *
     * @return list<int>|null
     */
    public static function territoryIdsFor(Manufacturer $manufacturer, ?Membership $membership): ?array
    {
        if ($membership === null
            || $membership->org_type !== Membership::MANUFACTURER
            || (int) $membership->org_id !== $manufacturer->id
            || ! in_array($membership->role, Territory::REP_ROLES, true)
            || ! Plan::includes($manufacturer, 'territories')) {
            return null;
        }

        $ids = DB::table('territory_reps')
            ->where('manufacturer_id', $manufacturer->id)
            ->where('membership_id', $membership->id)
            ->pluck('territory_id')->map(fn ($id) => (int) $id)->all();

        return $ids === [] ? null : $ids;
    }

    /**
     * The names of the territories $membership is limited to (for "you're
     * seeing dealers in: ..."), or null when they see every dealer.
     *
     * @return list<string>|null
     */
    public static function namesFor(Manufacturer $manufacturer, ?Membership $membership): ?array
    {
        $ids = self::territoryIdsFor($manufacturer, $membership);

        return $ids === null ? null : DB::table('territories')->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
    }
}
