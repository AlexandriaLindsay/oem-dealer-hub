<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An announcement from a manufacturer to its dealers. Tenant-owned.
 *
 * Recipients are fixed when it's sent (broadcast_recipients). A dealer only
 * ever sees broadcasts sent to them that haven't been withdrawn - a global
 * scope, like the dealer scopes, so no controller can forget it.
 */
class Broadcast extends Model
{
    use BelongsToManufacturer;

    public const ALL = 'all';

    public const SELECTED = 'selected';

    protected $fillable = ['title', 'body', 'audience', 'emailed'];

    protected function casts(): array
    {
        return ['emailed' => 'boolean', 'withdrawn_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('dealer', function (Builder $query) {
            $tenant = Tenant::current();
            if ($tenant->check() && $tenant->isDealer()) {
                $query->whereNull($query->qualifyColumn('withdrawn_at'))
                    ->whereHas('recipients', fn ($r) => $r->where('dealer_link_id', $tenant->dealerLink()->id));
            }
        });
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(BroadcastRead::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
