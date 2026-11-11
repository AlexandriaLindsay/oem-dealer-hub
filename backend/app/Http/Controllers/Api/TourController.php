<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Tours;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Remembers which guided tours a person has finished or skipped, so each shows once. */
class TourController extends Controller
{
    public function seen(Request $request, string $key): JsonResponse
    {
        abort_unless(in_array($key, Tours::KNOWN, true), 404);

        $user = $request->user();
        $seen = $user->tours_seen ?? [];
        $seen[$key] ??= now()->toIso8601String();
        $user->forceFill(['tours_seen' => $seen])->save();

        return response()->json(['tours_seen' => array_keys($seen)]);
    }
}
