<?php

namespace Tests\Feature;

use App\Billing\BillingGateway;
use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\User;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeBillingGateway;
use Tests\TestCase;

/** Trials, paying through Stripe, plan changes and their limits, and pausing a lapsed account. */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private FakeBillingGateway $gateway;

    private Manufacturer $acme;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cashier.secret' => 'sk_test_fake',
            'cashier.webhook.secret' => null, // webhooks below aren't signed
            'services.stripe.prices' => [
                'starter' => ['monthly' => 'price_starter_m', 'annual' => 'price_starter_y'],
                'pro' => ['monthly' => 'price_pro_m', 'annual' => 'price_pro_y'],
            ],
        ]);
        $this->gateway = new FakeBillingGateway;
        $this->app->instance(BillingGateway::class, $this->gateway);

        $this->acme = Manufacturer::create(['name' => 'Acme Manufacturing', 'slug' => 'acme', 'plan' => Plan::TRIAL, 'trial_ends_at' => now()->addDays(10)]);
        $this->acme->forceFill(['stripe_id' => 'cus_acme'])->save();
        $this->owner = $this->staff($this->acme);
    }

    // ---- Standing ----

    public function test_where_an_account_stands(): void
    {
        $this->assertSame(Plan::TRIALING, Plan::standing($this->acme));

        $this->acme->update(['trial_ends_at' => now()->subDay()]);
        $this->assertSame(Plan::LAPSED, Plan::standing($this->acme->fresh()));

        // A plan with no subscription: billed outside Stripe, or given free.
        $this->acme->update(['plan' => Plan::PRO]);
        $this->assertSame(Plan::ACTIVE, Plan::standing($this->acme->fresh()));

        $this->acme->update(['plan' => Plan::EXPIRED]);
        $this->assertSame(Plan::LAPSED, Plan::standing($this->acme->fresh()));

        $this->actingAs($this->owner)->getJson('/api/me')->assertJsonPath('manufacturers.0.standing', 'lapsed');
    }

    public function test_a_lapsed_account_is_paused_for_everyone_but_billing_stays_open(): void
    {
        $prairie = $this->dealer('Prairie Equipment', $this->acme);
        $dealerUser = $this->dealerUser($prairie);
        $admin = $this->staff($this->acme, 'admin');
        $this->acme->update(['trial_ends_at' => now()->subDay()]);

        $this->actingAs($this->owner)->getJson('/api/m/acme/machines')
            ->assertStatus(402)->assertJsonPath('code', 'account_lapsed')->assertJsonPath('can_manage_billing', true);
        $this->actingAs($admin)->getJson('/api/m/acme/dashboard')
            ->assertStatus(402)->assertJsonPath('can_manage_billing', false);
        $this->actingAs($dealerUser)->getJson('/api/m/acme/machines')
            ->assertStatus(402)
            ->assertJsonPath('message', 'The DealerDock account for Acme Manufacturing is paused. Nothing has been deleted; it\'ll be back as soon as Acme Manufacturing chooses a plan.');

        $this->actingAs($this->owner)->getJson('/api/m/acme/billing')->assertOk()->assertJsonPath('standing', 'lapsed');
        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/checkout', ['plan' => 'pro', 'interval' => 'monthly'])->assertOk();
    }

    public function test_only_the_owner_sees_billing(): void
    {
        $this->actingAs($this->staff($this->acme, 'admin'))->getJson('/api/m/acme/billing')->assertForbidden();
        $this->actingAs($this->dealerUser($this->dealer('Prairie Equipment', $this->acme)))->getJson('/api/m/acme/billing')->assertForbidden();
    }

    // ---- Starting to pay ----

    public function test_checkout_during_the_trial_waits_for_the_trial_to_end(): void
    {
        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/checkout', ['plan' => 'pro', 'interval' => 'annual'])
            ->assertOk()->assertJsonPath('url', 'https://checkout.stripe.test/session');

        $checkout = $this->gateway->checkouts[0];
        $this->assertSame('price_pro_y', $checkout['priceId']);
        $this->assertSame($this->acme->trial_ends_at->timestamp, $checkout['trialUntil']->getTimestamp());
        $this->assertStringEndsWith('/m/acme/billing?checkout=done', $checkout['successUrl']);
    }

    public function test_a_trial_ending_within_two_days_just_charges_now(): void
    {
        $this->acme->update(['trial_ends_at' => now()->addDay()]);

        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/checkout', ['plan' => 'starter', 'interval' => 'monthly'])->assertOk();

        $this->assertNull($this->gateway->checkouts[0]['trialUntil']);
    }

    public function test_starter_is_refused_while_over_its_limits_with_the_reasons(): void
    {
        foreach (range(1, 11) as $i) {
            $this->dealer("Dealer $i", $this->acme);
        }

        $this->actingAs($this->owner)->getJson('/api/m/acme/billing')
            ->assertJsonPath('plans.0.key', 'starter')
            ->assertJsonPath('plans.0.blockers.0', 'You have 11 dealers; Starter includes up to 10. Suspend or remove dealers you no longer work with first.')
            ->assertJsonPath('plans.1.blockers', []);

        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/checkout', ['plan' => 'starter', 'interval' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'You have 11 dealers']);
        $this->assertSame([], $this->gateway->checkouts);

        // Suspending one gets it under the limit.
        $link = Tenant::run($this->acme, fn () => DealerLink::first());
        $this->actingAs($this->owner)->postJson("/api/m/acme/dealers/{$link->id}/status", ['status' => 'suspended'])->assertOk();
        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/checkout', ['plan' => 'starter', 'interval' => 'monthly'])->assertOk();
    }

    public function test_billing_not_set_up_is_said_plainly(): void
    {
        config(['services.stripe.prices.pro.monthly' => null]);

        $this->actingAs($this->owner)->getJson('/api/m/acme/billing')->assertJsonPath('configured', false);
        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/checkout', ['plan' => 'pro', 'interval' => 'monthly'])
            ->assertStatus(503)->assertJsonPath('code', 'billing_not_configured');
    }

    // ---- Stripe's webhooks keep the plan in step ----

    public function test_a_new_subscription_sets_the_plan(): void
    {
        $this->webhook('customer.subscription.created', 'price_pro_m', 'active')->assertOk();

        $acme = $this->acme->fresh();
        $this->assertSame(Plan::PRO, $acme->plan);
        $this->assertSame(Plan::ACTIVE, Plan::standing($acme));
        $this->actingAs($this->owner)->getJson('/api/m/acme/billing')
            ->assertJsonPath('plan', 'pro')->assertJsonPath('interval', 'monthly')->assertJsonPath('subscription.status', 'active');
    }

    public function test_a_failed_payment_keeps_the_account_open_while_stripe_retries(): void
    {
        $this->webhook('customer.subscription.created', 'price_starter_m', 'active');
        $this->webhook('customer.subscription.updated', 'price_starter_m', 'past_due');

        $acme = $this->acme->fresh();
        $this->assertSame(Plan::STARTER, $acme->plan);
        $this->assertSame(Plan::PAST_DUE, Plan::standing($acme));
        $this->actingAs($this->owner)->getJson('/api/m/acme/machines')->assertOk();
    }

    public function test_an_ended_subscription_pauses_the_account(): void
    {
        $this->webhook('customer.subscription.created', 'price_pro_m', 'active');
        $this->webhook('customer.subscription.deleted', 'price_pro_m', 'canceled');

        $this->assertSame(Plan::EXPIRED, $this->acme->fresh()->plan);
        $this->actingAs($this->owner)->getJson('/api/m/acme/machines')->assertStatus(402);
        // Starting again works.
        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/checkout', ['plan' => 'pro', 'interval' => 'monthly'])->assertOk();
        $this->assertNull($this->gateway->checkouts[0]['trialUntil']); // no second trial
    }

    public function test_a_webhook_only_touches_its_own_customer(): void
    {
        $bolt = $this->manufacturer('bolt', Plan::STARTER);
        $bolt->forceFill(['stripe_id' => 'cus_bolt'])->save();

        $this->webhook('customer.subscription.created', 'price_pro_m', 'active', customer: 'cus_bolt');

        $this->assertSame(Plan::PRO, $bolt->fresh()->plan);
        $this->assertSame(Plan::TRIAL, $this->acme->fresh()->plan);
        // An unknown customer is ignored, not an error.
        $this->webhook('customer.subscription.created', 'price_pro_m', 'active', customer: 'cus_nobody')->assertOk();
    }

    // ---- Changing plans ----

    public function test_upgrading_and_downgrading_a_subscription(): void
    {
        $this->webhook('customer.subscription.created', 'price_starter_m', 'active');

        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/change', ['plan' => 'pro', 'interval' => 'annual'])
            ->assertOk()->assertJsonPath('plan', 'pro')->assertJsonPath('interval', 'annual');
        $this->assertSame(Plan::PRO, $this->acme->fresh()->plan);
        $this->assertSame('price_pro_y', $this->gateway->swaps[0]['priceId']);

        // Back down to Starter is refused while over its limits.
        foreach (range(1, 4) as $i) {
            $this->staff($this->acme, 'sales_rep');
        }
        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/change', ['plan' => 'starter', 'interval' => 'annual'])
            ->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'You have 5 staff users; Starter includes up to 3.']);
        $this->assertCount(1, $this->gateway->swaps);
    }

    public function test_change_needs_a_subscription_and_checkout_needs_none(): void
    {
        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/change', ['plan' => 'pro', 'interval' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'no subscription to change']);

        $this->webhook('customer.subscription.created', 'price_pro_m', 'active');
        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/checkout', ['plan' => 'pro', 'interval' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'already have a subscription']);
        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/change', ['plan' => 'pro', 'interval' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['plan' => 'already on that plan']);
    }

    public function test_the_portal_needs_a_billing_account(): void
    {
        $this->acme->forceFill(['stripe_id' => null])->save();
        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/portal')->assertUnprocessable();

        $this->acme->forceFill(['stripe_id' => 'cus_acme'])->save();
        $this->actingAs($this->owner)->postJson('/api/m/acme/billing/portal')->assertOk()->assertJsonPath('url', 'https://billing.stripe.test/portal');
    }

    // ---- Dealer limits follow active dealers ----

    public function test_suspending_frees_a_place_and_reactivating_must_fit(): void
    {
        $this->acme->update(['plan' => Plan::STARTER]);
        foreach (range(1, 10) as $i) {
            $this->dealer("Dealer $i", $this->acme);
        }
        $first = Tenant::run($this->acme, fn () => DealerLink::orderBy('id')->first());

        $this->actingAs($this->owner)->postJson('/api/m/acme/dealers', ['name' => 'Eleventh'])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson("/api/m/acme/dealers/{$first->id}/status", ['status' => 'suspended'])->assertOk()->assertJsonPath('status', 'suspended');
        $this->actingAs($this->owner)->postJson('/api/m/acme/dealers', ['name' => 'Eleventh'])->assertCreated();
        $this->actingAs($this->owner)->postJson("/api/m/acme/dealers/{$first->id}/status", ['status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'up to 10 active dealers']);
        $this->actingAs($this->owner)->getJson('/api/m/acme/dashboard')->assertJsonPath('usage.dealers.used', 10);
    }

    /** A Stripe subscription event for the one-price subscriptions DealerDock uses. */
    private function webhook(string $type, string $price, string $status, string $customer = 'cus_acme'): TestResponse
    {
        return $this->postJson('/stripe/webhook', [
            'id' => 'evt_'.uniqid(),
            'type' => $type,
            'data' => ['object' => [
                'id' => 'sub_'.$customer,
                'object' => 'subscription',
                'customer' => $customer,
                'status' => $status,
                'metadata' => ['type' => 'default'],
                'cancel_at_period_end' => false,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => ['data' => [[
                    'id' => 'si_'.$customer,
                    'price' => ['id' => $price, 'product' => 'prod_'.explode('_', $price)[1]],
                    'quantity' => 1,
                ]]],
            ]],
        ]);
    }
}
