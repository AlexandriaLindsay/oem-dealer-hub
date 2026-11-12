<?php

use App\Jobs\RunErpSync;
use App\Models\ErpConnection;
use App\Models\ErpSyncRun;
use App\Models\Manufacturer;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\UsageDay;
use App\Models\WebhookDelivery;
use App\Support\Plan;
use App\Support\StockChecks;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
 * Usage counts are kept 13 months, then deleted - what the privacy policy
 * says. Across every manufacturer on purpose (a date-only cleanup, no one's
 * data read), so withoutTenant().
 */
Artisan::command('usage:prune', function () {
    $deleted = UsageDay::withoutTenant()->where('day', '<', now()->subMonths(13)->toDateString())->delete();
    $this->info("Deleted {$deleted} usage rows older than 13 months.");
})->purpose('Delete usage counts older than 13 months');

Schedule::command('usage:prune')->daily();

/*
 * Webhook calls are kept 30 days - long enough to see what was sent and
 * retry a failure, not a second copy of everyone's orders forever.
 */
Artisan::command('webhooks:prune', function () {
    $deleted = WebhookDelivery::withoutTenant()->where('created_at', '<', now()->subDays(30))->delete();
    $this->info("Deleted {$deleted} webhook calls older than 30 days.");
})->purpose('Delete webhook call records older than 30 days');

Schedule::command('webhooks:prune')->daily();

/*
 * Stock checks: close the overdue ones, and start this month's for
 * manufacturers who turned monthly checks on (one per active dealer per
 * calendar month - daily, so a missed day catches up). Inside each
 * manufacturer (Tenant::run). Paused accounts are skipped.
 */
Artisan::command('stock-checks:run', function () {
    $started = 0;
    $closed = 0;
    foreach (Manufacturer::all() as $manufacturer) {
        // Close the overdue ones first, so last month's unfinished check never holds up this month's.
        [$c, $s] = Tenant::run($manufacturer, fn () => [
            StockChecks::expireOverdue(),
            Plan::standing($manufacturer) === Plan::LAPSED ? 0 : StockChecks::monthlyRound($manufacturer),
        ]);
        $started += $s;
        $closed += $c;
    }
    $this->info("Started {$started} stock checks; closed {$closed} overdue.");
})->purpose('Start monthly stock checks and close overdue ones');

Schedule::command('stock-checks:run')->dailyAt('07:00');

/*
 * Quotes marked won before sales orders existed have no order. This makes
 * the missing ones, each inside its own manufacturer (Tenant::run), dated
 * when the quote was won. Safe to run again: a quote that has an order is skipped.
 */
Artisan::command('sales-orders:backfill', function () {
    $made = 0;
    foreach (Manufacturer::all() as $manufacturer) {
        $made += Tenant::run($manufacturer, function () {
            $made = 0;
            $quotes = Quote::withoutGlobalScope('dealer')->where('status', Quote::WON)->whereDoesntHave('salesOrder')->orderBy('decided_at')->orderBy('id')->get();
            foreach ($quotes as $quote) {
                DB::transaction(function () use ($quote) {
                    $order = SalesOrder::fromQuote($quote, null);
                    $order->forceFill(['created_at' => $quote->decided_at ?? $order->created_at])->save();
                });
                $made++;
            }

            return $made;
        });
    }
    $this->info("Made {$made} sales order".($made === 1 ? '' : 's').' for quotes already won.');
})->purpose('Make sales orders for quotes that were won before sales orders existed');

/*
 * The ERP connection's fetches (order status, prices, machines), every 15
 * minutes, for each manufacturer that has connected OEM Dealer Hub to their
 * ERP's API and is on a plan that includes it. Each is its own queued job,
 * run inside its manufacturer, so one slow ERP doesn't hold up the others.
 */
Artisan::command('erp:sync', function () {
    $queued = 0;
    foreach (ErpConnection::withoutTenant()->where('active', true)->with('manufacturer')->get() as $connection) {
        if (! $connection->manufacturer || ! Plan::includes($connection->manufacturer, 'erp_integration')) {
            continue;
        }
        foreach (ErpConnection::PULLS as $sync) {
            if ($connection->syncOn($sync)) {
                RunErpSync::dispatch($connection->manufacturer_id, $sync);
                $queued++;
            }
        }
    }
    $this->info("Queued {$queued} ERP sync".($queued === 1 ? '' : 's').'.');
})->purpose('Fetch order status, prices and machines from connected ERPs');

Schedule::command('erp:sync')->everyFifteenMinutes();

/* ERP sync records are kept 30 days, like webhook calls. */
Artisan::command('erp-runs:prune', function () {
    $deleted = ErpSyncRun::withoutTenant()->where('created_at', '<', now()->subDays(30))->delete();
    $this->info("Deleted {$deleted} ERP sync records older than 30 days.");
})->purpose('Delete ERP sync records older than 30 days');

Schedule::command('erp-runs:prune')->daily();
