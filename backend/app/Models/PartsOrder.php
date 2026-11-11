<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use App\Tenancy\VisibleToOwnDealer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dealer's parts order to the manufacturer's parts desk. Tenant-owned, and
 * a dealer only ever sees their own (VisibleToOwnDealer).
 *
 * submitted -> in_progress -> shipped, or cancelled (by the parts desk at any
 * point before shipping; by the dealer only while still submitted).
 */
class PartsOrder extends Model
{
    use BelongsToManufacturer, VisibleToOwnDealer;

    public const SUBMITTED = 'submitted';

    public const IN_PROGRESS = 'in_progress';

    public const SHIPPED = 'shipped';

    public const CANCELLED = 'cancelled';

    public const OPEN = [self::SUBMITTED, self::IN_PROGRESS];

    public const SHIP_METHODS = ['standard', 'rush', 'pickup'];

    /** What the parts desk can move an order to, from each status. */
    public const MOVES = [
        self::SUBMITTED => [self::IN_PROGRESS, self::SHIPPED, self::CANCELLED],
        self::IN_PROGRESS => [self::SHIPPED, self::CANCELLED],
        self::SHIPPED => [],
        self::CANCELLED => [],
    ];

    public const FIRST_NUMBER = 1001;

    protected $fillable = ['po_number', 'machine_serial', 'ship_method', 'ship_to', 'notes'];

    protected $attributes = ['status' => self::SUBMITTED];

    protected function casts(): array
    {
        return [
            'ship_to' => 'array',
            'started_at' => 'datetime',
            'shipped_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PartsOrderLine::class)->orderBy('position');
    }

    public function dealerLink(): BelongsTo
    {
        return $this->belongsTo(DealerLink::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function label(): string
    {
        return '#'.$this->number;
    }
}
