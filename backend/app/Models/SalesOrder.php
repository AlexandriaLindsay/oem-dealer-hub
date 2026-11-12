<?php

namespace App\Models;

use App\Integrations\ErpSync;
use App\Integrations\SalesOrderPayload;
use App\Integrations\Webhooks;
use App\Tenancy\BelongsToManufacturer;
use App\Tenancy\VisibleToOwnDealer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a won quote becomes: the manufacturer's order to build and ship it.
 * Tenant-owned and tied to the quote's dealer (VisibleToOwnDealer). Only
 * manufacturer staff see sales orders - dealers stop seeing a quote once it's
 * decided, and the same goes for the order it turns into.
 *
 * received -> in_production -> shipped -> delivered, or cancelled any time
 * before it ships (with a reason). A unit already in stock can go straight
 * from received to shipped.
 */
class SalesOrder extends Model
{
    use BelongsToManufacturer, VisibleToOwnDealer;

    public const RECEIVED = 'received';

    public const IN_PRODUCTION = 'in_production';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::RECEIVED, self::IN_PRODUCTION, self::SHIPPED, self::DELIVERED, self::CANCELLED];

    /** Not finished yet: still to build or ship. */
    public const OPEN = [self::RECEIVED, self::IN_PRODUCTION];

    /** What an order can move to, from each status. */
    public const MOVES = [
        self::RECEIVED => [self::IN_PRODUCTION, self::SHIPPED, self::CANCELLED],
        self::IN_PRODUCTION => [self::SHIPPED, self::CANCELLED],
        self::SHIPPED => [self::DELIVERED],
        self::DELIVERED => [],
        self::CANCELLED => [],
    ];

    /** Numbers start here, so the first order doesn't look like #1. */
    public const FIRST_NUMBER = 1001;

    /** What staff can fill in after the order is made; the rest comes from the quote. */
    protected $fillable = ['po_number', 'requested_date', 'notes', 'erp_ref'];

    protected $attributes = ['status' => self::RECEIVED];

    protected function casts(): array
    {
        return [
            'customer' => 'array',
            'requested_date' => 'date',
            'started_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * The sales order for a quote that's just been won: its customer, lines
     * and totals copied, numbered next at the manufacturer. Call it inside
     * the transaction that marks the quote won, so neither happens alone.
     */
    public static function fromQuote(Quote $quote, ?User $by): self
    {
        // The manufacturer's next number; the lock stops two orders sharing one.
        DB::table('manufacturers')->where('id', $quote->manufacturer_id)->lockForUpdate()->first();
        $next = max(self::FIRST_NUMBER - 1, (int) self::withoutGlobalScope('dealer')->where('manufacturer_id', $quote->manufacturer_id)->max('number')) + 1;

        $order = new self;
        $order->forceFill([
            'manufacturer_id' => $quote->manufacturer_id,
            'dealer_link_id' => $quote->dealer_link_id,
            'quote_id' => $quote->id,
            'number' => $next,
            'currency' => $quote->currency,
            'customer' => $quote->customer,
            'subtotal_cents' => $quote->subtotal_cents,
            'discount_cents' => $quote->discount_cents,
            'total_cents' => $quote->total_cents,
            'created_by' => $by?->id,
        ])->save();

        foreach ($quote->lines()->get() as $line) {
            // Through the relation, so sales_order_id is set (it isn't fillable).
            $order->lines()->create($line->only(['position', 'kind', 'item_id', 'description', 'quantity', 'unit_price_cents', 'line_total_cents']));
        }

        // Their ERP hears about it once the transaction commits (Webhooks queues after commit).
        Webhooks::send('sales_order.created', SalesOrderPayload::make($order->fresh()));
        // And straight into it, if they've connected OEM Dealer Hub to their ERP's API.
        ErpSync::queuePush($order);

        return $order;
    }

    /**
     * Moves the order on (MOVES), stamping when, and tells their systems.
     * The one way an order moves - from the board and from their ERP alike.
     * Cancelling needs a reason, for whoever reads it later.
     *
     * @throws ValidationException
     */
    public function moveTo(string $to, ?User $by, ?string $cancelReason = null): void
    {
        if (! in_array($to, self::MOVES[$this->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'A '.str_replace('_', ' ', $this->status).' order can\'t be marked '.str_replace('_', ' ', $to).'.']);
        }
        if ($to === self::CANCELLED && trim((string) $cancelReason) === '') {
            throw ValidationException::withMessages(['cancel_reason' => 'Say why it was cancelled.']);
        }

        $this->forceFill(array_filter([
            'status' => $to,
            'handled_by' => $by?->id,
            'started_at' => $to === self::IN_PRODUCTION ? now() : null,
            'shipped_at' => $to === self::SHIPPED ? now() : null,
            'delivered_at' => $to === self::DELIVERED ? now() : null,
            'cancelled_at' => $to === self::CANCELLED ? now() : null,
            'cancel_reason' => $to === self::CANCELLED ? trim((string) $cancelReason) : null,
        ], fn ($v) => $v !== null))->save();

        Webhooks::send('sales_order.status_changed', SalesOrderPayload::make($this->fresh()));
    }

    /**
     * Changes the details filled in after it's made (fillable: PO, wanted-by
     * date, notes, ERP number), and tells their systems if anything changed.
     *
     * @param  array<string, mixed>  $details
     *
     * @throws ValidationException
     */
    public function updateDetails(array $details): void
    {
        // A finished order's details are final - except its ERP number, which an ERP may only assign late.
        if ($this->isFinished() && array_diff(array_keys($details), ['erp_ref']) !== []) {
            throw ValidationException::withMessages(['status' => 'A '.$this->status.' order can\'t be changed.']);
        }

        $this->update($details);
        if ($this->wasChanged()) {
            Webhooks::send('sales_order.updated', SalesOrderPayload::make($this->fresh()));
        }
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('position');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function dealerLink(): BelongsTo
    {
        return $this->belongsTo(DealerLink::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function label(): string
    {
        return 'SO-'.$this->number;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::DELIVERED, self::CANCELLED], true);
    }
}
