<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A manufacturer's prices for a group of dealers, in one currency. Tenant-owned.
 *
 * One list is the default: a dealer with no list of their own buys from it.
 * Dealers never read price lists directly - only the catalog of the one list
 * that applies to them (PriceList::forDealer).
 */
class PriceList extends Model
{
    use BelongsToManufacturer;

    public const CURRENCIES = ['USD', 'CAD'];

    protected $fillable = ['name', 'currency', 'notes'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function dealerLinks(): HasMany
    {
        return $this->hasMany(DealerLink::class);
    }

    public static function default(): ?self
    {
        return self::where('is_default', true)->first();
    }

    /** The list a dealer buys from: their own, or the default. */
    public static function forDealer(DealerLink $link): ?self
    {
        return ($link->price_list_id ? self::find($link->price_list_id) : null) ?? self::default();
    }

    /** @return array<string, int> "model:12" / "option:7" => cents */
    public function priceMap(): array
    {
        return $this->items()->get()->mapWithKeys(fn (PriceListItem $i) => [$i->item_type.':'.$i->item_id => (int) $i->price_cents])->all();
    }
}
