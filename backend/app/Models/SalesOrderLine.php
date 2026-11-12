<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;

/** One line of a sales order, copied from the won quote. Money in cents. Tenant-owned. */
class SalesOrderLine extends Model
{
    use BelongsToManufacturer;

    protected $fillable = ['position', 'kind', 'item_id', 'description', 'quantity', 'unit_price_cents', 'line_total_cents'];
}
