<?php

namespace App\Jobs;

use App\Integrations\ErpSync;
use App\Models\Manufacturer;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One fetch from their ERP (order status, prices or machines), inside its
 * manufacturer. Every 15 minutes (erp:sync) or from "Sync now". Only one of
 * each per manufacturer at a time (ShouldBeUnique), so a slow ERP can't pile
 * runs up. Not retried: a failed run is recorded, and the next one tries again.
 */
class RunErpSync implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    public function __construct(public int $manufacturerId, public string $sync, public bool $full = false) {}

    public function uniqueId(): string
    {
        return $this->manufacturerId.':'.$this->sync;
    }

    public function handle(ErpSync $erp): void
    {
        $manufacturer = Manufacturer::find($this->manufacturerId);
        if (! $manufacturer || Plan::standing($manufacturer) === Plan::LAPSED) {
            return;
        }

        Tenant::run($manufacturer, function () use ($erp) {
            $connection = ErpSync::connection();
            if ($connection?->syncOn($this->sync)) {
                $erp->run($connection, $this->sync, $this->full);
            }
        });
    }
}
