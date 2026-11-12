<?php

namespace App\Platform;

use App\Billing\Usage;
use App\Models\Manufacturer;
use App\Models\StaffAction;
use App\Models\User;
use App\Support\Plan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Everything the staff console can change about a customer's account. Each
 * change needs a reason and is written to the activity log (StaffAction) in
 * the same transaction, so there's never a change without its entry.
 *
 * The console's buttons only call these. Keeping the rules here, not in the
 * Filament pages, means tests cover them directly.
 */
final class AccountTools
{
    /** The furthest out a trial can be set, so a typo can't give away years. */
    public const MAX_TRIAL_DAYS = 90;

    /** The plans staff can set by hand: an account billed by invoice (or given free), or paused. */
    public const MANUAL_PLANS = [Plan::STARTER, Plan::PRO, Plan::EXPIRED];

    /**
     * Why staff can't change $manufacturer's plan or trial by hand, or null if
     * they can. A Stripe subscription is in charge of its own plan: a change
     * here would be overwritten by the next webhook (App\Billing\SyncPlan).
     */
    public static function billedByStripe(Manufacturer $manufacturer): ?string
    {
        return $manufacturer->subscribed('default')
            ? 'This account pays through Stripe, so its plan follows the subscription. Change it in Stripe instead.'
            : null;
    }

    /** Give a trial (or an ended one) more time, up to MAX_TRIAL_DAYS from now. */
    public static function extendTrial(Manufacturer $manufacturer, CarbonInterface $until, User $staff, string $note): void
    {
        self::refuseIf(self::billedByStripe($manufacturer));
        self::refuseIf(! in_array($manufacturer->plan, [Plan::TRIAL, Plan::EXPIRED], true)
            ? 'Only a trial, or an account whose trial or plan has ended, can be given trial time. This account is on '.Plan::PLANS[$manufacturer->plan]['label'].'.'
            : null);
        self::refuseIf(! $until->isFuture() ? 'Pick a date after today.' : null);
        self::refuseIf($until->gt(now()->addDays(self::MAX_TRIAL_DAYS)->endOfDay())
            ? 'A trial can run at most '.self::MAX_TRIAL_DAYS.' days from today.'
            : null);

        // Eloquent saves a date's clock time as it is, without converting, so
        // "end of day Eastern" has to become UTC first.
        $until = CarbonImmutable::instance($until)->setTimezone(config('app.timezone'));

        DB::transaction(function () use ($manufacturer, $until, $staff, $note) {
            $details = ['plan' => [$manufacturer->plan, Plan::TRIAL], 'trial_ends_at' => [$manufacturer->trial_ends_at?->toIso8601String(), $until->toIso8601String()]];
            $manufacturer->forceFill(['plan' => Plan::TRIAL, 'trial_ends_at' => $until])->save();
            StaffAction::record($staff, 'extend_trial', $manufacturer, $manufacturer->name, $note, $details);
        });
    }

    /**
     * Set the plan of an account that doesn't pay through Stripe. Starter or
     * Pro with no subscription is "billed outside Stripe" and active (see
     * Plan::standing); expired pauses it. Moving to Starter follows the same
     * rule as the Billing page: the account has to be under Starter's limits
     * first, so nobody's dealers or staff are silently cut off.
     */
    public static function setPlan(Manufacturer $manufacturer, string $plan, User $staff, string $note): void
    {
        self::refuseIf(! in_array($plan, self::MANUAL_PLANS, true) ? 'That isn\'t a plan staff can set.' : null);
        self::refuseIf(self::billedByStripe($manufacturer));
        self::refuseIf($plan === $manufacturer->plan ? 'The account is already on that plan.' : null);

        if ($plan === Plan::STARTER) {
            $blockers = Plan::blockers($plan, Usage::of($manufacturer));
            self::refuseIf($blockers ? 'It\'s over Starter\'s limits: '.implode(' ', $blockers) : null);
        }

        DB::transaction(function () use ($manufacturer, $plan, $staff, $note) {
            $details = ['plan' => [$manufacturer->plan, $plan]];
            $manufacturer->forceFill(['plan' => $plan])->save();
            StaffAction::record($staff, 'set_plan', $manufacturer, $manufacturer->name, $note, $details);
        });
    }

    /**
     * For someone who's lost their authenticator: clears it, and signs them
     * out everywhere (sessions and "remember me"), so whoever has the old phone
     * can't carry on either. They set up a new one at their next sign-in.
     */
    public static function resetTwoFactor(User $user, User $staff, string $note): void
    {
        self::refuseIf($user->is($staff) ? 'You can\'t reset your own two-factor here. Ask another staff member.' : null);
        self::refuseIf(! $user->hasTwoFactor() ? 'They haven\'t set up two-factor yet, so there\'s nothing to reset.' : null);

        DB::transaction(function () use ($user, $staff, $note) {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_method' => null,
                'remember_token' => Str::random(60),
            ])->save();
            // Remembered browsers skip the code, so they go too.
            $user->trustedBrowsers()->delete();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            StaffAction::record($staff, 'reset_two_factor', $user, $user->email, $note);
        });

        Cache::forget("two-factor-used:{$user->id}");
    }

    /** The same email they'd get from "Forgot password". Staff never see or set a password. */
    public static function sendPasswordReset(User $user, User $staff, string $note): void
    {
        $status = Password::sendResetLink(['email' => $user->email]);
        self::refuseIf($status === Password::RESET_THROTTLED ? 'A reset email went to them in the last minute. Give it a moment before sending another.' : null);
        self::refuseIf($status !== Password::RESET_LINK_SENT ? 'The reset email couldn\'t be sent.' : null);

        StaffAction::record($staff, 'send_password_reset', $user, $user->email, $note);
    }

    private static function refuseIf(?string $reason): void
    {
        if ($reason !== null) {
            throw new RefusedException($reason);
        }
    }
}
