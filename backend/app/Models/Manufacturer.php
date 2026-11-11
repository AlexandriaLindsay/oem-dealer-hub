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
            'logo_updated_at' => 'datetime',
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

    /** The logo's address in the app, or null. The version changes with the logo, so caches never show the old one. */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? '/api/m/'.$this->slug.'/logo?v='.$this->logo_updated_at?->timestamp : null;
    }

    public function onTrial(): bool
    {
        return $this->plan === Plan::TRIAL && $this->trial_ends_at?->isFuture();
    }
}
