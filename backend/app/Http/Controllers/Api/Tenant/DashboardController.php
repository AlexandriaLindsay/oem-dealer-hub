<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\ProductLine;
use App\Models\Registration;
use App\Support\Plan;
use App\Support\Uploads;
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
                // Their own, waiting for the manufacturer (Registration's dealer scope).
                'registrations_pending' => Registration::where('status', Registration::PENDING)->count(),
                // Announcements sent to their dealership that this person hasn't opened.
                'announcements_unread' => BroadcastController::unreadCount((int) request()->user()->id),
            ]);
        }

        $usage = [
            // Active dealers - the ones the plan counts.
            'dealers' => DealerLink::where('status', DealerLink::ACTIVE)->count(),
            'staff' => $manufacturer->staff()->count(),
            // In GB to match the plan's limit; one decimal is plenty on a dashboard.
            'storage_gb' => round(Uploads::usedBytes() / 1024 ** 3, 1),
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
            'registrations_pending' => Registration::where('status', Registration::PENDING)->count(),
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
