<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Billing\BillingGateway;
use App\Billing\SyncPlan;
use App\Billing\Usage;
use App\Http\Controllers\Controller;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The owner's billing page: where the account stands, the plans it can move
 * to, Stripe Checkout to start paying, plan changes, and Stripe's portal for
 * the card and invoices. Reachable even while the account is paused.
 */
class BillingController extends Controller
{
    public function show(): JsonResponse
    {
        $m = Tenant::current()->manufacturer();
        $usage = Usage::of($m);
        $subscription = $m->subscription('default');
        $live = $subscription?->valid() ? $subscription : null;
        [$plan, $interval] = Plan::forPrice($live?->stripe_price) ?? [in_array($m->plan, [Plan::STARTER, Plan::PRO], true) ? $m->plan : null, null];

        return response()->json([
            'standing' => Plan::standing($m),
            'plan' => $plan,
            'interval' => $interval,
            'trial_ends_at' => $m->plan === Plan::TRIAL ? $m->trial_ends_at?->toIso8601String() : null,
            'subscription' => $live ? [
                'status' => $live->stripe_status,
                // Cancelled, still paid up until then.
                'ends_at' => $live->ends_at?->toIso8601String(),
                'trial_ends_at' => $live->trial_ends_at?->toIso8601String(),
            ] : null,
            // Billed outside Stripe (invoice) or given free: nothing to manage here.
            'managed_outside' => ! $live && in_array($m->plan, [Plan::STARTER, Plan::PRO], true),
            'card' => $m->pm_last_four ? ['brand' => $m->pm_type, 'last_four' => $m->pm_last_four] : null,
            'usage' => $usage,
            'configured' => self::configured(),
            'plans' => collect(Plan::PLANS)->map(fn ($p, $key) => [
                'key' => $key,
                'label' => $p['label'],
                'monthly_usd' => $p['monthly_usd'],
                'annual_usd' => $p['annual_usd'],
                'limits' => $p['limits'],
                'excludes' => $p['excludes'],
                'blockers' => Plan::blockers($key, $usage),
            ])->values(),
        ]);
    }

    /** Starts paying: a Stripe Checkout page. During the trial, the first charge waits until the trial ends. */
    public function checkout(Request $request, BillingGateway $gateway): JsonResponse
    {
        $m = Tenant::current()->manufacturer();
        [$plan, $interval, $priceId] = $this->choice($request);

        if ($m->subscription('default')?->valid()) {
            throw ValidationException::withMessages(['plan' => 'You already have a subscription. Change plans instead.']);
        }

        // Stripe needs a trial to run at least 48 hours; a trial ending sooner just charges now.
        $trialUntil = $m->plan === Plan::TRIAL && $m->trial_ends_at?->isAfter(now()->addDays(2)) ? $m->trial_ends_at : null;
        $back = rtrim(config('app.frontend_url'), '/').'/m/'.$m->slug.'/billing';

        $url = $gateway->checkoutUrl($m, $priceId, $trialUntil, $back.'?checkout=done', $back.'?checkout=cancelled');

        return response()->json(['url' => $url, 'plan' => $plan, 'interval' => $interval]);
    }

    /** Moves an existing subscription to another plan or interval. */
    public function change(Request $request, BillingGateway $gateway): JsonResponse
    {
        $m = Tenant::current()->manufacturer();
        [, , $priceId] = $this->choice($request);

        $subscription = $m->subscription('default');
        if (! $subscription?->valid()) {
            throw ValidationException::withMessages(['plan' => 'There\'s no subscription to change yet. Choose a plan to start one.']);
        }
        if ($subscription->stripe_price === $priceId) {
            throw ValidationException::withMessages(['plan' => 'You\'re already on that plan.']);
        }

        $gateway->swap($m, $priceId);
        // The swap updated the subscription here too; the webhook will confirm it.
        SyncPlan::run($m->fresh());

        return $this->show();
    }

    public function portal(BillingGateway $gateway): JsonResponse
    {
        $m = Tenant::current()->manufacturer();
        if (! $m->stripe_id) {
            throw ValidationException::withMessages(['portal' => 'There\'s no billing account yet. Choose a plan first.']);
        }

        return response()->json(['url' => $gateway->portalUrl($m, rtrim(config('app.frontend_url'), '/').'/m/'.$m->slug.'/billing')]);
    }

    /**
     * The plan and interval asked for, checked against the account's usage.
     *
     * @return array{0: string, 1: string, 2: string} [plan, interval, Stripe price id]
     */
    private function choice(Request $request): array
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys(Plan::PLANS))],
            'interval' => ['required', Rule::in(Plan::INTERVALS)],
        ]);

        $priceId = Plan::priceId($data['plan'], $data['interval']);
        if (! $priceId) {
            abort(response()->json(['message' => 'Billing isn\'t set up on this server yet.', 'code' => 'billing_not_configured'], 503));
        }

        $blockers = Plan::blockers($data['plan'], Usage::of(Tenant::current()->manufacturer()));
        if ($blockers) {
            throw ValidationException::withMessages(['plan' => $blockers]);
        }

        return [$data['plan'], $data['interval'], $priceId];
    }

    private static function configured(): bool
    {
        return (bool) config('cashier.secret') && collect(array_keys(Plan::PLANS))
            ->every(fn ($p) => collect(Plan::INTERVALS)->every(fn ($i) => Plan::priceId($p, $i)));
    }
}
