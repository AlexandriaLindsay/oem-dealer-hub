<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\ProductModel;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "In stock near you": which dealers have which models right now, for a
 * manufacturer's own website (an embedded page, or this JSON for their web
 * developer). No sign-in; only for manufacturers who turned it on (Pro,
 * feature public_inventory).
 *
 * Counts only - never serial numbers, customers or prices - per active
 * dealer and model: new (inventory), demo and used units. With
 * "confirmed only", a machine counts only if its dealer confirmed it in a
 * stock check in the last CONFIRMED_DAYS days - so dealers who keep their
 * stock current are the ones shoppers find.
 */
class PublicStockController extends Controller
{
    public const CONFIRMED_DAYS = 45;

    private const SHOWN = ['inventory', 'demo', 'used'];

    public function __invoke(Request $request, string $slug): JsonResponse
    {
        $filters = $request->validate([
            'model' => ['nullable', 'integer'],
            'region' => ['nullable', 'string', 'max:80'],
        ]);
        $manufacturer = Manufacturer::where('slug', $slug)->first();
        if (! $manufacturer || ! self::enabled($manufacturer)) {
            return response()->json(['message' => 'There\'s no stock list here.', 'code' => 'not_found'], 404);
        }

        $body = Tenant::run($manufacturer, fn () => self::stock($manufacturer, $filters));

        // Readable from the manufacturer's own website; cached briefly so a busy page doesn't hammer us.
        return response()->json($body)->header('Access-Control-Allow-Origin', '*')->header('Cache-Control', 'public, max-age=300');
    }

    public static function enabled(Manufacturer $manufacturer): bool
    {
        return ! empty($manufacturer->settings['public_inventory']['enabled'])
            && Plan::includes($manufacturer, 'public_inventory')
            && Plan::standing($manufacturer) !== Plan::LAPSED;
    }

    private static function stock(Manufacturer $manufacturer, array $filters): array
    {
        $confirmedOnly = ! empty($manufacturer->settings['public_inventory']['confirmed_only']);

        $links = DealerLink::with('dealer')->where('status', DealerLink::ACTIVE)->get()->keyBy('id');
        $rows = Machine::query()
            ->whereIn('dealer_link_id', $links->keys())
            ->whereIn('status', self::SHOWN)
            ->when($confirmedOnly, fn ($q) => $q->where('confirmed_at', '>=', now()->subDays(self::CONFIRMED_DAYS)))
            ->when($filters['model'] ?? null, fn ($q, $m) => $q->where('product_model_id', $m))
            ->selectRaw('dealer_link_id, product_model_id, status, count(*) as total')
            ->groupBy('dealer_link_id', 'product_model_id', 'status')
            ->toBase()->get();

        $models = ProductModel::with('productLine')->orderBy('name')->get()->keyBy('id');
        $dealers = [];
        foreach ($rows as $row) {
            $dealer = $links->get($row->dealer_link_id)?->dealer;
            if (! $dealer || (($filters['region'] ?? null) && strcasecmp((string) $dealer->region, $filters['region']) !== 0)) {
                continue;
            }
            $d = &$dealers[$row->dealer_link_id];
            $d ??= ['name' => $dealer->name, 'city' => $dealer->city, 'region' => $dealer->region, 'country' => $dealer->country, 'phone' => $dealer->phone, 'website' => $dealer->website, 'models' => []];
            $m = &$d['models'][$row->product_model_id];
            $m ??= ['id' => (int) $row->product_model_id, 'name' => $models->get($row->product_model_id)?->name, 'new' => 0, 'demo' => 0, 'used' => 0];
            $m[$row->status === 'inventory' ? 'new' : $row->status] += (int) $row->total;
            unset($d, $m);
        }

        $dealers = collect($dealers)->map(function (array $d) {
            $d['models'] = collect($d['models'])->sortBy('name')->values()->all();
            $d['total'] = array_sum(array_map(fn ($m) => $m['new'] + $m['demo'] + $m['used'], $d['models']));

            return $d;
        })->sortBy([['region', 'asc'], ['name', 'asc']])->values();

        return [
            'manufacturer' => ['name' => $manufacturer->name],
            'confirmed_only' => $confirmedOnly,
            'updated_at' => now()->toIso8601String(),
            // For the filters: every model, and the regions dealers are in.
            'models' => $models->map(fn (ProductModel $m) => ['id' => $m->id, 'name' => $m->name, 'line' => $m->productLine?->name])->values(),
            'regions' => $links->map(fn (DealerLink $l) => $l->dealer->region)->filter()->unique()->sort()->values(),
            'dealers' => $dealers,
        ];
    }
}
