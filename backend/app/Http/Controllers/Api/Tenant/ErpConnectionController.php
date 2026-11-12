<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Integrations\ErpClient;
use App\Integrations\ErpException;
use App\Integrations\ErpSync;
use App\Integrations\UrlGuard;
use App\Jobs\PushSalesOrderToErp;
use App\Jobs\RunErpSync;
use App\Models\ErpConnection;
use App\Models\ErpSyncRun;
use App\Models\Machine;
use App\Models\PriceList;
use App\Models\SalesOrder;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Connect to your ERP's API" on the Integrations page (erp_integration:
 * owner and admin, Pro): where their ERP is, how to sign in, and for each
 * sync where things are in its JSON. Tenant-owned, one per manufacturer.
 *
 * The token is never sent back, only whether one is set and its last four
 * characters, so it can be recognised.
 */
class ErpConnectionController extends Controller
{
    /** Headers that would break the request, or aren't for signing in. */
    private const RESERVED_HEADERS = ['host', 'content-type', 'content-length', 'cookie', 'connection', 'transfer-encoding', 'user-agent', 'idempotency-key'];

    public function show(): JsonResponse
    {
        return response()->json([
            'connection' => ($c = ErpConnection::first()) ? self::row($c) : null,
            'syncs' => ErpConnection::SYNCS,
            'fields' => ErpConnection::FIELDS,
            'order_statuses' => SalesOrder::STATUSES,
            'machine_statuses' => Machine::STATUSES,
            'price_lists' => PriceList::orderBy('name')->get(['id', 'name'])->map(fn (PriceList $p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'runs' => ErpSyncRun::with('salesOrder')->orderByDesc('id')->limit(30)->get()->map(fn (ErpSyncRun $r) => self::runRow($r))->values(),
        ]);
    }

    public function save(Request $request, UrlGuard $guard): JsonResponse
    {
        $pathRule = ['nullable', 'string', 'max:500'];
        $fieldRule = ['nullable', 'string', 'max:200'];
        $data = $request->validate([
            'base_url' => ['required', 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail) use ($guard) {
                if ($problem = $guard->problem((string) $value)) {
                    $fail($problem);
                }
            }],
            'auth_header' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9-]+$/', function (string $attribute, mixed $value, Closure $fail) {
                // Header names ignore case, so "Host" is "host".
                if (in_array(strtolower((string) $value), self::RESERVED_HEADERS, true)) {
                    $fail('That header can\'t carry a token. Most ERPs use Authorization or X-API-Key.');
                }
            }],
            'auth_prefix' => ['nullable', 'string', 'max:30', 'regex:/^[\x21-\x7E]+$/'],
            'token' => ['nullable', 'string', 'max:4000', 'regex:/^[\x20-\x7E]+$/'],
            'clear_token' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],

            'syncs.orders_push.on' => ['sometimes', 'boolean'],
            'syncs.orders_push.path' => ['required_if_accepted:syncs.orders_push.on', ...$pathRule],
            'syncs.orders_push.ref_field' => $fieldRule,

            ...collect(ErpConnection::PULLS)->flatMap(fn (string $sync) => [
                "syncs.$sync.on" => ['sometimes', 'boolean'],
                "syncs.$sync.path" => ["required_if_accepted:syncs.$sync.on", ...$pathRule],
                "syncs.$sync.list" => $fieldRule,
                "syncs.$sync.next" => $fieldRule,
                ...collect(ErpConnection::FIELDS[$sync])->mapWithKeys(fn (bool $required, string $field) => [
                    "syncs.$sync.fields.$field" => $required ? ["required_if_accepted:syncs.$sync.on", ...$fieldRule] : $fieldRule,
                ])->all(),
            ])->all(),

            'syncs.order_status.match' => ['nullable', Rule::in(['erp_ref', 'number'])],
            'syncs.order_status.statuses' => ['nullable', 'array', 'max:50'],
            'syncs.order_status.statuses.*.erp' => ['nullable', 'string', 'max:100'],
            'syncs.order_status.statuses.*.ours' => ['required', Rule::in(SalesOrder::STATUSES)],
            'syncs.machines.statuses' => ['nullable', 'array', 'max:50'],
            'syncs.machines.statuses.*.erp' => ['nullable', 'string', 'max:100'],
            'syncs.machines.statuses.*.ours' => ['required', Rule::in(Machine::STATUSES)],
            'syncs.machines.add_models' => ['sometimes', 'boolean'],
            'syncs.prices.price_list_id' => ['required_if_accepted:syncs.prices.on', 'nullable', 'integer', Rule::exists('price_lists', 'id')->where('manufacturer_id', Tenant::current()->id())],
        ], [
            'required_if_accepted' => 'Fill this in to turn this sync on.',
            'auth_header.regex' => 'A header name has only letters, numbers and dashes.',
        ]);

        $connection = ErpConnection::first() ?? new ErpConnection;
        $connection->fill([
            'base_url' => rtrim(trim($data['base_url']), '/'),
            'auth_header' => trim((string) ($data['auth_header'] ?? '')) ?: 'Authorization',
            'auth_prefix' => trim((string) ($data['auth_prefix'] ?? '')) ?: null,
            'syncs' => self::cleanSyncs($data['syncs'] ?? []),
            'active' => $data['active'] ?? $connection->active ?? true,
        ]);
        // An empty token box keeps the one already saved, since it's never shown.
        if (! empty($data['clear_token'])) {
            $connection->token = null;
        } elseif (trim((string) ($data['token'] ?? '')) !== '') {
            $connection->token = trim($data['token']);
        }
        if (! $connection->exists) {
            $connection->created_by = $request->user()->id;
        }
        $connection->save();

        return response()->json(self::row($connection));
    }

    /** Stops every sync. Its past runs stay, for 30 days, to see what happened. */
    public function destroy(): JsonResponse
    {
        ErpConnection::first()?->delete();

        return response()->json(null, 204);
    }

    /** "Test the connection": a GET of their address, with the token, to check it answers. */
    public function test(Request $request, UrlGuard $guard): JsonResponse
    {
        $data = $request->validate(['path' => ['nullable', 'string', 'max:500']]);
        $connection = ErpConnection::firstOrFail();
        $client = new ErpClient($connection, $guard);
        try {
            $status = $client->check($client->url($data['path'] ?? ''));
        } catch (ErpException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'message' => "Connected. Your ERP answered {$status}."]);
    }

    /** "Try it": the first page of a sync, with what it would do. Writes nothing. */
    public function preview(string $sync, ErpSync $erp): JsonResponse
    {
        $connection = ErpConnection::firstOrFail();
        if (trim((string) ($connection->sync($sync)['path'] ?? '')) === '') {
            return response()->json(['message' => 'Save a path for this sync first.', 'errors' => ['path' => ['Save a path for this sync first.']]], 422);
        }
        try {
            return response()->json(['ok' => true] + $erp->preview($connection, $sync));
        } catch (ErpException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    /** "Sync now", or "Fetch everything again" (full: forgets where it got to). Runs on the queue. */
    public function syncNow(Request $request, string $sync): JsonResponse
    {
        $data = $request->validate(['full' => ['sometimes', 'boolean']]);
        $connection = ErpConnection::firstOrFail();
        if (! $connection->syncOn($sync)) {
            return response()->json(['message' => 'Turn this sync on and save first.', 'errors' => ['sync' => ['Turn this sync on and save first.']]], 422);
        }
        RunErpSync::dispatch(Tenant::current()->id(), $sync, (bool) ($data['full'] ?? false));

        return response()->json(['message' => 'Started. It shows under Recent syncs when it\'s done.'], 202);
    }

    /** Sends a failed order to their ERP again, from the start of its retries. */
    public function retry(int $run): JsonResponse
    {
        $r = ErpSyncRun::findOrFail($run);
        if ($r->sync !== 'orders_push' || $r->status !== ErpSyncRun::FAILED) {
            return response()->json(['message' => 'Only an order that failed to send can be sent again.', 'errors' => ['status' => ['Only an order that failed to send can be sent again.']]], 422);
        }
        $r->forceFill(['status' => ErpSyncRun::RUNNING, 'attempts' => 0, 'message' => null, 'finished_at' => null])->save();
        PushSalesOrderToErp::dispatch(Tenant::current()->id(), $r->id);

        return response()->json(self::runRow($r->fresh('salesOrder')), 202);
    }

    // ---- Helpers ----

    /** Only the settings we know, in a fixed shape, so nothing else ends up stored. */
    private static function cleanSyncs(array $in): array
    {
        $text = fn (mixed $v) => ($v = trim((string) $v)) === '' ? null : $v;
        $pairs = fn (?array $rows) => collect($rows ?? [])->map(fn ($p) => ['erp' => trim((string) $p['erp']), 'ours' => $p['ours']])->filter(fn ($p) => $p['erp'] !== '')->values()->all();

        $out = ['orders_push' => [
            'on' => (bool) ($in['orders_push']['on'] ?? false),
            'path' => $text($in['orders_push']['path'] ?? null),
            'ref_field' => $text($in['orders_push']['ref_field'] ?? null),
        ]];
        foreach (ErpConnection::PULLS as $sync) {
            $s = $in[$sync] ?? [];
            $out[$sync] = [
                'on' => (bool) ($s['on'] ?? false),
                'path' => $text($s['path'] ?? null),
                'list' => $text($s['list'] ?? null),
                'next' => $text($s['next'] ?? null),
                'fields' => collect(array_keys(ErpConnection::FIELDS[$sync]))->mapWithKeys(fn ($f) => [$f => $text($s['fields'][$f] ?? null)])->all(),
            ];
        }
        $out['order_status']['match'] = ($in['order_status']['match'] ?? null) === 'number' ? 'number' : 'erp_ref';
        $out['order_status']['statuses'] = $pairs($in['order_status']['statuses'] ?? null);
        $out['machines']['statuses'] = $pairs($in['machines']['statuses'] ?? null);
        $out['machines']['add_models'] = (bool) ($in['machines']['add_models'] ?? false);
        $out['prices']['price_list_id'] = isset($in['prices']['price_list_id']) ? (int) $in['prices']['price_list_id'] : null;

        return $out;
    }

    private static function row(ErpConnection $c): array
    {
        $token = (string) $c->token;

        return [
            'base_url' => $c->base_url,
            'auth_header' => $c->auth_header,
            'auth_prefix' => $c->auth_prefix,
            'token_set' => $token !== '',
            'token_hint' => strlen($token) >= 12 ? '…'.substr($token, -4) : null,
            'syncs' => $c->syncs,
            'active' => $c->active,
            'last_since' => collect(ErpConnection::PULLS)->mapWithKeys(fn ($s) => [$s => $c->since($s)])->all(),
        ];
    }

    private static function runRow(ErpSyncRun $r): array
    {
        return [
            'id' => $r->id,
            'sync' => $r->sync,
            'sales_order' => $r->salesOrder ? ['id' => $r->salesOrder->id, 'number' => $r->salesOrder->label()] : null,
            'status' => $r->status,
            'attempts' => $r->attempts,
            'counts' => $r->counts,
            'message' => $r->message,
            'problems' => $r->problems ?? [],
            'created_at' => $r->created_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
        ];
    }
}
