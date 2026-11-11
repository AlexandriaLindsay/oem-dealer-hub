<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A library document - a manual, parts book, service bulletin. Tenant-owned.
 *
 * `audience` is who can see it: everyone (staff and dealers) or staff only.
 * Dealers never see staff-only documents - a global scope, like the dealer
 * scopes, so a forgotten filter can't leak an internal bulletin.
 */
class Document extends Model
{
    use BelongsToManufacturer;

    public const KINDS = ['manual', 'parts_book', 'bulletin', 'brochure', 'other'];

    public const EVERYONE = 'everyone';

    public const STAFF = 'staff';

    protected $fillable = ['title', 'kind', 'description', 'audience', 'original_name', 'path', 'mime', 'size', 'sha256', 'scan_status', 'uploaded_by'];

    protected $attributes = ['audience' => self::EVERYONE];

    protected static function booted(): void
    {
        static::addGlobalScope('audience', function (Builder $query) {
            $tenant = Tenant::current();
            if ($tenant->check() && $tenant->isDealer()) {
                $query->where($query->qualifyColumn('audience'), self::EVERYONE);
            }
        });
    }

    public function models(): HasMany
    {
        return $this->hasMany(DocumentModel::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Every document that covers $machine: linked to its model with its serial
     * in range, or linked to no model at all (covers everything). The range
     * check runs in PHP with a natural comparison - serial formats differ per
     * manufacturer, and a model has few documents.
     *
     * @return Collection<int, Document>
     */
    public static function forMachine(Machine $machine): Collection
    {
        return self::with('models.productModel')
            ->where(fn ($q) => $q
                ->whereHas('models', fn ($m) => $m->where('product_model_id', $machine->product_model_id))
                ->orWhereDoesntHave('models'))
            ->orderBy('kind')
            ->orderBy('title')
            ->get()
            ->filter(fn (Document $d) => $d->models->isEmpty()
                || $d->models->contains(fn (DocumentModel $l) => (int) $l->product_model_id === (int) $machine->product_model_id && $l->covers($machine->serial_no)))
            ->values();
    }
}
