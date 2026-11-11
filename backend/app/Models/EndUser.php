<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A manufacturer's customer - whoever bought or rented a machine. Created when
 * a registration is approved. Tenant-owned: one manufacturer's customer list
 * is never visible to another.
 */
class EndUser extends Model
{
    use BelongsToManufacturer;

    public const FIELDS = ['company', 'contact_name', 'email', 'phone', 'address', 'city', 'region', 'postal_code', 'country'];

    protected $fillable = self::FIELDS;

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }

    /**
     * The customer from a registration: the same email at this manufacturer
     * is the same customer (their details stay as first entered); otherwise a
     * new one.
     */
    public static function fromCustomer(array $customer): self
    {
        $email = isset($customer['email']) ? mb_strtolower(trim($customer['email'])) : null;
        $fields = array_intersect_key($customer, array_flip(self::FIELDS));

        if ($email) {
            return self::firstOrCreate(['email' => $email], ['email' => $email] + $fields);
        }

        return self::create($fields);
    }
}
