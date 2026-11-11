<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\PartsOrder;
use App\Models\PartsOrderLine;
use App\Notifications\PartsOrderSubmitted;
use App\Notifications\PartsOrderUpdated;
use App\Support\Features;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Parts orders. Dealers send them and see only their own (PartsOrder's dealer
 * scope; feature order_parts); the parts desk works through them (feature
 * manage_parts_orders).
 */
class PartsOrderController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            // "open" = submitted or in progress - the parts desk's to-do list.
            'status' => ['nullable', Rule::in([...array_keys(PartsOrder::MOVES), 'open'])],
            'q' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $status = $filters['status'] ?? null;

        $page = PartsOrder::with(['dealerLink.dealer', 'submitter'])->withCount('lines')
            ->when($status === 'open', fn ($q) => $q->whereIn('status', PartsOrder::OPEN))
            ->when($status && $status !== 'open', fn ($q) => $q->where('status', $status))
            ->when($filters['q'] ?? null, function ($q, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $number = (int) preg_replace('/\D/', '', $search);
                $q->where(fn ($w) => $w->where('po_number', 'like', $like)
                    ->orWhere('machine_serial', 'like', $like)
                    ->orWhereHas('lines', fn ($l) => $l->where('part_number', 'like', $like))
                    ->when($number > 0, fn ($n) => $n->orWhere('number', $number)));
            })
            // The open queue oldest first; everything else newest first.
            ->when($status === 'open', fn ($q) => $q->orderBy('id'), fn ($q) => $q->orderByDesc('id'))
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (PartsOrder $o) => self::row($o)),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function show(int $order): JsonResponse
    {
        return response()->json(self::detail(PartsOrder::findOrFail($order)));
    }

    /** Where to ship by default: the dealer's own details. */
    public function defaults(): JsonResponse
    {
        $dealer = Tenant::current()->dealerLink()->dealer;

        return response()->json(['ship_to' => array_filter([
            'name' => $dealer->name,
            'city' => $dealer->city,
            'region' => $dealer->region,
            'country' => $dealer->country,
            'phone' => $dealer->phone,
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'po_number' => ['nullable', 'string', 'max:60'],
            'machine_serial' => ['nullable', 'string', 'max:80'],
            'ship_method' => ['required', Rule::in(PartsOrder::SHIP_METHODS)],
            'ship_to' => ['nullable', 'array', 'required_unless:ship_method,pickup'],
            'ship_to.name' => ['required_unless:ship_method,pickup', 'nullable', 'string', 'max:191'],
            'ship_to.address' => ['required_unless:ship_method,pickup', 'nullable', 'string', 'max:191'],
            'ship_to.city' => ['required_unless:ship_method,pickup', 'nullable', 'string', 'max:120'],
            'ship_to.region' => ['nullable', 'string', 'max:120'],
            'ship_to.postal_code' => ['nullable', 'string', 'max:20'],
            'ship_to.country' => ['nullable', 'string', 'size:2'],
            'ship_to.phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.part_number' => ['required', 'string', 'max:60'],
            'lines.*.description' => ['nullable', 'string', 'max:191'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
        ], [
            'lines.required' => 'Add at least one part.',
            'lines.min' => 'Add at least one part.',
            'ship_to.required_unless' => 'Where should it go? (Or choose to pick it up.)',
            'ship_to.*.required_unless' => 'Needed to ship it - or choose to pick it up.',
        ], [
            'ship_to.name' => 'ship-to name',
            'ship_to.address' => 'street address',
            'ship_to.city' => 'city',
            'lines.*.part_number' => 'part number',
            'lines.*.quantity' => 'quantity',
        ]);

        $link = Tenant::current()->dealerLink();
        $order = DB::transaction(function () use ($data, $link, $request) {
            // The manufacturer's next number; the lock stops two orders sharing one.
            $manufacturer = Tenant::current()->manufacturer();
            DB::table('manufacturers')->where('id', $manufacturer->id)->lockForUpdate()->first();
            $next = max(PartsOrder::FIRST_NUMBER - 1, (int) PartsOrder::withoutGlobalScope('dealer')->max('number')) + 1;

            $order = new PartsOrder([
                'po_number' => $data['po_number'] ?? null,
                'machine_serial' => $data['machine_serial'] ?? null,
                'ship_method' => $data['ship_method'],
                'ship_to' => $data['ship_method'] === 'pickup' ? null : array_filter($data['ship_to'] ?? []),
                'notes' => $data['notes'] ?? null,
            ]);
            $order->forceFill(['dealer_link_id' => $link->id, 'number' => $next, 'submitted_by' => $request->user()->id])->save();

            foreach (array_values($data['lines']) as $i => $line) {
                $order->lines()->create([
                    'position' => $i,
                    // Part numbers as the parts desk writes them: no stray spaces, upper case.
                    'part_number' => mb_strtoupper(trim($line['part_number'])),
                    'description' => $line['description'] ?? null,
                    'quantity' => $line['quantity'],
                ]);
            }

            return $order;
        });

        $order->load(['lines', 'dealerLink.dealer', 'manufacturer']);
        $this->notifyPartsDesk($order);

        return response()->json(self::detail($order), 201);
    }

    /**
     * The parts desk moves an order on (PartsOrder::MOVES): started, shipped
     * (carrier and tracking number), or cancelled (a reason, for the dealer).
     */
    public function update(Request $request, int $order): JsonResponse
    {
        $o = PartsOrder::findOrFail($order);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(PartsOrder::MOVES))],
            'carrier' => ['nullable', 'string', 'max:60'],
            'tracking_number' => ['nullable', 'string', 'max:80'],
            'parts_note' => ['nullable', 'string', 'max:2000', 'required_if:status,'.PartsOrder::CANCELLED],
        ], [
            'parts_note.required_if' => 'Tell the dealer why it was cancelled.',
        ]);

        $to = $data['status'];
        if (! in_array($to, PartsOrder::MOVES[$o->status], true)) {
            throw ValidationException::withMessages(['status' => 'A '.str_replace('_', ' ', $o->status).' order can\'t be marked '.str_replace('_', ' ', $to).'.']);
        }

        $o->status = $to;
        $o->handled_by = $request->user()->id;
        $o->parts_note = $data['parts_note'] ?? $o->parts_note;
        match ($to) {
            PartsOrder::IN_PROGRESS => $o->started_at = now(),
            PartsOrder::SHIPPED => $o->forceFill(['shipped_at' => now(), 'carrier' => $data['carrier'] ?? null, 'tracking_number' => $data['tracking_number'] ?? null]),
            PartsOrder::CANCELLED => $o->cancelled_at = now(),
            default => null,
        };
        $o->save();

        $o->load(['lines', 'dealerLink.dealer', 'manufacturer', 'submitter']);
        if ($o->submitter) {
            // A mail problem is logged, never undoes the update.
            rescue(fn () => $o->submitter->notify(new PartsOrderUpdated($o)), report: true);
        }

        return response()->json(self::detail($o));
    }

    /** The dealer takes back an order the parts desk hasn't started. */
    public function cancel(int $order): JsonResponse
    {
        $o = PartsOrder::findOrFail($order);
        if ($o->status !== PartsOrder::SUBMITTED) {
            throw ValidationException::withMessages(['status' => 'The parts desk has already started on this order. Contact them to change it.']);
        }

        $o->forceFill(['status' => PartsOrder::CANCELLED, 'cancelled_at' => now(), 'parts_note' => 'Cancelled by the dealer.'])->save();

        return response()->json(self::detail($o));
    }

    private function notifyPartsDesk(PartsOrder $order): void
    {
        $manufacturer = Tenant::current()->manufacturer();
        $desk = $manufacturer->staff()->with('user')->get()
            ->filter(fn (Membership $m) => Features::allows($manufacturer, $m, 'manage_parts_orders'))
            ->map(fn (Membership $m) => $m->user);

        if ($desk->isNotEmpty()) {
            rescue(fn () => Notification::send($desk, new PartsOrderSubmitted($order)), report: true);
        }
    }

    private static function row(PartsOrder $o): array
    {
        return [
            'id' => $o->id,
            'number' => $o->label(),
            'status' => $o->status,
            'po_number' => $o->po_number,
            'machine_serial' => $o->machine_serial,
            'ship_method' => $o->ship_method,
            'lines_count' => $o->lines_count ?? $o->lines()->count(),
            'dealer' => $o->dealerLink->dealer->name,
            'submitted_by' => $o->submitter?->name,
            'created_at' => $o->created_at?->toIso8601String(),
            'shipped_at' => $o->shipped_at?->toIso8601String(),
        ];
    }

    private static function detail(PartsOrder $o): array
    {
        $o->loadMissing(['lines', 'dealerLink.dealer', 'submitter', 'handler']);
        $tenant = Tenant::current();
        $isDesk = ! $tenant->isDealer() && Features::allows($tenant->manufacturer(), $tenant->membership(), 'manage_parts_orders');

        return self::row($o) + [
            'ship_to' => $o->ship_to,
            'notes' => $o->notes,
            'parts_note' => $o->parts_note,
            'carrier' => $o->carrier,
            'tracking_number' => $o->tracking_number,
            'handled_by' => $o->handler?->name,
            'started_at' => $o->started_at?->toIso8601String(),
            'cancelled_at' => $o->cancelled_at?->toIso8601String(),
            'lines' => $o->lines->map(fn (PartsOrderLine $l) => ['part_number' => $l->part_number, 'description' => $l->description, 'quantity' => $l->quantity])->values(),
            // What this viewer can do with it.
            'next' => $isDesk ? PartsOrder::MOVES[$o->status] : [],
            'can_cancel' => $tenant->isDealer() && $o->status === PartsOrder::SUBMITTED,
        ];
    }
}
