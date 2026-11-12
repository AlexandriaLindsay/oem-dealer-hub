<?php

namespace App\Integrations;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Prices from a manufacturer's ERP onto one price list, by the ERP's own item
 * numbers (a model's or option's ERP number). Shared by the integration API
 * (POST /api/v1/prices) and the ERP connection (we fetch).
 *
 * All or nothing, like the price list page: a bad price or an item number
 * that matches two things, and nothing changes, with every problem listed.
 * A null or empty price takes the item off the list ("price on request").
 * Only the items sent are touched.
 *
 * An item number nobody has is an error when the ERP sends prices itself (it
 * chose to send it). When we fetch a whole ERP price list it's usually an
 * item the manufacturer doesn't sell through dealers, so the connection
 * skips those ($skipUnknown) and says how many.
 */
class PriceSync
{
    /** The name of what an item number is ("S-400"), for a preview; null when nothing (or more than one thing) has it. */
    public static function match(string $ref): ?string
    {
        if ($ref === '') {
            return null;
        }
        $names = ProductModel::where('erp_ref', $ref)->pluck('name')->concat(ProductOption::where('erp_ref', $ref)->pluck('name'));

        return $names->count() === 1 ? $names->first() : null;
    }

    /**
     * @param  list<array{item: string, price: mixed}>  $rows
     * @return array{errors: array<string, string>, updated: int, unknown: list<string>}
     */
    public static function apply(PriceList $list, array $rows, string $key = 'prices', bool $skipUnknown = false): array
    {
        $refs = collect($rows)->pluck('item')->map(fn ($r) => (string) $r)->unique()->values();
        $models = ProductModel::whereIn('erp_ref', $refs)->get(['id', 'erp_ref'])->groupBy('erp_ref');
        $options = ProductOption::whereIn('erp_ref', $refs)->get(['id', 'erp_ref'])->groupBy('erp_ref');

        $errors = [];
        $unknown = [];
        $changes = [];
        foreach ($rows as $i => $row) {
            $ref = (string) $row['item'];
            $matches = collect($models->get($ref, []))->map(fn ($m) => [PriceListItem::MODEL, $m->id])
                ->concat(collect($options->get($ref, []))->map(fn ($o) => [PriceListItem::OPTION, $o->id]));
            if ($matches->isEmpty()) {
                if ($skipUnknown) {
                    $unknown[] = $ref;
                } else {
                    $errors["$key.$i.item"] = "No model or option has item number \"$ref\".";
                }

                continue;
            }
            if ($matches->count() > 1) {
                $errors["$key.$i.item"] = "More than one model or option has item number \"$ref\". Make them unique under Integrations.";

                continue;
            }
            $text = trim((string) ($row['price'] ?? ''));
            $cents = $text === '' ? null : Money::toCents($text);
            if ($text !== '' && $cents === null) {
                $errors["$key.$i.price"] = "\"$text\" for item \"$ref\" isn't a price. Send a number like \"1234.50\".";

                continue;
            }
            [$type, $id] = $matches->first();
            $changes[] = [$type, $id, $cents];
        }
        if ($errors) {
            return ['errors' => $errors, 'updated' => 0, 'unknown' => $unknown];
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

        return ['errors' => [], 'updated' => count($changes), 'unknown' => $unknown];
    }
}
