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

    /** The plan column after a paid subscription has ended (App\Billing\SyncPlan). */
    public const EXPIRED = 'expired';

    // Where an account stands - what the app lets it do.
    public const TRIALING = 'trialing';   // free trial, no card yet

    public const ACTIVE = 'active';       // paid up (or billed outside Stripe)

    public const PAST_DUE = 'past_due';   // a payment failed; still open while Stripe retries

    public const LAPSED = 'lapsed';       // trial over or subscription ended: paused, nothing deleted

    public const INTERVALS = ['monthly', 'annual'];

    /** The plan whose limits apply: a trial runs on Pro. */
    public static function effective(Manufacturer $manufacturer): string
    {
        return $manufacturer->plan === self::STARTER ? self::STARTER : self::PRO;
    }

    /**
     * Where the account stands.
     *
     * The plan column is kept in step with Stripe by SyncPlan. A starter or
     * pro plan with no subscription at all is an account billed outside
     * Stripe (by invoice) or given free - it's active.
     */
    public static function standing(Manufacturer $manufacturer): string
    {
        return match ($manufacturer->plan) {
            self::TRIAL => $manufacturer->trial_ends_at?->isFuture() ? self::TRIALING : self::LAPSED,
            self::EXPIRED => self::LAPSED,
            default => $manufacturer->subscription('default')?->pastDue() ? self::PAST_DUE : self::ACTIVE,
        };
    }

    /** The Stripe price for a plan and billing interval, or null when billing isn't set up. */
    public static function priceId(string $plan, string $interval): ?string
    {
        return config("services.stripe.prices.$plan.$interval") ?: null;
    }

    /** @return array{0: string, 1: string}|null [plan, interval] for a Stripe price id */
    public static function forPrice(?string $priceId): ?array
    {
        foreach (array_keys(self::PLANS) as $plan) {
            foreach (self::INTERVALS as $interval) {
                if ($priceId !== null && self::priceId($plan, $interval) === $priceId) {
                    return [$plan, $interval];
                }
            }
        }

        return null;
    }

    /**
     * Why $manufacturer can't move to $plan right now - one plain sentence per
     * limit it's over. Empty when it can. A downgrade never silently cuts off
     * dealers or staff: they have to be under the limit first.
     *
     * @param  array{dealers: int, staff: int, storage_gb: float, price_lists?: int}  $usage
     * @return list<string>
     */
    public static function blockers(string $plan, array $usage): array
    {
        $limits = self::PLANS[$plan]['limits'];
        $label = self::PLANS[$plan]['label'];
        $reasons = [];

        if ($limits['dealers'] !== null && $usage['dealers'] > $limits['dealers']) {
            $reasons[] = "You have {$usage['dealers']} dealers; $label includes up to {$limits['dealers']}. Suspend or remove dealers you no longer work with first.";
        }
        if ($limits['staff'] !== null && $usage['staff'] > $limits['staff']) {
            $reasons[] = "You have {$usage['staff']} staff users; $label includes up to {$limits['staff']}.";
        }
        if ($limits['storage_gb'] !== null && $usage['storage_gb'] > $limits['storage_gb']) {
            $reasons[] = "You're using {$usage['storage_gb']} GB of file storage; $label includes {$limits['storage_gb']} GB.";
        }
        if ($limits['price_lists'] !== null && ($usage['price_lists'] ?? 0) > $limits['price_lists']) {
            $reasons[] = "You have {$usage['price_lists']} price lists; $label includes {$limits['price_lists']}. Delete the ones you don't need first - their dealers go back to the default list.";
        }

        return $reasons;
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
