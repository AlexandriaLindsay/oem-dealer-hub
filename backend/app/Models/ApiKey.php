<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A key a manufacturer's own system (their ERP) uses to read from OEM Dealer
 * Hub's API (/api/v1). Tenant-owned: a key only ever sees its own
 * manufacturer. Only a hash is stored - the key is shown once, when made.
 */
class ApiKey extends Model
{
    use BelongsToManufacturer;

    /** What a key can be allowed to do, and how it's described in Settings. */
    public const ABILITIES = [
        'sales_orders:read' => 'Read sales orders',
        'sales_orders:write' => 'Update sales orders (status, ERP number, PO, wanted-by date)',
        'catalog:read' => 'Read dealers, models, options and price lists (to match numbers)',
        'machines:write' => 'Add and update machines (serial numbers, dealer, status)',
        'prices:write' => 'Set prices on price lists',
    ];

    protected $fillable = ['name', 'abilities'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * A new key: the model (unsaved) and the key itself, which is never stored.
     *
     * @param  list<string>  $abilities
     * @return array{0: self, 1: string}
     */
    public static function generate(string $name, array $abilities, ?User $by): array
    {
        $plain = 'odh_'.Str::random(40);
        $key = new self(['name' => $name, 'abilities' => array_values($abilities)]);
        $key->forceFill(['prefix' => substr($plain, 0, 12), 'key_hash' => self::hash($plain), 'created_by' => $by?->id]);

        return [$key, $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
