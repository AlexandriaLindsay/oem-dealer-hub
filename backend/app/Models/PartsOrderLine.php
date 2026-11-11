<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;

/** One line of a parts order: a part number and how many. Tenant-owned. */
class PartsOrderLine extends Model
{
    use BelongsToManufacturer;

    protected $fillable = ['position', 'part_number', 'description', 'quantity'];
}
