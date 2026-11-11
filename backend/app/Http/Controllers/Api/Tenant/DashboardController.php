<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\ProductLine;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $tenant = Tenant::current();
        $manufacturer = $tenant->manufacturer();

        if ($tenant->isDealer()) {
            return response()->json([
                'acting_as' => 'dealer',
                'product_lines' => ProductLine::count(),
                'machines' => self::machinesByStatus(),
            ]);
        }

        $usage = [
            'dealers' => DealerLink::count(),
            'staff' => $manufacturer->staff()->count(),
        ];

        return response()->json([
            'acting_as' => 'manufacturer',
            'plan' => [
                'key' => $manufacturer->plan,
                'effective' => Plan::effective($manufacturer),
                'label' => $manufacturer->plan === Plan::TRIAL ? 'Pro trial' : Plan::PLANS[Plan::effective($manufacturer)]['label'],
                'trial_ends_at' => $manufacturer->trial_ends_at?->toIso8601String(),
            ],
            'usage' => collect($usage)->map(fn ($used, $key) => [
                'used' => $used,
                'limit' => Plan::limit($manufacturer, $key),
            ]),
            'product_lines' => ProductLine::count(),
            'machines' => self::machinesByStatus(),
        ]);
    }

    /**
     * Every status with its count, zeros included, in Machine::STATUSES order.
     * For a dealer it's only their own machines (Machine's dealer scope).
     *
     * @return array<string, int>
     */
    private static function machinesByStatus(): array
    {
        $counts = Machine::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(Machine::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();
    }
}
