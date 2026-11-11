<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A model within a product line (e.g. "S-400" in "Scrapers"). Tenant-owned. */
class ProductModel extends Model
{
    use BelongsToManufacturer, HasFactory;

    protected $fillable = ['product_line_id', 'name', 'category', 'specs'];

    protected function casts(): array
    {
        return ['specs' => 'array'];
    }

    public function productLine(): BelongsTo
    {
        return $this->belongsTo(ProductLine::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('name');
    }

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }
}
