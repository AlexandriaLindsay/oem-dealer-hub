<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One manufacturer's relationship with one dealer. Tenant-owned. */
class DealerLink extends Model
{
    use BelongsToManufacturer, HasFactory;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    protected $fillable = ['dealer_id', 'dealer_code', 'status'];

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /** The dealer's own price list; null means they buy from the default (PriceList::forDealer). */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
