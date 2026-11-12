<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\PriceSync;
use App\Models\PriceList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Their ERP sets prices on a price list (`api-key:prices:write`), by its own
 * item numbers. All or nothing (PriceSync, which the ERP connection uses
 * too): one unknown item or bad price and nothing changes, with every
 * problem listed. A null price takes the item off the list ("price on request").
 *
 * Works whether or not prices are set to come from their ERP; when they are,
 * this is the only way prices change.
 */
class PriceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'price_list' => ['required', 'integer'],
            'prices' => ['required', 'array', 'min:1', 'max:2000'],
            'prices.*.item' => ['required', 'string', 'max:100'],
            'prices.*.price' => ['present', 'nullable'],
        ]);
        $list = PriceList::find($data['price_list']);
        if (! $list) {
            return response()->json(['message' => 'No price list '.$data['price_list'].'. GET /api/v1/catalog lists them.', 'code' => 'not_found'], 404);
        }

        $outcome = PriceSync::apply($list, $data['prices']);
        if ($outcome['errors']) {
            throw ValidationException::withMessages($outcome['errors']);
        }

        return response()->json(['price_list' => $list->id, 'updated' => $outcome['updated']]);
    }
}
