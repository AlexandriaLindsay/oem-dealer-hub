<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use App\Tenancy\VisibleToOwnDealer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dealer's stock check (App\Support\StockChecks): "are these machines
 * really on your lot?" Tenant-owned, and a dealer only sees their own.
 *
 * open -> done (every machine answered) or expired (not finished by the due
 * date - what wasn't answered stays unconfirmed).
 */
class StockCheck extends Model
{
    use BelongsToManufacturer, VisibleToOwnDealer;

    public const OPEN = 'open';

    public const DONE = 'done';

    public const EXPIRED = 'expired';

    /** How long a dealer has to answer. */
    public const DAYS_TO_ANSWER = 14;

    protected $fillable = [];

    protected $attributes = ['status' => self::OPEN];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCheckItem::class);
    }

    public function dealerLink(): BelongsTo
    {
        return $this->belongsTo(DealerLink::class);
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN && $this->due_on->endOfDay()->isFuture();
    }
}
