<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DealerLink;
use App\Models\PriceList;
use App\Models\ProductModel;
use App\Models\ProductOption;
use Illuminate\Http\JsonResponse;

/**
 * What an ERP needs to match its records to ours (`api-key:catalog:read`):
 * every dealer with their customer number, every model and option with its
 * item number, and the price lists (for the prices endpoint).
 */
class CatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'dealers' => DealerLink::with('dealer')->get()->sortBy(fn (DealerLink $l) => $l->dealer->name)->map(fn (DealerLink $l) => [
                'id' => $l->id,
                'name' => $l->dealer->name,
                'dealer_code' => $l->dealer_code,
                'status' => $l->status,
            ])->values(),
            'models' => ProductModel::with(['productLine', 'options' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get()->map(fn (ProductModel $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'product_line' => $m->productLine->name,
                'erp_ref' => $m->erp_ref,
                'options' => $m->options->map(fn (ProductOption $o) => ['id' => $o->id, 'name' => $o->name, 'erp_ref' => $o->erp_ref])->values(),
            ])->values(),
            'price_lists' => PriceList::orderBy('name')->get()->map(fn (PriceList $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'currency' => $p->currency,
                'is_default' => (bool) $p->is_default,
            ])->values(),
        ]);
    }
}
