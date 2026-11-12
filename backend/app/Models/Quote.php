<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use App\Tenancy\Tenant;
use App\Tenancy\VisibleToOwnDealer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A dealer's quote to a customer. Tenant-owned, and a dealer only ever sees
 * their own (VisibleToOwnDealer); the manufacturer's sales staff can read them.
 *
 * draft -> sent (the dealer), then sent -> won | lost (the manufacturer).
 * Only a draft can be changed; a sent quote can be reopened as a draft, or
 * copied to a new one. Each side sees only its part (visibleStatuses).
 */
class Quote extends Model
{
    use BelongsToManufacturer, VisibleToOwnDealer;

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const WON = 'won';

    public const LOST = 'lost';

    public const STATUSES = [self::DRAFT, self::SENT, self::WON, self::LOST];

    /**
     * What the dealer can do: send a draft, or take a sent one back to fix
     * something. Won or lost is the manufacturer's call (OUTCOMES), not the dealer's.
     */
    public const MOVES = [
        self::DRAFT => [self::SENT],
        self::SENT => [self::DRAFT],
        self::WON => [],
        self::LOST => [],
    ];

    /** How the manufacturer closes a sent quote. */
    public const OUTCOMES = [self::WON, self::LOST];

    /**
     * Who sees what. A dealer sees the quotes they're working on (drafts and
     * sent); once the manufacturer closes one it leaves the dealer's view.
     * The manufacturer never sees a dealer's drafts - only quotes that went to
     * a customer, and how they ended.
     */
    public const DEALER_SEES = [self::DRAFT, self::SENT];

    public const MANUFACTURER_SEES = [self::SENT, self::WON, self::LOST];

    /** @return list<string> the statuses the signed-in person can see. */
    public static function visibleStatuses(): array
    {
        return Tenant::current()->isDealer() ? self::DEALER_SEES : self::MANUFACTURER_SEES;
    }

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

    /** The order a won quote became. */
    public function salesOrder(): HasOne
    {
        return $this->hasOne(SalesOrder::class);
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
