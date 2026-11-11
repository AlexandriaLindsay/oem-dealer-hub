<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use App\Tenancy\VisibleToOwnDealer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One physical machine, by serial number. Tenant-owned.
 *
 * dealer_link_id is the dealer it's with now (null: still at the
 * manufacturer). Change status and dealer only through changeStatus() and
 * transferTo(), so every change lands in the history (machine_events).
 */
class Machine extends Model
{
    // A dealer only ever sees the machines they have now (VisibleToOwnDealer).
    use BelongsToManufacturer, HasFactory, VisibleToOwnDealer;

    /** In order of a machine's usual life at a dealer. */
    public const STATUSES = ['inventory', 'demo', 'rental', 'sold', 'used'];

    protected $fillable = ['product_model_id', 'serial_no', 'production_no', 'built_at', 'shipped_at'];

    protected function casts(): array
    {
        return [
            'built_at' => 'date',
            'shipped_at' => 'date',
        ];
    }

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
    }

    /** A rep limited to territories still sees the factory's own stock - it's what they sell to their dealers. */
    protected static function territoryRepsSeeRowsWithoutDealer(): bool
    {
        return true;
    }

    public function dealerLink(): BelongsTo
    {
        return $this->belongsTo(DealerLink::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MachineEvent::class)->orderByDesc('id');
    }

    /** Validation rules for the filters filter() takes - the machine list, fleet report and export share them. */
    public static function filterRules(): array
    {
        return [
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'line' => ['nullable', 'integer'],
            'model' => ['nullable', 'integer'],
            // A dealer link id, or "none" for machines still at the manufacturer.
            'dealer' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * The machine list's filters, in one place, so the list, the fleet report
     * and the export always agree on what "these machines" means.
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['model'] ?? null, fn ($q, $model) => $q->where('product_model_id', $model))
            // Through ProductModel, so its own tenant scope applies to the lookup too.
            ->when($filters['line'] ?? null, fn ($q, $line) => $q->whereIn('product_model_id', ProductModel::where('product_line_id', $line)->select('id')))
            ->when($filters['dealer'] ?? null, fn ($q, $dealer) => $dealer === 'none'
                ? $q->whereNull('dealer_link_id')
                : $q->where('dealer_link_id', (int) $dealer))
            ->when($filters['q'] ?? null, function ($q, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('serial_no', 'like', $like)->orWhere('production_no', 'like', $like));
            });
    }

    /**
     * Adds a machine and its first history entry.
     *
     * $stampShipped: a machine added straight to a dealer is marked shipped
     * today - unless it's history being imported, where "today" would be wrong
     * and an unknown date stays unknown.
     */
    public static function add(array $attributes, ?DealerLink $link, ?User $by, ?string $note = null, bool $stampShipped = true): self
    {
        return DB::transaction(function () use ($attributes, $link, $by, $note, $stampShipped) {
            $machine = new self($attributes);
            $machine->status = $attributes['status'] ?? 'inventory';
            $machine->dealer_link_id = $link?->id;
            if ($link && $stampShipped && ! $machine->shipped_at) {
                $machine->shipped_at = now();
            }
            $machine->save();

            $machine->record('created', null, $link?->id, $by, $note);

            return $machine;
        });
    }

    public function changeStatus(string $to, ?User $by, ?string $note = null): void
    {
        if ($to === $this->status) {
            return;
        }

        DB::transaction(function () use ($to, $by, $note) {
            $from = $this->status;
            $this->status = $to;
            $this->save();
            $this->record('status', $from, $to, $by, $note);
        });
    }

    /** Moves the machine to $link's dealer, or back to the manufacturer when $link is null. */
    public function transferTo(?DealerLink $link, ?User $by, ?string $note = null): void
    {
        if ($link?->id === $this->dealer_link_id) {
            return;
        }

        DB::transaction(function () use ($link, $by, $note) {
            $from = $this->dealer_link_id;
            $this->dealer_link_id = $link?->id;
            // The first time it leaves the manufacturer.
            if ($link && ! $this->shipped_at) {
                $this->shipped_at = now();
            }
            $this->save();
            $this->record('transfer', $from, $link?->id, $by, $note);
        });
    }

    /**
     * An approved registration: the machine is now sold (or out on rental) to
     * $endUser. One history entry - "registration", from the old status to the
     * registration type - rather than a plain status change, so the history
     * shows it was registered, not just relabelled.
     */
    public function registered(Registration $registration, EndUser $endUser, ?User $by): void
    {
        DB::transaction(function () use ($registration, $endUser, $by) {
            $from = $this->status;
            $this->status = $registration->type === 'sale' ? 'sold' : 'rental';
            $this->end_user_id = $endUser->id;
            $this->save();
            $this->record('registration', $from, $registration->type, $by, null);
        });
    }

    public function endUser(): BelongsTo
    {
        return $this->belongsTo(EndUser::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class)->orderByDesc('id');
    }

    private function record(string $type, string|int|null $from, string|int|null $to, ?User $by, ?string $note): void
    {
        $this->events()->create([
            'type' => $type,
            'from' => $from === null ? null : (string) $from,
            'to' => $to === null ? null : (string) $to,
            'user_id' => $by?->id,
            'note' => $note,
        ]);
    }
}
