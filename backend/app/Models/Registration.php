<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use App\Tenancy\VisibleToOwnDealer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A dealer reporting a machine sold or rented out. Starts pending; the
 * manufacturer approves it (the machine becomes sold or rental, and the
 * customer becomes an end user) or rejects it with a reason.
 *
 * Tenant-owned, and a dealer only ever sees their own (VisibleToOwnDealer) -
 * a registration holds a customer's name and address.
 */
class Registration extends Model
{
    use BelongsToManufacturer, VisibleToOwnDealer;

    public const TYPES = ['sale', 'rental'];

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = ['machine_id', 'dealer_link_id', 'type', 'delivered_on', 'customer', 'notes', 'submitted_by'];

    /** Every registration starts waiting for review (also set in memory, not only by the column default). */
    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'delivered_on' => 'date',
            'customer' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function dealerLink(): BelongsTo
    {
        return $this->belongsTo(DealerLink::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RegistrationDocument::class)->orderBy('id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function endUser(): BelongsTo
    {
        return $this->belongsTo(EndUser::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** The machine becomes sold (or rental), and the customer an end user. */
    public function approve(User $by): void
    {
        DB::transaction(function () use ($by) {
            $this->assertStillPending();

            $machine = $this->machine()->lockForUpdate()->firstOrFail();
            // It moved to another dealer while this waited - that dealer can't
            // have sold it. Reject and let the right dealer register it.
            if ($machine->dealer_link_id !== $this->dealer_link_id) {
                throw ValidationException::withMessages(['registration' => 'This machine has moved to another dealer since it was registered, so it can\'t be approved. Reject it instead.']);
            }

            $endUser = EndUser::fromCustomer($this->customer);
            $machine->registered($this, $endUser, $by);

            $this->status = self::APPROVED;
            $this->end_user_id = $endUser->id;
            $this->reviewed_by = $by->id;
            $this->reviewed_at = now();
            $this->save();
        });
    }

    public function reject(User $by, string $reason): void
    {
        DB::transaction(function () use ($by, $reason) {
            $this->assertStillPending();

            $this->status = self::REJECTED;
            $this->rejection_reason = $reason;
            $this->reviewed_by = $by->id;
            $this->reviewed_at = now();
            $this->save();
        });
    }

    /** Read again under a lock: two reviewers clicking at once can't both decide. */
    private function assertStillPending(): void
    {
        $current = self::whereKey($this->id)->lockForUpdate()->value('status');
        if ($current !== self::PENDING) {
            throw ValidationException::withMessages(['registration' => 'This registration has already been '.$current.'.']);
        }
    }
}
