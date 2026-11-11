<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * For tenant-owned models that belong to one dealer (a dealer_link_id column):
 * when the request is acting as a dealer, every query only returns that
 * dealer's rows - never another dealer's, even at the same manufacturer.
 *
 * Staff limited by territories (App\Support\Territories) see only the rows of
 * dealers in their territories. Other staff, and background work (Tenant::run
 * sets no dealer or membership), see every row at the manufacturer.
 *
 * A global scope rather than a filter in each controller, so a forgotten
 * filter can't leak a competitor's stock or customers.
 *
 * Use alongside BelongsToManufacturer.
 */
trait VisibleToOwnDealer
{
    public static function bootVisibleToOwnDealer(): void
    {
        static::addGlobalScope('dealer', function (Builder $query) {
            $tenant = Tenant::current();
            if (! $tenant->check()) {
                return;
            }

            $column = $query->qualifyColumn('dealer_link_id');
            if ($tenant->isDealer()) {
                $query->where($column, $tenant->dealerLink()->id);
            } elseif (($links = $tenant->territoryLinkIds()) !== null) {
                $query->where(function (Builder $q) use ($column, $links) {
                    $q->whereIn($column, $links);
                    if (static::territoryRepsSeeRowsWithoutDealer()) {
                        $q->orWhereNull($column);
                    }
                });
            }
        });
    }

    /**
     * Whether rows with no dealer (the manufacturer's own) still show for
     * territory-limited staff. Models override this to say yes.
     */
    protected static function territoryRepsSeeRowsWithoutDealer(): bool
    {
        return false;
    }
}
