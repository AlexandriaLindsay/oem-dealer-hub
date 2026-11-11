<?php

namespace App\Tenancy;

use App\Models\Manufacturer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every tenant-owned model (one with manufacturer_id).
 *
 * - Every query is limited to the current manufacturer. With none set it
 *   throws (MissingTenantException) - it fails closed, never open.
 * - New rows get the current manufacturer's id, and a row can't be saved into
 *   a different manufacturer than the current one.
 *
 * Platform staff tools that genuinely need every manufacturer use
 * Model::withoutTenant().
 */
trait BelongsToManufacturer
{
    public static function bootBelongsToManufacturer(): void
    {
        static::addGlobalScope('manufacturer', function (Builder $query) {
            $query->where($query->qualifyColumn('manufacturer_id'), Tenant::current()->id());
        });

        static::saving(function ($model) {
            $tenantId = Tenant::current()->id();

            if ($model->manufacturer_id === null) {
                $model->manufacturer_id = $tenantId;
            } elseif ((int) $model->manufacturer_id !== $tenantId) {
                throw new \LogicException(static::class.' belongs to manufacturer '.$model->manufacturer_id.', not the current one ('.$tenantId.').');
            }
        });
    }

    public static function withoutTenant(): Builder
    {
        return static::withoutGlobalScope('manufacturer');
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }
}
