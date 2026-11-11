<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A dealer a broadcast went to. Tenant-owned. */
class BroadcastRecipient extends Model
{
    use BelongsToManufacturer;

    public $timestamps = false;

    protected $fillable = ['dealer_link_id'];

    public function dealerLink(): BelongsTo
    {
        return $this->belongsTo(DealerLink::class);
    }
}
