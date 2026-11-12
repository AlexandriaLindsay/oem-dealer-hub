<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\MachineSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Their ERP sends machines (`api-key:machines:write`) - typically when one
 * is built, or shipped to a dealer. Matched by serial number (MachineSync,
 * which the ERP connection uses too). Up to 500 at a time; each machine is
 * its own transaction and gets its own result, so one bad row doesn't hold
 * up the rest.
 */
class MachineController extends Controller
{
    public function upsert(Request $request): JsonResponse
    {
        $request->validate(['machines' => ['required', 'array', 'min:1', 'max:500']]);

        $results = [];
        foreach ($request->input('machines') as $i => $row) {
            $results[] = ['index' => $i, 'serial_no' => is_array($row) ? ($row['serial_no'] ?? null) : null] + MachineSync::one(is_array($row) ? $row : []);
        }

        return response()->json([
            'results' => $results,
            'summary' => collect($results)->countBy('result')->all(),
        ]);
    }
}
