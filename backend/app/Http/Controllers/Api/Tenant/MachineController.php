<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\MachineEvent;
use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Machines. Staff see and manage every machine at their manufacturer; a dealer
 * sees only the machines they have now (Machine's dealer scope) and can change
 * their status.
 */
class MachineController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(Machine::filterRules() + [
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Machine::with(['productModel.productLine', 'dealerLink.dealer'])
            ->filter($filters)
            ->orderByDesc('id');

        $page = $query->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (Machine $m) => self::row($m)),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function show(int $machine): JsonResponse
    {
        $m = Machine::with(['productModel.productLine', 'dealerLink.dealer', 'events.user'])->findOrFail($machine);

        return response()->json(self::row($m) + ['history' => self::history($m->events)]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = Tenant::current()->id();

        $data = $request->validate([
            'product_model_id' => ['required', 'integer', Rule::exists('product_models', 'id')->where('manufacturer_id', $tenantId)],
            'serial_no' => ['required', 'string', 'max:80', Rule::unique('machines')->where('manufacturer_id', $tenantId)],
            'production_no' => ['nullable', 'string', 'max:80'],
            'dealer_link_id' => ['nullable', 'integer', self::activeLinkRule()],
            'status' => ['nullable', Rule::in(Machine::STATUSES)],
            'built_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'serial_no.unique' => 'There\'s already a machine with that serial number.',
            'dealer_link_id.exists' => self::LINK_MESSAGE,
        ]);

        $link = isset($data['dealer_link_id']) ? DealerLink::findOrFail($data['dealer_link_id']) : null;
        $machine = Machine::add($data, $link, $request->user(), $data['note'] ?? null);

        return response()->json(self::row($machine->load(['productModel.productLine', 'dealerLink.dealer'])), 201);
    }

    public function updateStatus(Request $request, int $machine): JsonResponse
    {
        $m = Machine::findOrFail($machine);

        $data = $request->validate([
            'status' => ['required', Rule::in(Machine::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $m->changeStatus($data['status'], $request->user(), $data['note'] ?? null);

        return $this->show($m->id);
    }

    /** Moves a machine to another dealer, or back to the manufacturer (dealer_link_id null). */
    public function transfer(Request $request, int $machine): JsonResponse
    {
        $m = Machine::findOrFail($machine);

        $data = $request->validate([
            'dealer_link_id' => ['present', 'nullable', 'integer', self::activeLinkRule()],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'dealer_link_id.exists' => self::LINK_MESSAGE,
        ]);

        $link = $data['dealer_link_id'] ? DealerLink::findOrFail($data['dealer_link_id']) : null;
        $m->transferTo($link, $request->user(), $data['note'] ?? null);

        return $this->show($m->id);
    }

    private const LINK_MESSAGE = 'Choose one of your active dealers.';

    /**
     * A dealer at this manufacturer that's active - a suspended dealer can't
     * be sent stock - and, for staff limited by territories, in one of theirs.
     */
    private static function activeLinkRule(): Exists
    {
        $rule = Rule::exists('dealer_links', 'id')
            ->where('manufacturer_id', Tenant::current()->id())
            ->where('status', DealerLink::ACTIVE);
        $territoryLinks = Tenant::current()->territoryLinkIds();

        return $territoryLinks === null ? $rule : $rule->whereIn('id', $territoryLinks);
    }

    private static function row(Machine $m): array
    {
        return [
            'id' => $m->id,
            'serial_no' => $m->serial_no,
            'production_no' => $m->production_no,
            'status' => $m->status,
            'model' => ['id' => $m->productModel->id, 'name' => $m->productModel->name],
            'product_line' => $m->productModel->productLine->name,
            'dealer' => $m->dealerLink ? ['id' => $m->dealerLink->id, 'name' => $m->dealerLink->dealer->name] : null,
            'built_at' => $m->built_at?->toDateString(),
            'shipped_at' => $m->shipped_at?->toDateString(),
            'updated_at' => $m->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The history, in words. A dealer sees where a machine came from and went
     * as "another dealer" - one dealer never learns which competitor had it.
     *
     * @param  Collection<int, MachineEvent>  $events
     */
    private static function history(Collection $events): array
    {
        $tenant = Tenant::current();
        $ownLink = $tenant->dealerLink()?->id;

        $linkIds = $events->whereIn('type', ['created', 'transfer'])->flatMap(fn ($e) => [$e->from, $e->to])->filter()->unique();
        $names = DealerLink::with('dealer')->whereIn('id', $linkIds)->get()->mapWithKeys(fn ($l) => [$l->id => $l->dealer->name]);

        $place = function (?string $linkId) use ($tenant, $ownLink, $names): string {
            if ($linkId === null) {
                return $tenant->manufacturer()->name;
            }
            if ($tenant->isDealer() && (int) $linkId !== $ownLink) {
                return 'another dealer';
            }

            // Not found: deleted, or outside the territories this person is limited to.
            return $names[(int) $linkId] ?? ($tenant->territoryLinkIds() !== null ? 'a dealer outside your territories' : 'a former dealer');
        };

        return $events->map(fn (MachineEvent $e) => [
            'id' => $e->id,
            'type' => $e->type,
            'summary' => match ($e->type) {
                'created' => 'Added, at '.$place($e->to),
                'status' => 'Status changed from '.$e->from.' to '.$e->to,
                'transfer' => 'Moved from '.$place($e->from).' to '.$place($e->to),
                // No customer name here: a later dealer (a trade-in) reads this history too.
                'registration' => $e->to === 'sale' ? 'Registered as sold' : 'Registered as a rental',
                default => $e->type,
            },
            'note' => $e->note,
            'by' => $e->user?->name,
            'at' => $e->created_at?->toIso8601String(),
        ])->values()->all();
    }
}
