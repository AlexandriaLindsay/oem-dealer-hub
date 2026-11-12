<?php

namespace App\Models;

use App\Support\Plan;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

/**
 * A paying tenant. The manufacturer - not a person - is the Stripe customer
 * (Cashier's Billable), so the bill stays with the company when people
 * change. Where the account stands is App\Support\Plan::standing().
 */
class Manufacturer extends Model
{
    use Billable, HasFactory;

    protected $fillable = ['name', 'slug', 'plan', 'trial_ends_at', 'settings'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'logo_updated_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    /**
     * What their ERP is in charge of (docs/INTEGRATIONS.md, "Who owns what"):
     * 'order_status' (sales orders move only through the API) and 'prices'
     * (price lists change only through the API). Off unless they turn it on
     * under Integrations, and only while their plan includes ERP integration.
     */
    public const ERP_OWNABLE = ['order_status', 'prices'];

    public function erpOwns(string $what): bool
    {
        return ! empty($this->settings['erp_owns'][$what]) && Plan::includes($this, 'erp_integration');
    }

    /** @param  array<string, bool>  $owns */
    public function setErpOwns(array $owns): void
    {
        $settings = $this->settings ?? [];
        foreach (self::ERP_OWNABLE as $what) {
            if (array_key_exists($what, $owns)) {
                $settings['erp_owns'][$what] = (bool) $owns[$what];
            }
        }
        $this->update(['settings' => $settings]);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Its staff (memberships with org_type manufacturer). */
    public function staff(): HasMany
    {
        return $this->hasMany(Membership::class, 'org_id')->where('org_type', Membership::MANUFACTURER);
    }

    /**
     * Its dealer links. DealerLink keeps its tenant and territory scopes here
     * too: only the staff console lifts them (withoutGlobalScopes).
     */
    public function dealerLinks(): HasMany
    {
        return $this->hasMany(DealerLink::class);
    }

    /** The logo's address in the app, or null. The version changes with the logo, so caches never show the old one. */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? '/api/m/'.$this->slug.'/logo?v='.$this->logo_updated_at?->timestamp : null;
    }

    /** Stripe's receipts and invoices go to the account owner. */
    public function stripeEmail(): ?string
    {
        return $this->staff()->where('role', 'owner')->with('user')->first()?->user?->email;
    }

    public function stripeName(): ?string
    {
        return $this->name;
    }
}
