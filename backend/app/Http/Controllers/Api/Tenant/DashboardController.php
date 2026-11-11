<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\DealerLink;
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
        ]);
    }
}
