<?php

namespace App\Models;

use App\Support\Plan;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A paying tenant. */
class Manufacturer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'plan', 'trial_ends_at', 'settings'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Its staff (memberships with org_type manufacturer). */
    public function staff(): HasMany
    {
        return $this->hasMany(Membership::class, 'org_id')->where('org_type', Membership::MANUFACTURER);
    }

    public function onTrial(): bool
    {
        return $this->plan === Plan::TRIAL && $this->trial_ends_at?->isFuture();
    }
}
