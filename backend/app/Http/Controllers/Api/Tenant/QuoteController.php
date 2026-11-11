<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\DealerLink;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Support\Money;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Quotes. Dealers write them for their customers, priced from their own
 * price list (feature build_quotes); they see only their own (Quote's dealer
 * scope). The manufacturer's sales staff can read every dealer's quotes but
 * not change them (feature quotes).
 *
 * Prices are always worked out here, from the dealer's list - never trusted
 * from the browser - except a unit price the dealer deliberately changes.
 */
class QuoteController extends Controller
{
    private const PER_PAGE = 50;

    private const MAX_LINES = 100;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Quote::MOVES))],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = Quote::with(['dealerLink.dealer', 'creator'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['q'] ?? null, function ($q, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $number = (int) preg_replace('/\D/', '', $search);
                $q->where(fn ($w) => $w->where('customer', 'like', $like)->when($number > 0, fn ($n) => $n->orWhere('number', $number)));
            })
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Quote $q) => self::row($q)),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function show(int $quote): JsonResponse
    {
        return response()->json(self::detail(Quote::findOrFail($quote)));
    }

    /** A new draft for the signed-in dealer, in their list's currency. */
    public function store(Request $request): JsonResponse
    {
        $link = Tenant::current()->dealerLink();
        $list = PriceList::forDealer($link);
        if (! $list) {
            throw ValidationException::withMessages(['customer' => 'There are no prices to quote from yet. Ask '.Tenant::current()->manufacturer()->name.' to set up a price list.']);
        }

        $data = $this->validated($request);
        [$lines, $subtotal] = $this->priceLines($data['lines'] ?? [], $list);
        $discount = $this->discount($data, $subtotal);

        $quote = DB::transaction(function () use ($data, $lines, $subtotal, $discount, $link, $list, $request) {
            // The dealer's next number; the lock stops two new quotes sharing one.
            DealerLink::whereKey($link->id)->lockForUpdate()->first();
            $next = max(Quote::FIRST_NUMBER - 1, (int) Quote::where('dealer_link_id', $link->id)->max('number')) + 1;

            $quote = new Quote(['customer' => $data['customer'], 'notes' => $data['notes'] ?? null, 'valid_until' => $data['valid_until'] ?? null]);
            $quote->forceFill([
                'dealer_link_id' => $link->id,
                'number' => $next,
                'currency' => $list->currency,
                'created_by' => $request->user()->id,
            ]);
            $this->saveTotals($quote, $lines, $subtotal, $discount);

            return $quote;
        });

        return response()->json(self::detail($quote), 201);
    }

    /** Replaces a draft's customer, notes, lines and discount. Prices re-checked against the current list. */
    public function update(Request $request, int $quote): JsonResponse
    {
        $q = Quote::findOrFail($quote);
        $this->assertDraft($q);
        $list = PriceList::forDealer(Tenant::current()->dealerLink());

        $data = $this->validated($request);
        [$lines, $subtotal] = $this->priceLines($data['lines'] ?? [], $list);
        $discount = $this->discount($data, $subtotal);

        DB::transaction(function () use ($q, $data, $lines, $subtotal, $discount, $list) {
            $q->fill(['customer' => $data['customer'], 'notes' => $data['notes'] ?? null, 'valid_until' => $data['valid_until'] ?? null]);
            // A draft follows the dealer's list - including a currency change.
            $q->currency = $list?->currency ?? $q->currency;
            $this->saveTotals($q, $lines, $subtotal, $discount);
        });

        return response()->json(self::detail($q->fresh()));
    }

    /** draft -> sent -> won | lost; sent -> draft to fix something (Quote::MOVES). */
    public function status(Request $request, int $quote): JsonResponse
    {
        $q = Quote::findOrFail($quote);
        $to = $request->validate(['status' => ['required', Rule::in(array_keys(Quote::MOVES))]])['status'];

        if (! in_array($to, Quote::MOVES[$q->status], true)) {
            throw ValidationException::withMessages(['status' => "A {$q->status} quote can't be marked {$to}."]);
        }
        if ($to === Quote::SENT && ! $q->lines()->exists()) {
            throw ValidationException::withMessages(['status' => 'Add at least one line before sending the quote.']);
        }

        $q->status = $to;
        $q->sent_at = $to === Quote::SENT ? now() : ($to === Quote::DRAFT ? null : $q->sent_at);
        $q->decided_at = in_array($to, [Quote::WON, Quote::LOST], true) ? now() : null;
        $q->save();

        return response()->json(self::detail($q));
    }

    /** A new draft with the same customer and lines, priced from today's list. */
    public function copy(Request $request, int $quote): JsonResponse
    {
        $original = Quote::with('lines')->findOrFail($quote);
        $request->merge([
            'customer' => $original->customer,
            'notes' => $original->notes,
            'valid_until' => null,
            'discount' => Money::fromCents((int) $original->discount_cents),
            'lines' => $original->lines->map(fn (QuoteLine $l) => [
                'kind' => $l->kind,
                'item_id' => $l->item_id,
                'description' => $l->description,
                'quantity' => $l->quantity,
                // A price the dealer changed stays changed; list prices come fresh from today's list.
                'unit_price' => $l->list_price_cents !== null && $l->unit_price_cents === $l->list_price_cents ? null : Money::fromCents((int) $l->unit_price_cents),
            ])->all(),
        ]);

        return $this->store($request);
    }

    public function destroy(int $quote): JsonResponse
    {
        $q = Quote::findOrFail($quote);
        $this->assertDraft($q);
        $q->delete();

        return response()->json(null, 204);
    }

    // ---- Pricing ----

    /**
     * Lines as sent -> lines to store, with prices from $list. A model or
     * option takes its list price unless the dealer typed one; "price on
     * request" needs a typed price. Custom lines need a description and price.
     *
     * @return array{0: list<array>, 1: int} [lines, subtotal cents]
     */
    private function priceLines(array $input, ?PriceList $list): array
    {
        $prices = $list?->priceMap() ?? [];
        $models = ProductModel::whereIn('id', collect($input)->where('kind', 'model')->pluck('item_id'))->get()->keyBy('id');
        $options = ProductOption::with('productModel')->whereIn('id', collect($input)->where('kind', 'option')->pluck('item_id'))->get()->keyBy('id');

        $errors = [];
        $lines = [];
        $subtotal = 0;

        foreach (array_values($input) as $i => $row) {
            $kind = $row['kind'];
            $typed = trim((string) ($row['unit_price'] ?? ''));
            $unit = $typed === '' ? null : Money::toCents($typed);
            if ($typed !== '' && $unit === null) {
                $errors["lines.$i.unit_price"] = "\"$typed\" isn't a price. Use a number like 1234.50.";

                continue;
            }

            $listPrice = null;
            if ($kind === 'model' || $kind === 'option') {
                $item = $kind === 'model' ? $models->get((int) ($row['item_id'] ?? 0)) : $options->get((int) ($row['item_id'] ?? 0));
                if (! $item) {
                    $errors["lines.$i.item_id"] = 'That isn\'t one of '.Tenant::current()->manufacturer()->name.'\'s '.($kind === 'model' ? 'models' : 'options').'.';

                    continue;
                }
                $description = $kind === 'model' ? $item->name : $item->productModel->name.' - '.$item->name;
                $listPrice = $prices[($kind === 'model' ? PriceListItem::MODEL : PriceListItem::OPTION).':'.$item->id] ?? null;
                $unit ??= $listPrice;
                if ($unit === null) {
                    $errors["lines.$i.unit_price"] = "$description is \"price on request\" - enter the price you're quoting.";

                    continue;
                }
            } else {
                $description = trim((string) ($row['description'] ?? ''));
                if ($description === '') {
                    $errors["lines.$i.description"] = 'Describe this line (e.g. "Freight").';

                    continue;
                }
                if ($unit === null) {
                    $errors["lines.$i.unit_price"] = 'Enter a price for this line.';

                    continue;
                }
            }

            $quantity = (int) $row['quantity'];
            $total = $unit * $quantity;
            $subtotal += $total;
            $lines[] = [
                'position' => $i,
                'kind' => $kind,
                'item_id' => $kind === 'custom' ? null : (int) $row['item_id'],
                'description' => mb_substr($description, 0, 255),
                'quantity' => $quantity,
                'list_price_cents' => $listPrice,
                'unit_price_cents' => $unit,
                'line_total_cents' => $total,
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [$lines, $subtotal];
    }

    private function discount(array $data, int $subtotal): int
    {
        $text = trim((string) ($data['discount'] ?? ''));
        if ($text === '') {
            return 0;
        }
        $cents = Money::toCents($text);
        if ($cents === null) {
            throw ValidationException::withMessages(['discount' => "\"$text\" isn't an amount. Use a number like 500 or 1250.50."]);
        }
        if ($cents > $subtotal) {
            throw ValidationException::withMessages(['discount' => 'The discount is more than the quote\'s total.']);
        }

        return $cents;
    }

    private function saveTotals(Quote $quote, array $lines, int $subtotal, int $discount): void
    {
        $quote->forceFill(['subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'total_cents' => $subtotal - $discount])->save();
        $quote->lines()->delete();
        foreach ($lines as $line) {
            $quote->lines()->create($line);
        }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'customer' => ['required', 'array'],
            'customer.company' => ['nullable', 'string', 'max:191'],
            'customer.contact_name' => ['required', 'string', 'max:191'],
            'customer.email' => ['nullable', 'email', 'max:191'],
            'customer.phone' => ['nullable', 'string', 'max:40'],
            'customer.city' => ['nullable', 'string', 'max:120'],
            'customer.region' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'discount' => ['nullable', 'string', 'max:20'],
            'lines' => ['nullable', 'array', 'max:'.self::MAX_LINES],
            'lines.*.kind' => ['required', Rule::in(QuoteLine::KINDS)],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'lines.*.unit_price' => ['nullable', 'string', 'max:20'],
        ], [
            'valid_until.after_or_equal' => 'A quote can\'t already be out of date.',
        ], [
            'customer.contact_name' => 'contact name',
            'customer.email' => 'email',
            'lines.*.quantity' => 'quantity',
        ]);
    }

    private function assertDraft(Quote $q): void
    {
        if (! $q->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Only a draft can be changed. Reopen it as a draft, or copy it to a new quote.']);
        }
    }

    // ---- Output ----

    private static function row(Quote $q): array
    {
        return [
            'id' => $q->id,
            'number' => $q->label(),
            'status' => $q->status,
            'expired' => $q->isExpired(),
            'customer' => $q->customer,
            'currency' => $q->currency,
            'total' => Money::fromCents((int) $q->total_cents),
            'valid_until' => $q->valid_until?->toDateString(),
            'dealer' => $q->dealerLink->dealer->name,
            'created_by' => $q->creator?->name,
            'created_at' => $q->created_at?->toIso8601String(),
            'sent_at' => $q->sent_at?->toIso8601String(),
        ];
    }

    private static function detail(Quote $q): array
    {
        $q->loadMissing(['lines', 'dealerLink.dealer', 'creator']);
        $tenant = Tenant::current();
        $dealer = $q->dealerLink->dealer;

        return self::row($q) + [
            'notes' => $q->notes,
            'subtotal' => Money::fromCents((int) $q->subtotal_cents),
            'discount' => Money::fromCents((int) $q->discount_cents),
            'decided_at' => $q->decided_at?->toIso8601String(),
            // Who the quote is from - for the printed copy.
            'from' => [
                'dealer' => $dealer->name,
                'city' => $dealer->city,
                'region' => $dealer->region,
                'phone' => $dealer->phone,
                'website' => $dealer->website,
                'manufacturer' => $tenant->manufacturer()->name,
                'logo_url' => $tenant->manufacturer()->logoUrl(),
            ],
            'lines' => $q->lines->map(fn (QuoteLine $l) => [
                'kind' => $l->kind,
                'item_id' => $l->item_id,
                'description' => $l->description,
                'quantity' => $l->quantity,
                'list_price' => $l->list_price_cents === null ? null : Money::fromCents((int) $l->list_price_cents),
                'unit_price' => Money::fromCents((int) $l->unit_price_cents),
                'line_total' => Money::fromCents((int) $l->line_total_cents),
            ])->values(),
            // Only the dealer, and only a draft.
            'can_edit' => $tenant->isDealer() && $q->isDraft(),
            'can_change_status' => $tenant->isDealer(),
        ];
    }
}
