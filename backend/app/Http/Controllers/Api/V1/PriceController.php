<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Their ERP sets prices on a price list (`api-key:prices:write`), by its own
 * item numbers (a model's or option's ERP number). All or nothing, like the
 * price list page: one unknown item or bad price and nothing changes, with
 * every problem listed. A null price takes the item off the list ("price on
 * request"). Only the items sent are touched.
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

        $refs = collect($data['prices'])->pluck('item')->unique()->values();
        $models = ProductModel::whereIn('erp_ref', $refs)->get(['id', 'erp_ref'])->groupBy('erp_ref');
        $options = ProductOption::whereIn('erp_ref', $refs)->get(['id', 'erp_ref'])->groupBy('erp_ref');

        $errors = [];
        $changes = [];
        foreach ($data['prices'] as $i => $row) {
            $ref = $row['item'];
            $matches = collect($models->get($ref, []))->map(fn ($m) => [PriceListItem::MODEL, $m->id])
                ->concat(collect($options->get($ref, []))->map(fn ($o) => [PriceListItem::OPTION, $o->id]));
            if ($matches->isEmpty()) {
                $errors["prices.$i.item"] = "No model or option has item number \"$ref\".";

                continue;
            }
            if ($matches->count() > 1) {
                $errors["prices.$i.item"] = "More than one model or option has item number \"$ref\". Make them unique under Integrations.";

                continue;
            }
            $text = trim((string) ($row['price'] ?? ''));
            $cents = $text === '' ? null : Money::toCents($text);
            if ($text !== '' && $cents === null) {
                $errors["prices.$i.price"] = "\"$text\" isn't a price. Send a number like \"1234.50\".";

                continue;
            }
            [$type, $id] = $matches->first();
            $changes[] = [$type, $id, $cents];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($list, $changes) {
            foreach ($changes as [$type, $id, $cents]) {
                if ($cents === null) {
                    $list->items()->where(['item_type' => $type, 'item_id' => $id])->delete();
                } else {
                    $list->items()->updateOrCreate(['item_type' => $type, 'item_id' => $id], ['price_cents' => $cents]);
                }
            }
            $list->touch();
        });

        return response()->json(['price_list' => $list->id, 'updated' => count($changes)]);
    }
}
