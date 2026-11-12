<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sync with their ERP, or one sales order sent to it: what changed and
 * what went wrong. Tenant-owned; kept 30 days (erp-runs:prune).
 */
class ErpSyncRun extends Model
{
    use BelongsToManufacturer;

    public const RUNNING = 'running';

    public const OK = 'ok';

    /** Finished, but some rows couldn't be used (listed in problems). */
    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    /** The first problems are kept, enough to fix a mapping without storing a copy of their ERP. */
    public const MAX_PROBLEMS = 25;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'problems' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}
