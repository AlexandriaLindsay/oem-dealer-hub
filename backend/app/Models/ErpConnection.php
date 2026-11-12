<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;

/**
 * How OEM Dealer Hub reaches a manufacturer's ERP API (App\Integrations\ErpSync).
 * One per manufacturer. The token is encrypted and never sent back to the
 * browser. Tenant-owned.
 *
 * `syncs` holds each sync's settings: whether it's on, the path to call, and
 * where things are in the ERP's JSON (dot paths, e.g. "data.items" or
 * "Customer.No"). See SYNCS and docs/INTEGRATIONS.md.
 */
class ErpConnection extends Model
{
    use BelongsToManufacturer;

    /** The syncs and what each does, as the Integrations page describes them. */
    public const SYNCS = [
        'orders_push' => 'Send each new sales order to your ERP',
        'order_status' => 'Fetch sales order status from your ERP',
        'prices' => 'Fetch prices from your ERP',
        'machines' => 'Fetch machines and serial numbers from your ERP',
    ];

    /** The ones we fetch on a schedule (orders_push goes out when an order is made). */
    public const PULLS = ['order_status', 'prices', 'machines'];

    /** Our fields each pull can read, and which must be mapped. */
    public const FIELDS = [
        'order_status' => ['order' => true, 'status' => true, 'erp_ref' => false, 'cancel_reason' => false],
        'prices' => ['item' => true, 'price' => true],
        'machines' => ['serial_no' => true, 'model' => false, 'model_name' => false, 'dealer_code' => false, 'status' => false, 'production_no' => false, 'built_at' => false, 'shipped_at' => false],
    ];

    protected $fillable = ['base_url', 'auth_header', 'auth_prefix', 'syncs', 'active'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'syncs' => 'array',
            'state' => 'array',
            'active' => 'boolean',
        ];
    }

    /** One sync's settings, or an empty array. */
    public function sync(string $name): array
    {
        return $this->syncs[$name] ?? [];
    }

    public function syncOn(string $name): bool
    {
        return $this->active && ! empty($this->sync($name)['on']);
    }

    /** Where a pull got to last time (ISO 8601), or null to fetch everything. */
    public function since(string $sync): ?string
    {
        return $this->state[$sync]['since'] ?? null;
    }

    public function setSince(string $sync, ?string $since): void
    {
        $state = $this->state ?? [];
        $state[$sync]['since'] = $since;
        $this->forceFill(['state' => $state])->save();
    }
}
