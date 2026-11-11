<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One price on a list: a model or an option, in cents. Tenant-owned. */
class PriceListItem extends Model
{
    use BelongsToManufacturer;

    public const MODEL = 'model';

    public const OPTION = 'option';

    protected $fillable = ['item_type', 'item_id', 'price_cents'];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
