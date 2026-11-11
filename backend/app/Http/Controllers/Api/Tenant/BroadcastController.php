<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Models\BroadcastRead;
use App\Models\DealerLink;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\BroadcastSent;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Broadcasts: the manufacturer's staff send announcements to dealers (feature
 * broadcasts - Pro); dealers read the ones sent to them (feature
 * announcements; Broadcast's dealer scope does the filtering).
 */
class BroadcastController extends Controller
{
    // ---- Staff ----

    public function index(): JsonResponse
    {
        $broadcasts = Broadcast::with('sender')->withCount('recipients')->orderByDesc('id')->limit(200)->get();
        $readers = BroadcastRead::whereIn('broadcast_id', $broadcasts->pluck('id'))
            ->selectRaw('broadcast_id, count(distinct dealer_link_id) as dealers')
            ->groupBy('broadcast_id')->pluck('dealers', 'broadcast_id');

        return response()->json($broadcasts->map(fn (Broadcast $b) => self::row($b) + [
            'recipients_count' => $b->recipients_count,
            // Dealers where at least one person has opened it.
            'read_by_dealers' => (int) ($readers[$b->id] ?? 0),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = Tenant::current()->id();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:10000'],
            'audience' => ['required', Rule::in([Broadcast::ALL, Broadcast::SELECTED])],
            'dealer_link_ids' => ['required_if:audience,'.Broadcast::SELECTED, 'array', 'max:5000'],
            'dealer_link_ids.*' => ['integer', 'distinct', Rule::exists('dealer_links', 'id')->where('manufacturer_id', $tenantId)->where('status', DealerLink::ACTIVE)],
            'email' => ['boolean'],
        ], [
            'dealer_link_ids.required_if' => 'Choose at least one dealer.',
            'dealer_link_ids.*.exists' => 'That dealer isn\'t one of your active dealers.',
        ]);

        $links = $data['audience'] === Broadcast::ALL
            ? DealerLink::where('status', DealerLink::ACTIVE)->pluck('id')
            : collect($data['dealer_link_ids']);
        if ($links->isEmpty()) {
            abort(response()->json(['message' => 'There are no active dealers to send this to yet.', 'errors' => ['audience' => ['There are no active dealers to send this to yet.']]], 422));
        }

        $broadcast = DB::transaction(function () use ($data, $links, $request) {
            $b = new Broadcast(['title' => $data['title'], 'body' => $data['body'], 'audience' => $data['audience'], 'emailed' => (bool) ($data['email'] ?? false)]);
            $b->sent_by = $request->user()->id;
            $b->save();
            foreach ($links as $id) {
                $b->recipients()->create(['dealer_link_id' => $id]);
            }

            return $b;
        });

        if ($broadcast->emailed) {
            $this->email($broadcast, $links->all());
        }

        return response()->json($this->staffDetail($broadcast), 201);
    }

    public function show(int $broadcast): JsonResponse
    {
        return response()->json($this->staffDetail(Broadcast::findOrFail($broadcast)));
    }

    /** Takes it down for dealers. It stays in the manufacturer's history; emails already sent can't be unsent. */
    public function withdraw(int $broadcast): JsonResponse
    {
        $b = Broadcast::findOrFail($broadcast);
        $b->forceFill(['withdrawn_at' => $b->withdrawn_at ?? now()])->save();

        return response()->json($this->staffDetail($b));
    }

    // ---- Dealers ----

    public function announcements(Request $request): JsonResponse
    {
        $read = BroadcastRead::where('user_id', $request->user()->id)->pluck('broadcast_id')->flip();
        $items = Broadcast::orderByDesc('id')->limit(200)->get();

        return response()->json($items->map(fn (Broadcast $b) => [
            'id' => $b->id,
            'title' => $b->title,
            'excerpt' => mb_strimwidth(preg_replace('/\s+/', ' ', $b->body), 0, 160, '…'),
            'sent_at' => $b->created_at?->toIso8601String(),
            'read' => isset($read[$b->id]),
        ]));
    }

    /** Opening an announcement marks it read, for this person. */
    public function announcement(Request $request, int $broadcast): JsonResponse
    {
        $b = Broadcast::findOrFail($broadcast);
        // Through the relation, so broadcast_id is set (it isn't mass-assignable).
        $b->reads()->firstOrCreate(
            ['user_id' => $request->user()->id],
            ['dealer_link_id' => Tenant::current()->dealerLink()?->id, 'read_at' => now()],
        );

        return response()->json(self::row($b) + ['body' => $b->body]);
    }

    /** How many announcements this person hasn't opened (dashboard). */
    public static function unreadCount(int $userId): int
    {
        return Broadcast::whereDoesntHave('reads', fn ($r) => $r->where('user_id', $userId))->count();
    }

    // ---- Helpers ----

    /** Everyone at the chosen dealers, emailed in the background (queued). */
    private function email(Broadcast $b, array $linkIds): void
    {
        $manufacturer = Tenant::current()->manufacturer();
        $dealerIds = DealerLink::whereIn('id', $linkIds)->pluck('dealer_id');
        $users = User::whereIn('id', Membership::where('org_type', Membership::DEALER)->whereIn('org_id', $dealerIds)->pluck('user_id'))->get();

        if ($users->isNotEmpty()) {
            $url = rtrim(config('app.frontend_url'), '/').'/m/'.$manufacturer->slug.'/announcements/'.$b->id;
            rescue(fn () => Notification::send($users, new BroadcastSent($manufacturer->name, $b->title, $b->body, $url)), report: true);
        }
    }

    private function staffDetail(Broadcast $b): array
    {
        $b->loadMissing(['sender', 'recipients.dealerLink.dealer']);
        $reads = $b->reads()->with('user:id,name')->orderBy('read_at')->get()->groupBy('dealer_link_id');

        return self::row($b) + [
            'body' => $b->body,
            'audience' => $b->audience,
            'emailed' => $b->emailed,
            'withdrawn_at' => $b->withdrawn_at?->toIso8601String(),
            'recipients' => $b->recipients->map(fn ($r) => [
                'dealer_link_id' => $r->dealer_link_id,
                'dealer' => $r->dealerLink?->dealer->name ?? 'A former dealer',
                'read_by' => ($reads[$r->dealer_link_id] ?? collect())->map(fn ($read) => ['name' => $read->user?->name, 'at' => $read->read_at->toIso8601String()])->values(),
            ])->sortBy('dealer')->values(),
        ];
    }

    private static function row(Broadcast $b): array
    {
        return [
            'id' => $b->id,
            'title' => $b->title,
            'sent_at' => $b->created_at?->toIso8601String(),
            'sent_by' => $b->sender?->name,
        ];
    }
}
