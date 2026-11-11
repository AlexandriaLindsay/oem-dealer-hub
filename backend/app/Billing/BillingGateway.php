<?php

namespace App\Billing;

use App\Models\Manufacturer;
use DateTimeInterface;

/**
 * The calls that go out to Stripe. Behind an interface so the rules around
 * them (limits, trials, who may do what) are tested without a Stripe account;
 * StripeGateway is the real one.
 */
interface BillingGateway
{
    /** A Stripe Checkout page to start a subscription; charging starts after $trialUntil when given. */
    public function checkoutUrl(Manufacturer $manufacturer, string $priceId, ?DateTimeInterface $trialUntil, string $successUrl, string $cancelUrl): string;

    /** Stripe's billing portal: card, invoices, cancelling. */
    public function portalUrl(Manufacturer $manufacturer, string $returnUrl): string;

    /** Move the subscription to another price (plan or interval), prorated. */
    public function swap(Manufacturer $manufacturer, string $priceId): void;
}
