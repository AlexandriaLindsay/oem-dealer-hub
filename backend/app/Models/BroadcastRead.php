<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person opening a broadcast. Tenant-owned. */
class BroadcastRead extends Model
{
    use BelongsToManufacturer;

    public $timestamps = false;

    protected $fillable = ['user_id', 'dealer_link_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
