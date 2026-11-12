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
        // Prices: a dealer sees only the one list that applies to them (CatalogController).
        'catalog' => [
            'label' => 'Prices',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales', 'warranty', 'finance', 'parts', 'dealer_admin', 'dealer_user'],
        ],
        'manage_pricing' => [
            'label' => 'Price lists',
            'roles' => ['owner', 'admin', 'finance'],
        ],
        // Dealers see their own quotes (Quote's dealer scope); sales staff read every dealer's.
        'quotes' => [
            'label' => 'Quotes',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales', 'dealer_admin', 'dealer_user'],
        ],
        // Writing quotes is the dealer's job - to their own customers, from their own prices.
        'build_quotes' => [
            'label' => 'Write quotes',
            'roles' => ['dealer_admin', 'dealer_user'],
        ],
        // Whether a sent quote was won or lost is the manufacturer's call; the dealer stops seeing it then.
        'close_quotes' => [
            'label' => 'Mark quotes won or lost',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales'],
        ],
        // A won quote becomes a sales order. Manufacturer staff only - finance reads them for invoicing.
        'sales_orders' => [
            'label' => 'Sales orders',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales', 'finance'],
        ],
        'manage_sales_orders' => [
            'label' => 'Work sales orders (production, shipping)',
            'roles' => ['owner', 'admin', 'inside_sales'],
        ],
        // Dealers see their own orders (PartsOrder's dealer scope); the parts desk sees all.
        // Dealers confirm the machines on their lot; staff see who has and what's missing (App\Support\StockChecks).
        'stock_checks' => [
            'label' => 'Stock checks',
            'roles' => ['owner', 'admin', 'sales_rep', 'inside_sales', 'dealer_admin', 'dealer_user'],
        ],
        // Starting checks, and the monthly round.
        'manage_stock_checks' => [
            'label' => 'Run stock checks',
            'roles' => ['owner', 'admin', 'inside_sales'],
        ],
        'parts_orders' => [
            'label' => 'Parts orders',
            'roles' => ['owner', 'admin', 'inside_sales', 'parts', 'dealer_admin', 'dealer_user'],
        ],
        'order_parts' => [
            'label' => 'Order parts',
            'roles' => ['dealer_admin', 'dealer_user'],
        ],
        'manage_parts_orders' => [
            'label' => 'Work the parts order queue',
            'roles' => ['owner', 'admin', 'parts'],
        ],
        // Reading the broadcasts sent to them (Broadcast's dealer scope). Not plan-gated:
        // a dealer can still read what was sent before a manufacturer moved to Starter.
        'announcements' => [
            'label' => 'Announcements',
            'roles' => ['dealer_admin', 'dealer_user'],
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
        // Pro-only (Plan::PLANS excludes), built in later phases. 'coming' marks
        // them "coming soon" wherever plans are described (pricing, billing),
        // so nobody pays for something that isn't there yet - remove it when a
        // feature ships.
        'broadcasts' => ['label' => 'Broadcasts to your dealers', 'roles' => ['owner', 'admin', 'inside_sales']],
        'usage_analytics' => ['label' => 'Usage analytics', 'roles' => ['owner', 'admin']],
        // Managing territories. Being limited by one needs no feature - see App\Support\Territories.
        'territories' => ['label' => 'Territories', 'roles' => ['owner', 'admin']],
        // Your brand's colour across the app, for staff and dealers (App\Http\Controllers\Api\Tenant\SetupController::brand).
        'custom_branding' => ['label' => 'Your own colours', 'roles' => ['owner', 'admin']],
        // acme.dealerdock... - needs DNS and hosting decided first.
        'custom_domain' => ['label' => 'Your own subdomain', 'roles' => ['owner', 'admin'], 'coming' => true],
        // "In stock near you" on their own website: which dealers have which models (PublicStockController).
        'public_inventory' => ['label' => '"In stock near you" for your website', 'roles' => ['owner', 'admin']],
        // Self-serve: API keys, webhooks and matching numbers (IntegrationController). Hands-on setup is still quoted separately.
        'erp_integration' => ['label' => 'Connect your ERP (API and webhooks)', 'roles' => ['owner', 'admin']],
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
