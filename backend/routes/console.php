<?php

use App\Models\Manufacturer;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\UsageDay;
use App\Models\WebhookDelivery;
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
