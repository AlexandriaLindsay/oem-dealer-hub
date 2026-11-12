<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One call to a webhook: what was sent and how it went. Tenant-owned; kept 30 days. */
class WebhookDelivery extends Model
{
    use BelongsToManufacturer;

    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    /** How many times a call is tried before it's marked failed (the first try plus retries). */
    public const MAX_ATTEMPTS = 6;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
        ];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
