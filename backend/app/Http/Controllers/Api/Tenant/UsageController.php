<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\DealerLink;
use App\Models\UsageDay;
use App\Support\Features;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The usage report (Pro): which dealers use OEM Dealer Hub, how much, and for
 * what - including the dealers who don't use it at all, often the most
 * useful line for a sales team. Built from per-person daily visit counts.
 */
class UsageController extends Controller
{
    /**
     * The app says "this person opened <area>". Page visits rather than API
     * requests, because one page makes several requests (the machine list
     * also loads models for its filters) and counting those would credit
     * people with areas they never opened.
     *
     * Counted only when the plan includes the report (nothing is kept that
     * nobody can see) and the person can use that area. Either way the app
     * gets "204", so it never has to care.
     */
    public function visit(Request $request): Response
    {
        $area = $request->validate(['area' => ['required', 'string', Rule::in(array_keys(UsageDay::AREAS))]])['area'];
        $tenant = Tenant::current();
        $manufacturer = $tenant->manufacturer();

        if (Plan::includes($manufacturer, 'usage_analytics') && Features::allows($manufacturer, $tenant->membership(), UsageDay::AREAS[$area])) {
            // One row per person, day and area; a repeat visit adds one.
            DB::table('usage_days')->upsert(
                [[
                    'manufacturer_id' => $tenant->id(),
                    'user_id' => $request->user()->id,
                    'dealer_link_id' => $tenant->dealerLink()?->id,
                    'day' => now()->toDateString(),
                    'area' => $area,
                    'count' => 1,
                ]],
                ['manufacturer_id', 'user_id', 'day', 'area'],
                ['count' => DB::raw('usage_days.count + 1')],
            );
        }

        return response()->noContent();
    }

    public function report(Request $request): JsonResponse
    {
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'in:30,90']])['days'] ?? 30);
        $from = Carbon::today()->subDays($days - 1);

        $rows = UsageDay::where('day', '>=', $from->toDateString())->get();
        $dealerRows = $rows->whereNotNull('dealer_link_id');
        $links = DealerLink::with('dealer')->where('status', DealerLink::ACTIVE)->get();

        // Active dealers per day, every day in the range (zeros included).
        $perDay = $dealerRows->groupBy(fn ($r) => $r->day->toDateString())->map(fn ($g) => $g->pluck('dealer_link_id')->unique()->count());
        $daily = collect(range(0, $days - 1))->map(fn ($i) => $from->copy()->addDays($i)->toDateString())
            ->map(fn ($d) => ['day' => $d, 'dealers' => (int) ($perDay[$d] ?? 0)])->values();

        $byDealer = $dealerRows->groupBy('dealer_link_id');
        $dealers = $links->map(function (DealerLink $link) use ($byDealer) {
            $mine = $byDealer->get($link->id, collect());

            return [
                'dealer_link_id' => $link->id,
                'name' => $link->dealer->name,
                'people' => $mine->pluck('user_id')->unique()->count(),
                'days_active' => $mine->pluck('day')->map->toDateString()->unique()->count(),
                'last_active' => $mine->max('day')?->toDateString(),
                'visits' => (int) $mine->sum('count'),
                // Not the dashboard: everyone lands there, so it says nothing about what they use it for.
                'top_areas' => $mine->where('area', '!=', 'dashboard')->groupBy('area')->map->sum('count')->sortDesc()->keys()->take(3)->values(),
            ];
        })->sortBy([['visits', 'desc'], ['name', 'asc']])->values();

        $areas = $rows->groupBy('area')->map(fn ($g, $area) => [
            'area' => $area,
            'dealer_visits' => (int) $g->whereNotNull('dealer_link_id')->sum('count'),
            'staff_visits' => (int) $g->whereNull('dealer_link_id')->sum('count'),
        ])->sortByDesc(fn ($a) => $a['dealer_visits'] + $a['staff_visits'])->values();

        return response()->json([
            'days' => $days,
            'from' => $from->toDateString(),
            'summary' => [
                'dealers_total' => $links->count(),
                'dealers_active' => $dealers->where('visits', '>', 0)->count(), // of the dealers listed, so never "3 of 2"
                'people_active' => $rows->pluck('user_id')->unique()->count(),
                'visits' => (int) $rows->sum('count'),
            ],
            'daily' => $daily,
            'dealers' => $dealers,
            'areas' => $areas,
        ]);
    }
}
