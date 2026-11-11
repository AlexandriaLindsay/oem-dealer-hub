<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One model a document covers, optionally only a serial range. Tenant-owned. */
class DocumentModel extends Model
{
    use BelongsToManufacturer;

    public $timestamps = false;

    protected $fillable = ['product_model_id', 'serial_from', 'serial_to'];

    public function productModel(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class);
    }

    /**
     * Whether $serial is in this link's range (inclusive, either end open).
     * Natural and case-insensitive, so "AS4-24100" comes after "AS4-9999".
     */
    public function covers(string $serial): bool
    {
        return ($this->serial_from === null || strnatcasecmp($serial, $this->serial_from) >= 0)
            && ($this->serial_to === null || strnatcasecmp($serial, $this->serial_to) <= 0);
    }

    /** "S/N AS4-24001 and up", "up to S/N ...", "S/N ... to ..." - or null for every serial. */
    public function rangeLabel(): ?string
    {
        return match (true) {
            $this->serial_from !== null && $this->serial_to !== null => "S/N {$this->serial_from} to {$this->serial_to}",
            $this->serial_from !== null => "S/N {$this->serial_from} and up",
            $this->serial_to !== null => "Up to S/N {$this->serial_to}",
            default => null,
        };
    }
}
