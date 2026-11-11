<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductLine extends Model
{
    use BelongsToManufacturer, HasFactory;

    protected $fillable = ['name'];

    public function models(): HasMany
    {
        return $this->hasMany(ProductModel::class)->orderBy('name');
    }
}
