<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\DealerLink;
use App\Models\Membership;
use App\Models\Territory;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Territories (Pro, owner and admins): named groups of dealers, each covered
 * by some sales staff. What that changes for the staff is in
 * App\Support\Territories.
 */
class TerritoryController extends Controller
{
    /** Everything the territories page needs: the territories, who can cover one, and every dealer with its territory. */
    public function index(): JsonResponse
    {
        $territories = Territory::with('reps:id')->withCount('dealerLinks')->orderBy('name')->get();
        $reps = Tenant::current()->manufacturer()->staff()->with('user:id,name')
            ->whereIn('role', Territory::REP_ROLES)->get()->sortBy('user.name')->values();
        $dealers = DealerLink::with('dealer:id,name,city,region')->get()->sortBy('dealer.name')->values();

        return response()->json([
            'territories' => $territories->map(fn (Territory $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'rep_ids' => $t->reps->pluck('id')->sort()->values(),
                'dealer_count' => $t->dealer_links_count,
            ]),
            'reps' => $reps->map(fn (Membership $m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role]),
            'dealers' => $dealers->map(fn (DealerLink $l) => [
                'id' => $l->id,
                'name' => $l->dealer->name,
                'place' => collect([$l->dealer->city, $l->dealer->region])->filter()->implode(', ') ?: null,
                'status' => $l->status,
                'territory_id' => $l->territory_id,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $territory = Territory::create($this->validated($request));

        return response()->json(['id' => $territory->id, 'name' => $territory->name], 201);
    }

    public function update(Request $request, int $territory): JsonResponse
    {
        $territory = Territory::findOrFail($territory);
        $territory->update($this->validated($request, $territory));

        return response()->json(['id' => $territory->id, 'name' => $territory->name]);
    }

    /** Its dealers end up in no territory, and its reps stop being limited by it. */
    public function destroy(int $territory): JsonResponse
    {
        $territory = Territory::findOrFail($territory);
        $territory->delete();

        return response()->json(null, 204);
    }

    /** Sets who covers the territory - sales reps and inside sales only. */
    public function reps(Request $request, int $territory): JsonResponse
    {
        $territory = Territory::findOrFail($territory);
        $data = $request->validate([
            'membership_ids' => ['present', 'array', 'max:500'],
            'membership_ids.*' => ['integer', 'distinct', Rule::exists('memberships', 'id')
                ->where('org_type', Membership::MANUFACTURER)
                ->where('org_id', Tenant::current()->id())
                ->whereIn('role', Territory::REP_ROLES)],
        ], [
            'membership_ids.*.exists' => 'Only your sales reps and inside sales staff can cover a territory.',
        ]);

        $territory->reps()->sync(collect($data['membership_ids'])->mapWithKeys(fn ($id) => [$id => ['manufacturer_id' => $territory->manufacturer_id]]));

        return response()->json(['id' => $territory->id, 'rep_ids' => $territory->reps()->pluck('memberships.id')->sort()->values()]);
    }

    /** Puts a dealer in a territory (moving it out of any other), or in none (null). */
    public function assignDealer(Request $request, int $link): JsonResponse
    {
        $dealerLink = DealerLink::findOrFail($link);
        $data = $request->validate([
            'territory_id' => ['present', 'nullable', 'integer', Rule::exists('territories', 'id')->where('manufacturer_id', Tenant::current()->id())],
        ]);
        // forceFill: territory_id is deliberately not mass-assignable on DealerLink - only this endpoint sets it.
        $dealerLink->forceFill(['territory_id' => $data['territory_id']])->save();

        return response()->json(['id' => $dealerLink->id, 'territory_id' => $dealerLink->territory_id]);
    }

    private function validated(Request $request, ?Territory $territory = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('territories', 'name')->where('manufacturer_id', Tenant::current()->id())->ignore($territory?->id)],
        ], [
            'name.unique' => 'You already have a territory with that name.',
        ]);
    }
}
