<?php

namespace App\Support;

use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\StockCheck;
use App\Models\StockCheckItem;
use App\Models\User;
use App\Notifications\StockCheckRequested;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stock checks: keeping "what's on each dealer's lot" true without asking
 * dealers to type. Once a month (if the manufacturer turns it on) or when
 * asked, each dealer gets the list of machines we think they have - anything
 * not sold - and confirms them: one click for "everything's here", or
 * machine by machine. "Not here" is flagged to the manufacturer.
 *
 * Every call here runs inside the current manufacturer (Tenant).
 */
final class StockChecks
{
    /** Machines a dealer is asked about: everything with them that isn't sold. */
    public const ASKED_ABOUT = ['inventory', 'demo', 'rental', 'used'];

    /**
     * Starts a check for one dealer and emails their people. Null when
     * there's nothing to ask about, or they already have one open.
     */
    public static function start(DealerLink $link, ?User $by): ?StockCheck
    {
        // A check past its due date doesn't block a new one, even before stock-checks:run has closed it.
        $openCheck = StockCheck::where('dealer_link_id', $link->id)->where('status', StockCheck::OPEN)->where('due_on', '>=', now()->toDateString())->exists();
        if ($link->status !== DealerLink::ACTIVE || $openCheck) {
            return null;
        }
        $machineIds = Machine::where('dealer_link_id', $link->id)->whereIn('status', self::ASKED_ABOUT)->pluck('id');
        if ($machineIds->isEmpty()) {
            return null;
        }

        $token = Str::random(48);
        $check = DB::transaction(function () use ($link, $by, $machineIds, $token) {
            // An overdue one still marked open is closed now, so there's only ever one open check.
            StockCheck::where('dealer_link_id', $link->id)->where('status', StockCheck::OPEN)->update(['status' => StockCheck::EXPIRED]);
            $check = new StockCheck;
            $check->forceFill([
                'dealer_link_id' => $link->id,
                'token_hash' => hash('sha256', $token),
                'due_on' => now()->addDays(StockCheck::DAYS_TO_ANSWER)->toDateString(),
                'requested_by' => $by?->id,
            ])->save();
            foreach ($machineIds as $id) {
                // Through the relation, so stock_check_id is set (it isn't fillable).
                $check->items()->make()->forceFill(['machine_id' => $id])->save();
            }

            return $check;
        });

        $dealer = $link->dealer;
        $people = User::whereIn('id', Membership::where('org_type', Membership::DEALER)->where('org_id', $dealer->id)->pluck('user_id'))->get();
        $manufacturer = Tenant::current()->manufacturer();
        foreach ($people as $person) {
            // A mail problem is logged, never undoes the check.
            rescue(fn () => $person->notify(new StockCheckRequested($manufacturer, $dealer->name, $machineIds->count(), $check->due_on->toDateString(), $token)), report: true);
        }

        return $check;
    }

    /** The check a one-click link points at, with the manufacturer it belongs to; null if the link is wrong. */
    public static function byToken(string $token): ?StockCheck
    {
        return StockCheck::withoutTenant()->with('manufacturer')->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * Records answers: [machine id => 'here'|'not_here'] with optional notes.
     * Machines not in this check are ignored. "Here" also stamps the
     * machine's confirmed_at. The check is done once every machine is answered.
     *
     * @param  array<int, string>  $answers
     * @param  array<int, string|null>  $notes
     */
    public static function answer(StockCheck $check, array $answers, array $notes, ?User $by): StockCheck
    {
        if (! $check->isOpen()) {
            throw ValidationException::withMessages(['answers' => $check->status === StockCheck::DONE ? 'This stock check is already finished. Thank you!' : 'This stock check has closed.']);
        }

        DB::transaction(function () use ($check, $answers, $notes, $by) {
            $items = $check->items()->whereIn('machine_id', array_keys($answers))->get();
            foreach ($items as $item) {
                $answer = $answers[$item->machine_id];
                $item->forceFill(['answer' => $answer, 'note' => $notes[$item->machine_id] ?? null, 'answered_at' => now()])->save();
                if ($answer === StockCheckItem::HERE) {
                    Machine::whereKey($item->machine_id)->update(['confirmed_at' => now()]);
                }
            }

            if (! $check->items()->whereNull('answer')->exists()) {
                $check->forceFill(['status' => StockCheck::DONE, 'completed_at' => now(), 'completed_by' => $by?->id])->save();
            }
        });

        return $check->fresh();
    }

    /** "Everything's here": every machine not answered yet. */
    public static function confirmAll(StockCheck $check, ?User $by): StockCheck
    {
        $open = $check->items()->whereNull('answer')->pluck('machine_id');

        return self::answer($check, $open->mapWithKeys(fn ($id) => [$id => StockCheckItem::HERE])->all(), [], $by);
    }

    /** Monthly rounds, for manufacturers who turned them on: one check per active dealer per calendar month. */
    public static function monthlyRound(Manufacturer $manufacturer): int
    {
        if (empty($manufacturer->settings['stock_checks_monthly'])) {
            return 0;
        }
        $started = 0;
        foreach (DealerLink::where('status', DealerLink::ACTIVE)->with('dealer')->get() as $link) {
            $thisMonth = StockCheck::where('dealer_link_id', $link->id)->where('created_at', '>=', now()->startOfMonth())->exists();
            if (! $thisMonth && self::start($link, null)) {
                $started++;
            }
        }

        return $started;
    }

    /** Checks past their due date close; whatever wasn't answered stays unconfirmed. */
    public static function expireOverdue(): int
    {
        return StockCheck::where('status', StockCheck::OPEN)->where('due_on', '<', now()->toDateString())->update(['status' => StockCheck::EXPIRED]);
    }

    /** What a dealer is shown for one check (in the app, and on the one-click page). */
    public static function present(StockCheck $check): array
    {
        $check->loadMissing(['items.machine.productModel', 'dealerLink.dealer']);

        return [
            'id' => $check->id,
            'status' => $check->isOpen() ? StockCheck::OPEN : ($check->status === StockCheck::OPEN ? StockCheck::EXPIRED : $check->status),
            'dealer' => $check->dealerLink->dealer->name,
            'due_on' => $check->due_on->toDateString(),
            'created_at' => $check->created_at?->toIso8601String(),
            'completed_at' => $check->completed_at?->toIso8601String(),
            'items' => $check->items->sortBy(fn (StockCheckItem $i) => $i->machine?->serial_no)->map(fn (StockCheckItem $i) => [
                'machine_id' => $i->machine_id,
                'serial_no' => $i->machine?->serial_no,
                'model' => $i->machine?->productModel?->name,
                'status' => $i->machine?->status,
                'answer' => $i->answer,
                'note' => $i->note,
            ])->values(),
        ];
    }
}
