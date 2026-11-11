<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named group of a manufacturer's dealers, covered by some of its sales
 * staff (Pro). Tenant-owned. What it changes is in App\Support\Territories.
 */
class Territory extends Model
{
    use BelongsToManufacturer;

    /** The roles that can cover a territory - the people who work a patch of dealers. */
    public const REP_ROLES = ['sales_rep', 'inside_sales'];

    protected $fillable = ['name'];

    public function dealerLinks(): HasMany
    {
        return $this->hasMany(DealerLink::class);
    }

    /** The staff memberships covering this territory. Pivot rows carry the manufacturer too. */
    public function reps(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'territory_reps')->withPivot('manufacturer_id');
    }
}
