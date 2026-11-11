<?php

namespace App\Support;

use App\Models\Manufacturer;
use App\Models\Membership;

/**
 * Who can use what. A user can use a feature when:
 *   1. the manufacturer's plan includes it (Plan::includes), and
 *   2. their membership's override says so, or - with no override - their
 *      role has it by default.
 *
 * Routes check it with the `feature:<key>` middleware; the frontend gets the
 * resolved list from /api/me.
 */
final class Features
{
    /** A manufacturer's own staff. */
    public const MANUFACTURER_ROLES = ['owner', 'admin', 'sales_rep', 'inside_sales', 'warranty', 'finance', 'parts'];

    /** A dealer's people. */
    public const DEALER_ROLES = ['dealer_admin', 'dealer_user'];

    public const LIST = [
        'dashboard' => [
            'label' => 'Dashboard',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales', 'warranty', 'finance', 'parts', 'dealer_admin', 'dealer_user'],
        ],
        'products' => [
            'label' => 'Product lines and models',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales', 'warranty', 'finance', 'parts', 'dealer_admin', 'dealer_user'],
        ],
        'manage_products' => [
            'label' => 'Edit product lines and models',
            'roles' => ['owner', 'admin'],
        ],
        'dealers' => [
            'label' => 'Dealer network',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales'],
        ],
        'manage_dealers' => [
            'label' => 'Add and edit dealers',
            'roles' => ['owner', 'admin'],
        ],
        'manage_staff' => [
            'label' => 'Staff and access',
            'roles' => ['owner', 'admin'],
        ],
        'billing' => [
            'label' => 'Plan and billing',
            'roles' => ['owner'],
        ],
        // Pro-only (Plan::PLANS excludes) - built in later phases.
        'broadcasts' => ['label' => 'Broadcasts', 'roles' => ['owner', 'admin', 'inside_sales']],
        'usage_analytics' => ['label' => 'Usage analytics', 'roles' => ['owner', 'admin']],
        'territories' => ['label' => 'Territories', 'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales']],
        'custom_branding' => ['label' => 'Own subdomain and branding', 'roles' => ['owner', 'admin']],
        'erp_integration' => ['label' => 'ERP integration', 'roles' => ['owner', 'admin']],
    ];

    public static function allows(Manufacturer $manufacturer, Membership $membership, string $feature): bool
    {
        if (! isset(self::LIST[$feature]) || ! Plan::includes($manufacturer, $feature)) {
            return false;
        }

        $override = $membership->feature_overrides[$feature] ?? null;
        if ($override !== null) {
            return (bool) $override;
        }

        return in_array($membership->role, self::LIST[$feature]['roles'], true);
    }

    /** @return list<string> every feature $membership can use at $manufacturer */
    public static function for(Manufacturer $manufacturer, Membership $membership): array
    {
        return array_values(array_filter(
            array_keys(self::LIST),
            fn (string $feature) => self::allows($manufacturer, $membership, $feature),
        ));
    }
}
