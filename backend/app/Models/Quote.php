<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use App\Tenancy\VisibleToOwnDealer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dealer's quote to a customer. Tenant-owned, and a dealer only ever sees
 * their own (VisibleToOwnDealer); the manufacturer's sales staff can read them.
 *
 * draft -> sent -> won | lost. Only a draft can be changed; a sent quote can
 * be reopened as a draft, or copied to a new one.
 */
class Quote extends Model
{
    use BelongsToManufacturer, VisibleToOwnDealer;

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const WON = 'won';

    public const LOST = 'lost';

    /** Which status can follow which. */
    public const MOVES = [
        self::DRAFT => [self::SENT],
        self::SENT => [self::DRAFT, self::WON, self::LOST],
        self::WON => [],
        self::LOST => [],
    ];

    /** Dealers' quote numbers start here, so the first doesn't look like #1. */
    public const FIRST_NUMBER = 1001;

    protected $fillable = ['customer', 'notes', 'valid_until'];

    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return [
            'customer' => 'array',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('position');
    }

    public function dealerLink(): BelongsTo
    {
        return $this->belongsTo(DealerLink::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function label(): string
    {
        return 'Q-'.$this->number;
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** Sent, and past its valid-until date. */
    public function isExpired(): bool
    {
        return $this->status === self::SENT && $this->valid_until?->endOfDay()->isPast();
    }
}
