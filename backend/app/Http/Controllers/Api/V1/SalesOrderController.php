<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\SalesOrderPayload;
use App\Models\SalesOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The integration API's sales orders (docs/INTEGRATIONS.md). Behind
 * `api-key:sales_orders:read`, which has already set the key's manufacturer.
 *
 * An ERP typically either listens for webhooks, or asks now and then for
 * everything changed since its last look (updated_since) - both give the
 * same order shape (SalesOrderPayload).
 */
class SalesOrderController extends Controller
{
    private const PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(SalesOrder::STATUSES)],
            'updated_since' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = SalesOrder::with(['lines', 'dealerLink.dealer', 'quote'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['updated_since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>=', now()->parse($since)))
            // Oldest change first, so a sync can page through and remember where it got to.
            ->orderBy('updated_at')->orderBy('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (SalesOrder $o) => SalesOrderPayload::make($o))->values(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    /** One order, by its id or its number ("SO-1001"). */
    public function show(string $order): JsonResponse
    {
        $found = self::find($order);
        if (! $found) {
            return self::notFound($order);
        }

        return response()->json(['data' => SalesOrderPayload::make($found)]);
    }

    /**
     * Their ERP updates an order (`api-key:sales_orders:write`): its ERP
     * number, PO, wanted-by date, and its status - with the same rules as the
     * board (SalesOrder::moveTo). Details first, then the move, in one
     * transaction, so a refused move changes nothing.
     */
    public function update(Request $request, string $order): JsonResponse
    {
        $found = self::find($order);
        if (! $found) {
            return self::notFound($order);
        }

        $data = $request->validate([
            'erp_ref' => ['sometimes', 'nullable', 'string', 'max:100'],
            'po_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'requested_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(SalesOrder::STATUSES)],
            'cancel_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $details = array_intersect_key($data, array_flip(['erp_ref', 'po_number', 'requested_date']));

        DB::transaction(function () use ($found, $data, $details) {
            if ($details) {
                $found->updateDetails($details);
            }
            if (isset($data['status']) && $data['status'] !== $found->status) {
                $found->moveTo($data['status'], null, $data['cancel_reason'] ?? null);
            }
        });

        return response()->json(['data' => SalesOrderPayload::make($found->fresh())]);
    }

    private static function find(string $order): ?SalesOrder
    {
        if (preg_match('/^SO-(\d+)$/i', $order, $m)) {
            return SalesOrder::where('number', (int) $m[1])->first();
        }

        return ctype_digit($order) ? SalesOrder::find((int) $order) : null;
    }

    private static function notFound(string $order): JsonResponse
    {
        return response()->json(['message' => 'No sales order '.$order.'.', 'code' => 'not_found'], 404);
    }
}
