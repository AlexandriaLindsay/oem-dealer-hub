<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One manufacturer's relationship with one dealer. Tenant-owned.
 *
 * Staff limited by territories only see the dealers in their territories
 * (App\Support\Territories) - a global scope, named "dealer" like the one on
 * dealer-owned data, so withoutTenant() lifts both.
 */
class DealerLink extends Model
{
    use BelongsToManufacturer, HasFactory;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    // price_list_id and territory_id aren't fillable: they're set only by
    // PriceListController::assign and TerritoryController::assignDealer.
    protected $fillable = ['dealer_id', 'dealer_code', 'status'];

    protected static function booted(): void
    {
        static::addGlobalScope('dealer', function (Builder $query) {
            $tenant = Tenant::current();
            if ($tenant->check() && ($links = $tenant->territoryLinkIds()) !== null) {
                $query->whereIn($query->qualifyColumn('id'), $links);
            }
        });
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /** The dealer's own price list; null means they buy from the default (PriceList::forDealer). */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }
}
