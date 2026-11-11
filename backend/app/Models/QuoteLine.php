<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;

/** One line of a quote. Money in cents. Tenant-owned. */
class QuoteLine extends Model
{
    use BelongsToManufacturer;

    public const KINDS = ['model', 'option', 'custom'];

    protected $fillable = ['position', 'kind', 'item_id', 'description', 'quantity', 'list_price_cents', 'unit_price_cents', 'line_total_cents'];
}
