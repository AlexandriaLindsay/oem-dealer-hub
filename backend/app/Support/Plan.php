<?php

namespace App\Support;

use App\Models\Manufacturer;

/**
 * Every plan's price and limits, in one place (docs/PLAN.md section 2) - change
 * them here and nowhere else.
 *
 * A null limit is unlimited. The trial gets Pro's limits.
 */
final class Plan
{
    public const STARTER = 'starter';

    public const PRO = 'pro';

    public const TRIAL = 'trial';

    public const TRIAL_DAYS = 14;

    public const PLANS = [
        self::STARTER => [
            'label' => 'Starter',
            'monthly_usd' => 49,
            'annual_usd' => 490,
            'limits' => [
                'dealers' => 10,
                'staff' => 3,
                'storage_gb' => 10,
                'price_lists' => 1,
            ],
            // Pro-only features (Features::LIST keys).
            'excludes' => ['broadcasts', 'usage_analytics', 'territories', 'custom_branding', 'erp_integration'],
        ],
        self::PRO => [
            'label' => 'Pro',
            'monthly_usd' => 99,
            'annual_usd' => 990,
            'limits' => [
                'dealers' => null,
                'staff' => null,
                'storage_gb' => 100,
                'price_lists' => null,
            ],
            'excludes' => [],
        ],
    ];

    /** The plan whose limits apply: a trial runs on Pro. */
    public static function effective(Manufacturer $manufacturer): string
    {
        return $manufacturer->plan === self::STARTER ? self::STARTER : self::PRO;
    }

    public static function limit(Manufacturer $manufacturer, string $key): ?int
    {
        return self::PLANS[self::effective($manufacturer)]['limits'][$key];
    }

    public static function includes(Manufacturer $manufacturer, string $feature): bool
    {
        return ! in_array($feature, self::PLANS[self::effective($manufacturer)]['excludes'], true);
    }

    /** Whether $manufacturer, now at $current, can add $adding more of $key. */
    public static function allows(Manufacturer $manufacturer, string $key, int $current, int $adding = 1): bool
    {
        $limit = self::limit($manufacturer, $key);

        return $limit === null || $current + $adding <= $limit;
    }
}
