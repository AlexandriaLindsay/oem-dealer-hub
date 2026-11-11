<?php

namespace App\Billing;

use App\Models\Manufacturer;
use App\Support\Plan;

/**
 * Keeps manufacturers.plan in step with the Stripe subscription, which
 * Cashier keeps up to date from Stripe's webhooks:
 *
 * - a live subscription (active, on a paid-up grace period, or past due while
 *   Stripe retries) -> the plan of its price;
 * - a subscription that has ended -> expired (the account lapses);
 * - none, or one still waiting for its first payment -> left as it is.
 *
 * Run it inside Tenant::run($manufacturer) - it's background work.
 */
final class SyncPlan
{
    public static function run(Manufacturer $manufacturer): void
    {
        $subscription = $manufacturer->subscriptions()->where('type', 'default')->latest('id')->first();
        if (! $subscription) {
            return;
        }

        if ($subscription->valid()) {
            $plan = Plan::forPrice($subscription->stripe_price)[0] ?? null;
            if ($plan) {
                $manufacturer->forceFill(['plan' => $plan])->save();
            } else {
                // A price this app doesn't know (set up by hand in Stripe?) - leave the plan, but say so.
                logger()->warning('Subscription price is not one of the configured plan prices', [
                    'manufacturer_id' => $manufacturer->id,
                    'price' => $subscription->stripe_price,
                ]);
            }
        } elseif ($subscription->ended()) {
            $manufacturer->forceFill(['plan' => Plan::EXPIRED])->save();
        }
    }
}
