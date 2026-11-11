<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something a model can be ordered with (e.g. "Hydraulic tilt"). Tenant-owned. */
class ProductOption extends Model
{
    use BelongsToManufacturer, HasFactory;

    protected $fillable = ['product_model_id', 'name'];

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
    }
}
