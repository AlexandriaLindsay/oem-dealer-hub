<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dealer business. Global, not tenant-owned: one dealer can carry several
 * manufacturers' brands, each through its own DealerLink. Only what the dealer
 * would put on its own website lives here - anything one manufacturer knows
 * about it goes on the link.
 */
class Dealer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'city', 'region', 'country', 'phone', 'website'];

    /**
     * The manufacturers it carries, one link each. DealerLink is tenant-owned,
     * so this needs a current manufacturer; only the staff console lifts the
     * scopes to see every link.
     */
    public function links(): HasMany
    {
        return $this->hasMany(DealerLink::class);
    }

    /** Its people (memberships with org_type dealer). */
    public function members(): HasMany
    {
        return $this->hasMany(Membership::class, 'org_id')->where('org_type', Membership::DEALER);
    }
}
