<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * For tenant-owned models that belong to one dealer (a dealer_link_id column):
 * when the request is acting as a dealer, every query only returns that
 * dealer's rows - never another dealer's, even at the same manufacturer.
 *
 * A global scope rather than a filter in each controller, so a forgotten
 * filter can't leak a competitor's stock or customers. Staff, and background
 * work (Tenant::run sets no dealer), see every row at the manufacturer.
 *
 * Use alongside BelongsToManufacturer.
 */
trait VisibleToOwnDealer
{
    public static function bootVisibleToOwnDealer(): void
    {
        static::addGlobalScope('dealer', function (Builder $query) {
            $tenant = Tenant::current();
            if ($tenant->check() && $tenant->isDealer()) {
                $query->where($query->qualifyColumn('dealer_link_id'), $tenant->dealerLink()->id);
            }
        });
    }
}
