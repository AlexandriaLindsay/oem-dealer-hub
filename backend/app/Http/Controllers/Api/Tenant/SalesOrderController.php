<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Support\Features;
use App\Support\Money;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sales orders: what a won quote becomes (SalesOrder::fromQuote, when staff
 * mark a quote won). Manufacturer staff only. Reading is feature
 * sales_orders; moving an order along and filling in its details is
 * manage_sales_orders. A rep limited to territories sees only their dealers'
 * orders (SalesOrder's dealer scope).
 */
class SalesOrderController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            // "open" = received or in production - still to build or ship.
            'status' => ['nullable', Rule::in([...SalesOrder::STATUSES, 'open'])],
            'q' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $status = $filters['status'] ?? null;

        $page = SalesOrder::with(['dealerLink.dealer', 'quote'])->withCount('lines')
            ->when($status === 'open', fn ($q) => $q->whereIn('status', SalesOrder::OPEN))
            ->when($status && $status !== 'open', fn ($q) => $q->where('status', $status))
            ->when($filters['q'] ?? null, function ($q, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $number = (int) preg_replace('/\D/', '', $search);
                $q->where(fn ($w) => $w->where('customer', 'like', $like)
                    ->orWhere('po_number', 'like', $like)
                    ->when($number > 0, fn ($n) => $n->orWhere('number', $number)));
            })
            // Work still to do oldest first, so the longest-waiting order is on top; the rest newest first.
            ->when(in_array($status, ['open', SalesOrder::RECEIVED, SalesOrder::IN_PRODUCTION], true), fn ($q) => $q->orderBy('id'), fn ($q) => $q->orderByDesc('id'))
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (SalesOrder $o) => self::row($o)),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            // Moved only by their ERP: the board shows the cards without move buttons.
            'status_from_erp' => Tenant::current()->manufacturer()->erpOwns('order_status'),
        ]);
    }

    public function show(int $order): JsonResponse
    {
        return response()->json(self::detail(SalesOrder::findOrFail($order)));
    }

    /** The details the quote didn't have: the customer's PO, when they want it, notes for the team. */
    public function update(Request $request, int $order): JsonResponse
    {
        $o = SalesOrder::findOrFail($order);
        $o->updateDetails($request->validate([
            'po_number' => ['nullable', 'string', 'max:60'],
            'requested_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // The order's number in their ERP, once it's been entered there.
            'erp_ref' => ['nullable', 'string', 'max:100'],
        ]));

        return response()->json(self::detail($o->fresh()));
    }

    /**
     * Moves an order on (SalesOrder::moveTo). Not when their ERP is in charge
     * of order status - then it moves only through the API, so the two
     * never disagree.
     */
    public function status(Request $request, int $order): JsonResponse
    {
        $o = SalesOrder::findOrFail($order);
        if (Tenant::current()->manufacturer()->erpOwns('order_status')) {
            throw ValidationException::withMessages(['status' => 'Order status comes from your ERP. Change it there, or turn that off under Integrations.']);
        }
        $data = $request->validate([
            'status' => ['required', Rule::in(SalesOrder::STATUSES)],
            'cancel_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $o->moveTo($data['status'], $request->user(), $data['cancel_reason'] ?? null);

        return response()->json(self::detail($o->fresh()));
    }

    // ---- Output ----

    private static function row(SalesOrder $o): array
    {
        return [
            'id' => $o->id,
            'number' => $o->label(),
            'status' => $o->status,
            'customer' => $o->customer,
            'currency' => $o->currency,
            'total' => Money::fromCents((int) $o->total_cents),
            'dealer' => $o->dealerLink->dealer->name,
            'quote' => $o->quote?->label(),
            'po_number' => $o->po_number,
            'requested_date' => $o->requested_date?->toDateString(),
            'erp_ref' => $o->erp_ref,
            'lines_count' => $o->lines_count ?? $o->lines()->count(),
            'created_at' => $o->created_at?->toIso8601String(),
            'shipped_at' => $o->shipped_at?->toIso8601String(),
        ];
    }

    private static function detail(SalesOrder $o): array
    {
        $o->loadMissing(['lines', 'dealerLink.dealer', 'quote', 'creator', 'handler']);
        $tenant = Tenant::current();
        $canManage = Features::allows($tenant->manufacturer(), $tenant->membership(), 'manage_sales_orders');
        $fromErp = $tenant->manufacturer()->erpOwns('order_status');

        return self::row($o) + [
            'quote_id' => $o->quote_id,
            'subtotal' => Money::fromCents((int) $o->subtotal_cents),
            'discount' => Money::fromCents((int) $o->discount_cents),
            'notes' => $o->notes,
            'cancel_reason' => $o->cancel_reason,
            'created_by' => $o->creator?->name,
            'handled_by' => $o->handler?->name,
            'started_at' => $o->started_at?->toIso8601String(),
            'delivered_at' => $o->delivered_at?->toIso8601String(),
            'cancelled_at' => $o->cancelled_at?->toIso8601String(),
            'lines' => $o->lines->map(fn (SalesOrderLine $l) => [
                'kind' => $l->kind,
                'description' => $l->description,
                'quantity' => $l->quantity,
                'unit_price' => Money::fromCents((int) $l->unit_price_cents),
                'line_total' => Money::fromCents((int) $l->line_total_cents),
            ])->values(),
            // Where it can go next, and whether its details can be filled in - only for those who manage orders.
            'next' => $canManage && ! $fromErp ? SalesOrder::MOVES[$o->status] : [],
            // Moved only by their ERP: the page says so instead of showing buttons.
            'status_from_erp' => $fromErp,
            'can_edit' => $canManage && ! $o->isFinished(),
        ];
    }
}
