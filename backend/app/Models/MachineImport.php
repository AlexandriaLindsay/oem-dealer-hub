<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A machine import that was applied, with every row's outcome. Tenant-owned. */
class MachineImport extends Model
{
    use BelongsToManufacturer;

    protected $fillable = [
        'user_id', 'file_name', 'sha256', 'mode', 'rows_total',
        'created_count', 'updated_count', 'unchanged_count', 'skipped_count', 'failed_count', 'results',
    ];

    protected function casts(): array
    {
        return ['results' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
