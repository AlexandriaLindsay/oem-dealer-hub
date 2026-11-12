<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\DealerLink;
use App\Models\StockCheck;
use App\Models\StockCheckItem;
use App\Support\Features;
use App\Support\StockChecks;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Stock checks in the app (App\Support\StockChecks). Feature stock_checks:
 * a dealer sees and answers their own (StockCheck's dealer scope); staff see
 * every dealer's. Starting checks and the monthly switch are
 * manage_stock_checks.
 */
class StockCheckController extends Controller
{
    /**
     * A dealer: their open check, and past ones. Staff: each dealer's latest
     * check with its tally, the machines dealers said aren't there, and the
     * monthly switch.
     */
    public function index(): JsonResponse
    {
        $tenant = Tenant::current();

        if ($tenant->isDealer()) {
            $checks = StockCheck::with('dealerLink.dealer')->withCount([
                'items',
                'items as here_count' => fn ($q) => $q->where('answer', StockCheckItem::HERE),
                'items as not_here_count' => fn ($q) => $q->where('answer', StockCheckItem::NOT_HERE),
            ])->orderByDesc('id')->limit(12)->get();

            return response()->json([
                'open' => ($open = $checks->first(fn (StockCheck $c) => $c->isOpen())) ? StockChecks::present($open) : null,
                'history' => $checks->map(fn (StockCheck $c) => self::summary($c))->values(),
            ]);
        }

        // Each dealer's latest check.
        $latestIds = StockCheck::selectRaw('max(id) as id')->groupBy('dealer_link_id')->pluck('id');
        $latest = StockCheck::with(['dealerLink.dealer'])->withCount([
            'items',
            'items as here_count' => fn ($q) => $q->where('answer', StockCheckItem::HERE),
            'items as not_here_count' => fn ($q) => $q->where('answer', StockCheckItem::NOT_HERE),
        ])->whereIn('id', $latestIds)->get();

        $notHere = StockCheckItem::with(['machine.productModel', 'stockCheck.dealerLink.dealer'])
            ->whereIn('stock_check_id', $latestIds)->where('answer', StockCheckItem::NOT_HERE)->get();

        return response()->json([
            'monthly' => ! empty($tenant->manufacturer()->settings['stock_checks_monthly']),
            'can_manage' => Features::allows($tenant->manufacturer(), $tenant->membership(), 'manage_stock_checks'),
            'dealers' => $latest->sortBy(fn (StockCheck $c) => $c->dealerLink->dealer->name)->map(fn (StockCheck $c) => self::summary($c))->values(),
            'not_here' => $notHere->map(fn (StockCheckItem $i) => [
                'machine_id' => $i->machine_id,
                'serial_no' => $i->machine?->serial_no,
                'model' => $i->machine?->productModel?->name,
                'dealer' => $i->stockCheck->dealerLink->dealer->name,
                'note' => $i->note,
                'answered_at' => $i->answered_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function show(int $check): JsonResponse
    {
        return response()->json(StockChecks::present(StockCheck::findOrFail($check)));
    }

    /** A dealer's answers, machine by machine. */
    public function answer(Request $request, int $check): JsonResponse
    {
        $c = StockCheck::findOrFail($check);
        abort_unless(Tenant::current()->isDealer(), 403, 'Only the dealer answers their stock check.');
        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.machine_id' => ['required', 'integer'],
            'answers.*.answer' => ['required', Rule::in([StockCheckItem::HERE, StockCheckItem::NOT_HERE])],
            'answers.*.note' => ['nullable', 'string', 'max:500'],
        ]);
        $answers = collect($data['answers']);

        $done = StockChecks::answer($c, $answers->pluck('answer', 'machine_id')->all(), $answers->pluck('note', 'machine_id')->all(), $request->user());

        return response()->json(StockChecks::present($done));
    }

    /** "Everything's here." */
    public function confirmAll(Request $request, int $check): JsonResponse
    {
        $c = StockCheck::findOrFail($check);
        abort_unless(Tenant::current()->isDealer(), 403, 'Only the dealer answers their stock check.');

        return response()->json(StockChecks::present(StockChecks::confirmAll($c, $request->user())));
    }

    /** Staff start checks now: for one dealer, or every active dealer. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['dealer_link_id' => ['nullable', 'integer']]);
        $links = isset($data['dealer_link_id'])
            ? collect([DealerLink::findOrFail($data['dealer_link_id'])])
            : DealerLink::where('status', DealerLink::ACTIVE)->with('dealer')->get();

        $started = $links->filter(fn (DealerLink $l) => StockChecks::start($l, $request->user()))->count();

        return response()->json(['started' => $started, 'skipped' => $links->count() - $started]);
    }

    /** Monthly rounds on or off. */
    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['monthly' => ['required', 'boolean']]);
        $manufacturer = Tenant::current()->manufacturer();
        $manufacturer->update(['settings' => array_merge($manufacturer->settings ?? [], ['stock_checks_monthly' => $data['monthly']])]);

        return response()->json(['monthly' => $data['monthly']]);
    }

    private static function summary(StockCheck $c): array
    {
        $c->loadMissing('dealerLink.dealer');
        // Counted already when the list was loaded with them (index); only counted here otherwise.
        if (! isset($c->items_count)) {
            $c->loadCount([
                'items',
                'items as here_count' => fn ($q) => $q->where('answer', StockCheckItem::HERE),
                'items as not_here_count' => fn ($q) => $q->where('answer', StockCheckItem::NOT_HERE),
            ]);
        }

        return [
            'id' => $c->id,
            'dealer_link_id' => $c->dealer_link_id,
            'dealer' => $c->dealerLink->dealer->name,
            'status' => $c->isOpen() ? StockCheck::OPEN : ($c->status === StockCheck::OPEN ? StockCheck::EXPIRED : $c->status),
            'machines' => $c->items_count,
            'here' => $c->here_count,
            'not_here' => $c->not_here_count,
            'unanswered' => $c->items_count - $c->here_count - $c->not_here_count,
            'due_on' => $c->due_on->toDateString(),
            'created_at' => $c->created_at?->toIso8601String(),
            'completed_at' => $c->completed_at?->toIso8601String(),
        ];
    }
}
