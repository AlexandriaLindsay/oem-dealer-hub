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
        // Staff see every machine; a dealer sees only their own (Machine's dealer scope).
        'machines' => [
            'label' => 'Machines and inventory',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales', 'warranty', 'finance', 'parts', 'dealer_admin', 'dealer_user'],
        ],
        'manage_machines' => [
            'label' => 'Add machines and move them between dealers',
            'roles' => ['owner', 'admin', 'inside_sales'],
        ],
        // Bulk adds and changes machines from a spreadsheet - the people who set the account up.
        'import_machines' => [
            'label' => 'Import machines from a spreadsheet',
            'roles' => ['owner', 'admin'],
        ],
        // Every dealer's machines at once, and the Excel export - so staff only.
        'fleet_report' => [
            'label' => 'Fleet report and export',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales', 'warranty', 'finance', 'parts'],
        ],
        // Dealers keep their own stock's status current (demo, rental, sold...).
        'machine_status' => [
            'label' => 'Change a machine\'s status',
            'roles' => ['owner', 'admin', 'inside_sales', 'dealer_admin', 'dealer_user'],
        ],
        // Staff see every registration; a dealer sees only their own (Registration's dealer scope).
        'registrations' => [
            'label' => 'Sale and rental registrations',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales', 'warranty', 'finance', 'dealer_admin', 'dealer_user'],
        ],
        'register_machines' => [
            'label' => 'Register a sale or rental',
            'roles' => ['owner', 'admin', 'inside_sales', 'dealer_admin', 'dealer_user'],
        ],
        'review_registrations' => [
            'label' => 'Approve and reject registrations',
            'roles' => ['owner', 'admin', 'inside_sales', 'warranty'],
        ],
        // Dealers see documents meant for everyone; staff also see staff-only ones (Document's audience scope).
        'documents' => [
            'label' => 'Manuals and documents',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales', 'warranty', 'finance', 'parts', 'dealer_admin', 'dealer_user'],
        ],
        'manage_documents' => [
            'label' => 'Add and change manuals and documents',
            'roles' => ['owner', 'admin', 'warranty', 'parts'],
        ],
        'dealers' => [
            'label' => 'Dealer network',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales'],
        ],
        'manage_dealers' => [
            'label' => 'Add and edit dealers',
            'roles' => ['owner', 'admin'],
        ],
        // The "Getting started" checklist and the logo.
        'setup' => [
            'label' => 'Company setup and logo',
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
