<?php

namespace Tests\Support;

use App\Billing\BillingGateway;
use App\Models\Manufacturer;
use DateTimeInterface;

/** Records what would have gone to Stripe. A swap updates the local subscription, as Cashier's does. */
class FakeBillingGateway implements BillingGateway
{
    public array $checkouts = [];

    public array $swaps = [];

    public function checkoutUrl(Manufacturer $manufacturer, string $priceId, ?DateTimeInterface $trialUntil, string $successUrl, string $cancelUrl): string
    {
        $this->checkouts[] = compact('priceId', 'trialUntil', 'successUrl', 'cancelUrl') + ['manufacturer' => $manufacturer->id];

        return 'https://checkout.stripe.test/session';
    }

    public function portalUrl(Manufacturer $manufacturer, string $returnUrl): string
    {
        return 'https://billing.stripe.test/portal';
    }

    public function swap(Manufacturer $manufacturer, string $priceId): void
    {
        $this->swaps[] = ['manufacturer' => $manufacturer->id, 'priceId' => $priceId];
        $subscription = $manufacturer->subscription('default');
        $subscription->forceFill(['stripe_price' => $priceId])->save();
        $subscription->items()->update(['stripe_price' => $priceId]);
    }
}
