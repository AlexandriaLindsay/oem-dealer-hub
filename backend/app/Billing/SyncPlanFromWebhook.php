<?php

namespace App\Billing;

use App\Tenancy\Tenant;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * After Cashier has stored a subscription change from Stripe, bring the
 * manufacturer's plan in line - as that manufacturer (Tenant::run), never
 * "whoever is signed in": a webhook has no signed-in user.
 */
final class SyncPlanFromWebhook
{
    public function handle(WebhookHandled $event): void
    {
        $type = $event->payload['type'] ?? '';
        $customer = $event->payload['data']['object']['customer'] ?? null;
        if (! str_starts_with($type, 'customer.subscription.') || ! is_string($customer)) {
            return;
        }

        $manufacturer = Cashier::findBillable($customer);
        if ($manufacturer) {
            Tenant::run($manufacturer, fn () => SyncPlan::run($manufacturer));
        }
    }
}
