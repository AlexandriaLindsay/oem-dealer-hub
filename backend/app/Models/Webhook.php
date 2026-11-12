<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An address of the manufacturer's that OEM Dealer Hub calls when something
 * happens (App\Integrations\Webhooks). Each call is signed with the secret,
 * so they can check it really came from us. Tenant-owned.
 */
class Webhook extends Model
{
    use BelongsToManufacturer;

    /** What can be sent, and how it's described in Settings. "ping" is the test button. */
    public const EVENTS = [
        'sales_order.created' => 'A sales order is made (a quote is marked won)',
        'sales_order.status_changed' => 'A sales order moves (into production, shipped, delivered, cancelled)',
        'sales_order.updated' => 'A sales order\'s details change (PO, wanted-by date, notes, ERP number)',
    ];

    protected $fillable = ['url', 'events', 'active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'active' => 'boolean',
        ];
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function wants(string $event): bool
    {
        return $this->active && in_array($event, $this->events ?? [], true);
    }
}
