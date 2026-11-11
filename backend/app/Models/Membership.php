<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's place in one org: a manufacturer's staff member, or one of a
 * dealer's people. Carries their role and per-user feature overrides
 * (App\Support\Features).
 */
class Membership extends Model
{
    use HasFactory;

    public const MANUFACTURER = 'manufacturer';

    public const DEALER = 'dealer';

    protected $fillable = ['user_id', 'org_type', 'org_id', 'role', 'feature_overrides'];

    protected function casts(): array
    {
        return ['feature_overrides' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'org_id');
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class, 'org_id');
    }

    public function isDealer(): bool
    {
        return $this->org_type === self::DEALER;
    }
}
