<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a machine's history. Written only by Machine, never edited -
 * the history is the record of what happened.
 */
class MachineEvent extends Model
{
    use BelongsToManufacturer;

    public const UPDATED_AT = null;

    protected $fillable = ['type', 'from', 'to', 'user_id', 'note'];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
