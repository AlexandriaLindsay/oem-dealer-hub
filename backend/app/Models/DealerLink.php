<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One manufacturer's relationship with one dealer. Tenant-owned.
 *
 * Staff limited by territories only see the dealers in their territories
 * (App\Support\Territories) - a global scope, named "dealer" like the one on
 * dealer-owned data, so withoutTenant() lifts both.
 *
 * The product lines a dealer carries limit what they can price and quote.
 * None chosen means every line (see productLineIds).
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

    /** The lines this dealer carries. Set only through DealerController::productLines. */
    public function productLines(): BelongsToMany
    {
        return $this->belongsToMany(ProductLine::class, 'dealer_product_lines')->withPivot('manufacturer_id');
    }

    /**
     * The ids of the lines this dealer carries, or null for every line.
     * "None chosen" means every line, so a manufacturer with one line (or a
     * dealer who sells them all) never has to set anything, and a new line
     * shows up for those dealers without anyone remembering to tick it.
     *
     * @return list<int>|null
     */
    public function productLineIds(): ?array
    {
        $ids = $this->relationLoaded('productLines')
            ? $this->productLines->pluck('id')
            : $this->productLines()->pluck('product_lines.id');

        return $ids->isEmpty() ? null : $ids->map(fn ($id) => (int) $id)->sort()->values()->all();
    }
}
