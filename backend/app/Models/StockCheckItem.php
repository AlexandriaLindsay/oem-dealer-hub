<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One machine in a stock check, and the dealer's answer: 'here', 'not_here', or null (not answered). Tenant-owned. */
class StockCheckItem extends Model
{
    use BelongsToManufacturer;

    public const HERE = 'here';

    public const NOT_HERE = 'not_here';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function stockCheck(): BelongsTo
    {
        return $this->belongsTo(StockCheck::class);
    }
}
