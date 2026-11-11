<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use App\Tenancy\Tenant;
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
    use BelongsToManufacturer, HasFactory;

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

    /**
     * A dealer only ever sees the machines they have now - never another
     * dealer's, even at the same manufacturer. A global scope rather than a
     * filter in each controller, so a forgotten filter can't leak a
     * competitor's stock.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('dealer', function (Builder $query) {
            $tenant = Tenant::current();
            if ($tenant->check() && $tenant->isDealer()) {
                $query->where($query->qualifyColumn('dealer_link_id'), $tenant->dealerLink()->id);
            }
        });
    }

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
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

    /** Adds a machine and its first history entry. */
    public static function add(array $attributes, ?DealerLink $link, ?User $by, ?string $note = null): self
    {
        return DB::transaction(function () use ($attributes, $link, $by, $note) {
            $machine = new self($attributes);
            $machine->status = $attributes['status'] ?? 'inventory';
            $machine->dealer_link_id = $link?->id;
            if ($link && ! $machine->shipped_at) {
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
