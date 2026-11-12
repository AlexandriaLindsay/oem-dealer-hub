<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\DealerLink;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductLine;
use App\Models\ProductOption;
use App\Support\Money;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Price lists, for the manufacturer's staff (feature manage_pricing). Dealers
 * never reach these routes - they see only their own list, through the catalog.
 */
class PriceListController extends Controller
{
    public function index(): JsonResponse
    {
        $lists = PriceList::withCount(['items', 'dealerLinks'])->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json($lists->map(fn (PriceList $l) => self::summary($l)));
    }

    public function store(Request $request): JsonResponse
    {
        $manufacturer = Tenant::current()->manufacturer();
        if (! Plan::allows($manufacturer, 'price_lists', PriceList::count())) {
            throw ValidationException::withMessages([
                'name' => 'Your plan includes '.Plan::limit($manufacturer, 'price_lists').' price list. Upgrade to Pro for a list per dealer group.',
            ]);
        }

        $data = $request->validate(self::rules() + [
            'copy_from' => ['nullable', 'integer', Rule::exists('price_lists', 'id')->where('manufacturer_id', Tenant::current()->id())],
        ]);

        $list = DB::transaction(function () use ($data) {
            $list = new PriceList(['name' => $data['name'], 'currency' => $data['currency'], 'notes' => $data['notes'] ?? null]);
            // The first list is the default - every dealer needs prices from somewhere.
            $list->is_default = ! PriceList::exists();
            $list->save();

            // Start from another list's prices (same numbers - no currency conversion).
            if (! empty($data['copy_from'])) {
                foreach (PriceList::findOrFail($data['copy_from'])->items as $item) {
                    $list->items()->create($item->only(['item_type', 'item_id', 'price_cents']));
                }
            }

            return $list;
        });

        return response()->json(self::summary($list->loadCount(['items', 'dealerLinks'])), 201);
    }

    /** One list with every model and option and its price on it (null: price on request). */
    public function show(int $priceList): JsonResponse
    {
        $list = PriceList::withCount(['items', 'dealerLinks'])->findOrFail($priceList);

        return response()->json(self::summary($list) + [
            'lines' => self::catalog($list),
            // Prices are set by their ERP (Integrations), so the page shows them without the editor.
            'prices_from_erp' => Tenant::current()->manufacturer()->erpOwns('prices'),
        ]);
    }

    public function update(Request $request, int $priceList): JsonResponse
    {
        $list = PriceList::findOrFail($priceList);
        $data = $request->validate(self::rules($list->id));
        $list->update(['name' => $data['name'], 'currency' => $data['currency'], 'notes' => $data['notes'] ?? null]);

        return $this->show($list->id);
    }

    /**
     * Sets prices: [{type: model|option, id, price: "1234.50" | null}]. A null
     * (or empty) price removes it - back to "price on request". Only the
     * items sent are touched.
     */
    public function prices(Request $request, int $priceList): JsonResponse
    {
        $list = PriceList::findOrFail($priceList);
        $tenantId = Tenant::current()->id();
        // One owner per kind of data: when their ERP sets prices, they're not also typed in here.
        if (Tenant::current()->manufacturer()->erpOwns('prices')) {
            throw ValidationException::withMessages(['prices' => 'Prices come from your ERP. Change them there, or turn that off under Integrations.']);
        }
        $data = $request->validate([
            'prices' => ['required', 'array', 'max:2000'],
            'prices.*.type' => ['required', Rule::in([PriceListItem::MODEL, PriceListItem::OPTION])],
            'prices.*.id' => ['required', 'integer'],
            'prices.*.price' => ['nullable', 'string', 'max:20'],
        ]);

        // Only this manufacturer's models and options can be priced.
        $ids = collect($data['prices'])->groupBy('type')->map(fn ($g) => $g->pluck('id')->unique()->values());
        $known = [
            PriceListItem::MODEL => DB::table('product_models')->where('manufacturer_id', $tenantId)->whereIn('id', $ids[PriceListItem::MODEL] ?? [])->pluck('id')->all(),
            PriceListItem::OPTION => DB::table('product_options')->where('manufacturer_id', $tenantId)->whereIn('id', $ids[PriceListItem::OPTION] ?? [])->pluck('id')->all(),
        ];

        $errors = [];
        $changes = [];
        foreach ($data['prices'] as $i => $row) {
            if (! in_array((int) $row['id'], $known[$row['type']], true)) {
                $errors["prices.$i.id"] = 'That isn\'t one of your models or options.';

                continue;
            }
            $text = trim((string) ($row['price'] ?? ''));
            $cents = $text === '' ? null : Money::toCents($text);
            if ($text !== '' && $cents === null) {
                $errors["prices.$i.price"] = "\"$text\" isn't a price. Use a number like 1234.50.";

                continue;
            }
            $changes[] = [$row['type'], (int) $row['id'], $cents];
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

        return $this->show($list->id);
    }

    public function makeDefault(int $priceList): JsonResponse
    {
        $list = PriceList::findOrFail($priceList);
        DB::transaction(function () use ($list) {
            PriceList::where('is_default', true)->update(['is_default' => false]);
            $list->forceFill(['is_default' => true])->save();
        });

        return $this->index();
    }

    /**
     * Deletes a list. Its dealers go back to the default. The default itself
     * can only go when it's the last list (choose another default first).
     */
    public function destroy(int $priceList): JsonResponse
    {
        $list = PriceList::findOrFail($priceList);
        if ($list->is_default && PriceList::count() > 1) {
            throw ValidationException::withMessages(['price_list' => 'This is your default list. Make another list the default first.']);
        }
        $list->delete();

        return response()->json(null, 204);
    }

    /** Puts a dealer on a list, or back on the default (null). */
    public function assign(Request $request, int $link): JsonResponse
    {
        $dealerLink = DealerLink::findOrFail($link);
        $data = $request->validate([
            'price_list_id' => ['present', 'nullable', 'integer', Rule::exists('price_lists', 'id')->where('manufacturer_id', Tenant::current()->id())],
        ]);
        // forceFill: price_list_id is deliberately not mass-assignable on DealerLink - only this endpoint sets it.
        $dealerLink->forceFill(['price_list_id' => $data['price_list_id']])->save();

        return response()->json(['id' => $dealerLink->id, 'price_list_id' => $dealerLink->price_list_id]);
    }

    /**
     * Every product line, model and option with its price on $list - the
     * shape both the price editor and the dealers' catalog use.
     */
    /** @param  list<int>|null  $lineIds  only these product lines (a dealer's, DealerLink::productLineIds); null for all */
    public static function catalog(PriceList $list, ?array $lineIds = null): array
    {
        $prices = $list->priceMap();
        $price = fn (string $type, int $id) => isset($prices["$type:$id"]) ? Money::fromCents($prices["$type:$id"]) : null;

        return ProductLine::with(['models.options'])
            ->when($lineIds !== null, fn ($q) => $q->whereIn('id', $lineIds))
            ->orderBy('name')->get()->map(fn (ProductLine $line) => [
                'id' => $line->id,
                'name' => $line->name,
                'models' => $line->models->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'category' => $m->category,
                    'price' => $price(PriceListItem::MODEL, $m->id),
                    'options' => $m->options->map(fn (ProductOption $o) => ['id' => $o->id, 'name' => $o->name, 'price' => $price(PriceListItem::OPTION, $o->id)])->values(),
                ])->values(),
            ])->filter(fn ($line) => count($line['models']) > 0)->values()->all();
    }

    private static function rules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('price_lists')->where('manufacturer_id', Tenant::current()->id())->ignore($ignoreId)],
            'currency' => ['required', Rule::in(PriceList::CURRENCIES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private static function summary(PriceList $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'currency' => $l->currency,
            'is_default' => $l->is_default,
            'notes' => $l->notes,
            'items_count' => $l->items_count ?? null,
            'dealers_count' => $l->dealer_links_count ?? null,
            'updated_at' => $l->updated_at?->toIso8601String(),
        ];
    }
}
