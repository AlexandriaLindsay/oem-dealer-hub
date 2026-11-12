<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Support\Features;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The price catalog: models and options with their prices.
 *
 * A dealer gets exactly one list - their own, or the default - and never
 * learns any other list exists, let alone its prices. They see only the
 * product lines they carry. Staff see the default,
 * or any list by ?price_list= when they manage pricing.
 */
class CatalogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        if ($tenant->isDealer()) {
            // Whatever ?price_list= says, a dealer gets their own list.
            $list = PriceList::forDealer($tenant->dealerLink());
        } else {
            $asked = $request->integer('price_list');
            $canPickList = Features::allows($tenant->manufacturer(), $tenant->membership(), 'manage_pricing');
            $list = ($asked && $canPickList ? PriceList::find($asked) : null) ?? PriceList::default();
        }

        if (! $list) {
            return response()->json(['price_list' => null, 'lines' => []]);
        }

        return response()->json([
            // Dealers see the currency, not the list's internal name.
            'price_list' => $tenant->isDealer()
                ? ['currency' => $list->currency]
                : ['id' => $list->id, 'name' => $list->name, 'currency' => $list->currency, 'is_default' => $list->is_default],
            // A dealer sees only the product lines they carry.
            'lines' => PriceListController::catalog($list, $tenant->isDealer() ? $tenant->dealerLink()->productLineIds() : null),
        ]);
    }
}
