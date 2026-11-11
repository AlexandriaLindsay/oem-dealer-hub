<?php

namespace App\Billing;

use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\PriceList;
use App\Support\Uploads;
use App\Tenancy\Tenant;

/** What a manufacturer uses of the things plans limit. */
final class Usage
{
    /** @return array{dealers: int, staff: int, storage_gb: float, price_lists: int} */
    public static function of(Manufacturer $manufacturer): array
    {
        return Tenant::run($manufacturer, fn () => [
            // Suspended dealers don't count - suspending is how to get under a limit.
            'dealers' => DealerLink::where('status', DealerLink::ACTIVE)->count(),
            'staff' => $manufacturer->staff()->count(),
            'storage_gb' => round(Uploads::usedBytes() / 1024 ** 3, 1),
            'price_lists' => PriceList::count(),
        ]);
    }
}
