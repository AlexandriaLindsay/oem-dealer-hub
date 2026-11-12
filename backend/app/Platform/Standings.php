<?php

namespace App\Platform;

use App\Models\Manufacturer;
use App\Support\Plan;
use Illuminate\Database\Eloquent\Builder;

/**
 * Plan::standing() as a database query, so the console can filter and count
 * accounts by standing without loading every one. It has to agree with
 * Plan::standing (StaffConsoleTest checks it does): change both together.
 */
final class Standings
{
    public const LABELS = [
        Plan::TRIALING => 'Trial',
        Plan::ACTIVE => 'Active',
        Plan::PAST_DUE => 'Past due',
        Plan::LAPSED => 'Paused',
    ];

    public const COLORS = [
        Plan::TRIALING => 'info',
        Plan::ACTIVE => 'success',
        Plan::PAST_DUE => 'warning',
        Plan::LAPSED => 'danger',
    ];

    /** @param  Builder<Manufacturer>  $query */
    public static function where(Builder $query, string $standing): Builder
    {
        $paid = [Plan::STARTER, Plan::PRO];
        $pastDue = fn (Builder $s) => $s->where('type', 'default')->where('stripe_status', 'past_due');

        return match ($standing) {
            Plan::TRIALING => $query->where('plan', Plan::TRIAL)->where('trial_ends_at', '>', now()),
            Plan::LAPSED => $query->where(fn (Builder $q) => $q
                ->where('plan', Plan::EXPIRED)
                ->orWhere(fn (Builder $q) => $q->where('plan', Plan::TRIAL)
                    ->where(fn (Builder $q) => $q->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', now())))),
            Plan::PAST_DUE => $query->whereIn('plan', $paid)->whereHas('subscriptions', $pastDue),
            Plan::ACTIVE => $query->whereIn('plan', $paid)->whereDoesntHave('subscriptions', $pastDue),
            default => throw new \InvalidArgumentException("Unknown standing $standing"),
        };
    }

    public const PLAN_LABELS = [
        Plan::TRIAL => 'Trial',
        Plan::STARTER => 'Starter',
        Plan::PRO => 'Pro',
        Plan::EXPIRED => 'Ended',
    ];
}
