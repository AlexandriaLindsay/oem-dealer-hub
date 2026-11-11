<?php

namespace App\Billing;

use App\Models\Manufacturer;
use DateTimeInterface;

/** BillingGateway through Laravel Cashier. */
final class StripeGateway implements BillingGateway
{
    public function checkoutUrl(Manufacturer $manufacturer, string $priceId, ?DateTimeInterface $trialUntil, string $successUrl, string $cancelUrl): string
    {
        $builder = $manufacturer->newSubscription('default', $priceId);
        if ($trialUntil) {
            $builder->trialUntil($trialUntil);
        }

        return $builder->checkout([
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ])->asStripeCheckoutSession()->url;
    }

    public function portalUrl(Manufacturer $manufacturer, string $returnUrl): string
    {
        return $manufacturer->billingPortalUrl($returnUrl);
    }

    public function swap(Manufacturer $manufacturer, string $priceId): void
    {
        $manufacturer->subscription('default')->swap($priceId);
    }
}
