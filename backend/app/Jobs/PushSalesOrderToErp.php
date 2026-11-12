<?php

namespace App\Jobs;

use App\Integrations\ErpException;
use App\Integrations\ErpSync;
use App\Models\ErpSyncRun;
use App\Models\Manufacturer;
use App\Tenancy\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one new sales order to their ERP (ErpSync::push), inside its
 * manufacturer. Tried again like a webhook - after 1 minute, 5, 30, 2 hours
 * and 6 hours - when their ERP doesn't answer or has an error of its own; a
 * refusal (a 4xx, say a bad token) is marked failed at once, since trying
 * again won't change it. A failed one can be sent again from Integrations.
 *
 * Carries only ids, so no order or token sits in the queue table.
 */
class PushSalesOrderToErp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public int $manufacturerId, public int $runId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 21600];
    }

    public function handle(ErpSync $sync): void
    {
        $manufacturer = Manufacturer::find($this->manufacturerId);
        if (! $manufacturer) {
            return;
        }

        Tenant::run($manufacturer, function () use ($sync) {
            $run = ErpSyncRun::with('salesOrder')->find($this->runId);
            if (! $run || $run->status !== ErpSyncRun::RUNNING) {
                return;
            }

            $connection = ErpSync::connection();
            if (! $connection?->syncOn('orders_push')) {
                $run->forceFill(['status' => ErpSyncRun::FAILED, 'message' => 'Not sent: sending orders to your ERP is turned off.', 'finished_at' => now()])->save();

                return;
            }

            $run->increment('attempts');
            try {
                $sync->push($connection, $run);
            } catch (ErpException $e) {
                $last = ! $e->retryable() || $this->attempts() >= $this->tries;
                $run->forceFill([
                    'status' => $last ? ErpSyncRun::FAILED : ErpSyncRun::RUNNING,
                    'message' => mb_substr($e->getMessage(), 0, 1000),
                    'finished_at' => $last ? now() : null,
                ])->save();
                if (! $last) {
                    $this->release($this->backoff()[$this->attempts() - 1] ?? 21600);
                }
            }
        });
    }
}
