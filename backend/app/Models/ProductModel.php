<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A model within a product line (e.g. "S-400" in "Scrapers"). Tenant-owned. */
class ProductModel extends Model
{
    use BelongsToManufacturer, HasFactory;

    protected $fillable = ['product_line_id', 'name', 'category', 'specs', 'erp_ref'];

    protected function casts(): array
    {
        return ['specs' => 'array'];
    }

    public function productLine(): BelongsTo
    {
        return $this->belongsTo(ProductLine::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('name');
    }

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }

    /** What may follow a model's ERP number in a longer item code: "1233A" covers "1233A_2001_SS". */
    public const ITEM_CODE_SEPARATORS = ['_', '-', '.', '/', ' '];

    /**
     * The model an ERP item code belongs to. Many ERPs give each build its own
     * item code (model, then configuration: "1233A_2001_SS", "1233A_2101_ADT_NECK"),
     * so a model's ERP number also covers codes that start with it and then a
     * separator. An exact match wins, then the longest match, so "1233A" never
     * takes "1233AB_…" and "1233A_21" beats "1233A" for "1233A_21_X".
     * Prices don't use this: each build has its own price, matched exactly.
     */
    public static function forItemCode(string $code): ?self
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        if ($exact = self::where('erp_ref', $code)->first()) {
            return $exact;
        }

        // A manufacturer has tens or hundreds of models, so checking them here is cheap
        // and works the same on every database (no LIKE against a column).
        $lower = mb_strtolower($code);

        return self::whereNotNull('erp_ref')->where('erp_ref', '!=', '')->get()
            ->filter(function (self $m) use ($lower) {
                $ref = mb_strtolower($m->erp_ref);

                return mb_strlen($lower) > mb_strlen($ref)
                    && str_starts_with($lower, $ref)
                    && in_array(mb_substr($lower, mb_strlen($ref), 1), self::ITEM_CODE_SEPARATORS, true);
            })
            ->sortByDesc(fn (self $m) => mb_strlen($m->erp_ref))
            ->first();
    }
}
