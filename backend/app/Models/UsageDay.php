<?php

namespace App\Models;

use App\Tenancy\BelongsToManufacturer;
use Illuminate\Database\Eloquent\Model;

/**
 * How many times one person opened one area of OEM Dealer Hub on one day - the
 * whole of what the usage report is built from. Tenant-owned. Deleted after
 * 13 months (usage:prune). What's kept is described in the privacy policy.
 */
class UsageDay extends Model
{
    use BelongsToManufacturer;

    /**
     * The areas that count, as the app names them (the first part of the
     * page's address), each with the feature that puts it in the person's
     * menu. A visit only counts if the person can use that area, so the
     * report never shows someone "using" a page they can't open. Staff,
     * settings, billing and this report aren't "using OEM Dealer Hub".
     */
    public const AREAS = [
        'dashboard' => 'dashboard',
        'machines' => 'machines',
        'registrations' => 'registrations',
        'fleet' => 'fleet_report',
        'documents' => 'documents',
        'prices' => 'catalog',
        'price-lists' => 'manage_pricing',
        'quotes' => 'quotes',
        'parts-orders' => 'parts_orders',
        'announcements' => 'announcements',
        'broadcasts' => 'broadcasts',
        'products' => 'products',
        'dealers' => 'dealers',
    ];

    public $timestamps = false;

    protected $fillable = ['user_id', 'dealer_link_id', 'day', 'area', 'count'];

    protected function casts(): array
    {
        return ['day' => 'date'];
    }
}
