<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Reports\FleetReport;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The fleet report across every dealer, and its Excel export. Staff only. */
class FleetController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $report = new FleetReport($request->validate(Machine::filterRules()));

        return response()->json($report->summary());
    }

    public function export(Request $request): BinaryFileResponse
    {
        $report = new FleetReport($request->validate(Machine::filterRules()));

        // Written in full while the tenant is still set, then sent and deleted.
        $path = tempnam(sys_get_temp_dir(), 'fleet');
        $report->writeXlsx($path);

        $name = Tenant::current()->manufacturer()->slug.'-fleet-'.now()->toDateString().'.xlsx';

        return response()
            ->download($path, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }
}
