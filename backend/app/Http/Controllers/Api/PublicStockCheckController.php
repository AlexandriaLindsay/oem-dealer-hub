<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockCheck;
use App\Models\StockCheckItem;
use App\Support\StockChecks;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The stock check link in the email - no sign-in, so a dealer can answer
 * from their phone in the yard. The link's token is the only key: it shows
 * that one dealer's list (serials and models, nothing about customers) and
 * works only while the check is open. Opening it changes nothing; the
 * answers are POSTs from the page.
 */
class PublicStockCheckController extends Controller
{
    public function show(string $token): JsonResponse
    {
        return $this->withCheck($token, fn (StockCheck $c) => response()->json(
            StockChecks::present($c) + ['manufacturer' => $c->manufacturer->name]
        ));
    }

    public function confirmAll(string $token): JsonResponse
    {
        return $this->withCheck($token, fn (StockCheck $c) => response()->json(
            StockChecks::present(StockChecks::confirmAll($c, null)) + ['manufacturer' => $c->manufacturer->name]
        ));
    }

    public function answer(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.machine_id' => ['required', 'integer'],
            'answers.*.answer' => ['required', Rule::in([StockCheckItem::HERE, StockCheckItem::NOT_HERE])],
            'answers.*.note' => ['nullable', 'string', 'max:500'],
        ]);
        $answers = collect($data['answers']);

        return $this->withCheck($token, fn (StockCheck $c) => response()->json(
            StockChecks::present(StockChecks::answer($c, $answers->pluck('answer', 'machine_id')->all(), $answers->pluck('note', 'machine_id')->all(), null)) + ['manufacturer' => $c->manufacturer->name]
        ));
    }

    /** Finds the check by its link, and works inside its manufacturer. */
    private function withCheck(string $token, callable $then): JsonResponse
    {
        $check = StockChecks::byToken($token);
        if (! $check || ! $check->manufacturer) {
            return response()->json(['message' => 'This stock check link isn\'t right, or it\'s been replaced by a newer one.', 'code' => 'not_found'], 404);
        }

        return Tenant::run($check->manufacturer, fn () => $then(StockCheck::findOrFail($check->id)));
    }
}
