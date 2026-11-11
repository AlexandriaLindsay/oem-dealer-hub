<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file that goes with a registration. The file is on the documents disk at `path`. */
class RegistrationDocument extends Model
{
    use BelongsToManufacturer;

    public const KINDS = ['bill_of_sale', 'pdi', 'other'];

    protected $fillable = ['kind', 'original_name', 'path', 'mime', 'size', 'sha256', 'scan_status', 'uploaded_by'];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
