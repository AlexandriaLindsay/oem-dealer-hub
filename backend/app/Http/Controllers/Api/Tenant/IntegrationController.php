<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Api\PublicStockController;
use App\Http\Controllers\Controller;
use App\Integrations\UrlGuard;
use App\Integrations\Webhooks;
use App\Jobs\DeliverWebhook;
use App\Models\ApiKey;
use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Platform\StaffWriteAccess;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The Integrations page (feature erp_integration: owner and admin, Pro):
 * API keys, webhooks and their recent calls, and matching dealers, models
 * and options to the ERP's own numbers. Everything is tenant-owned, so it
 * only ever touches this manufacturer's keys and webhooks.
 *
 * A key or a webhook's secret is shown once, in the reply that makes it.
 */
class IntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $manufacturer = Tenant::current()->manufacturer();
        $staffAccess = $request->user()->is_platform_staff ? StaffWriteAccess::current($request->user(), $manufacturer) : null;

        return response()->json([
            'api_base' => rtrim(config('app.url'), '/').'/api/v1',
            'abilities' => ApiKey::ABILITIES,
            'events' => Webhook::EVENTS,
            'api_keys' => ApiKey::whereNull('revoked_at')->orderByDesc('id')->get()->map(fn (ApiKey $k) => self::keyRow($k))->values(),
            'webhooks' => Webhook::orderBy('id')->get()->map(fn (Webhook $w) => self::webhookRow($w))->values(),
            'deliveries' => WebhookDelivery::with('webhook')->orderByDesc('id')->limit(30)->get()->map(fn (WebhookDelivery $d) => self::deliveryRow($d))->values(),
            'erp_owns' => self::erpOwns(),
            // What OEM Dealer Hub staff changed here lately, so the customer always knows.
            'staff_changes' => StaffWriteAccess::recentFor($manufacturer),
            // For staff looking in: until when they can make changes (null: read-only until they say why).
            'staff_access_until' => $staffAccess['until'] ?? null,
        ]);
    }

    /**
     * Who's in charge: {erp_owns: {order_status: true, prices: false}}. On,
     * that kind of data changes only through the API, so the two systems never disagree.
     */
    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'erp_owns' => ['required', 'array'],
            'erp_owns.*' => ['boolean'],
        ]);
        Tenant::current()->manufacturer()->setErpOwns(array_intersect_key($data['erp_owns'], array_flip(Manufacturer::ERP_OWNABLE)));

        return response()->json(['erp_owns' => self::erpOwns()]);
    }

    /** "In stock near you": on or off, and whether only confirmed stock shows (feature public_inventory). */
    public function publicStock(): JsonResponse
    {
        return response()->json(self::publicStockSettings());
    }

    public function savePublicStock(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'confirmed_only' => ['required', 'boolean']]);
        $m = Tenant::current()->manufacturer();
        $m->update(['settings' => array_merge($m->settings ?? [], ['public_inventory' => $data])]);

        return response()->json(self::publicStockSettings());
    }

    private static function publicStockSettings(): array
    {
        $m = Tenant::current()->manufacturer();
        $base = rtrim(config('app.frontend_url'), '/');

        return [
            'enabled' => ! empty($m->settings['public_inventory']['enabled']),
            'confirmed_only' => ! empty($m->settings['public_inventory']['confirmed_only']),
            'confirmed_days' => PublicStockController::CONFIRMED_DAYS,
            'page_url' => $base.'/stock/'.$m->slug,
            'feed_url' => rtrim(config('app.url'), '/').'/api/public/'.$m->slug.'/stock',
        ];
    }

    /** @return array<string, bool> */
    private static function erpOwns(): array
    {
        $m = Tenant::current()->manufacturer();

        return collect(Manufacturer::ERP_OWNABLE)->mapWithKeys(fn ($w) => [$w => $m->erpOwns($w)])->all();
    }

    // ---- API keys ----

    public function storeKey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(array_keys(ApiKey::ABILITIES))],
        ], ['abilities.required' => 'Choose what the key can do.']);

        [$key, $plain] = ApiKey::generate($data['name'], array_unique($data['abilities']), $request->user());
        $key->save();

        // The only time the key itself is ever shown.
        return response()->json(self::keyRow($key) + ['key' => $plain], 201);
    }

    /** Stops a key working at once. Kept (revoked), so "who made this" survives. */
    public function revokeKey(int $key): JsonResponse
    {
        ApiKey::whereNull('revoked_at')->findOrFail($key)->forceFill(['revoked_at' => now()])->save();

        return response()->json(null, 204);
    }

    // ---- Webhooks ----

    public function storeWebhook(Request $request, UrlGuard $guard): JsonResponse
    {
        $data = $this->validatedWebhook($request, $guard);
        $webhook = new Webhook(['url' => $data['url'], 'events' => array_values(array_unique($data['events'])), 'active' => true]);
        $secret = Webhook::newSecret();
        $webhook->forceFill(['secret' => $secret, 'created_by' => $request->user()->id])->save();

        return response()->json(self::webhookRow($webhook) + ['secret' => $secret], 201);
    }

    public function updateWebhook(Request $request, int $webhook, UrlGuard $guard): JsonResponse
    {
        $w = Webhook::findOrFail($webhook);
        $data = $this->validatedWebhook($request, $guard, partial: true);
        $w->update(array_filter([
            'url' => $data['url'] ?? null,
            'events' => isset($data['events']) ? array_values(array_unique($data['events'])) : null,
            'active' => $data['active'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json(self::webhookRow($w));
    }

    /** A new signing secret; the old one stops working at once. */
    public function rotateSecret(int $webhook): JsonResponse
    {
        $w = Webhook::findOrFail($webhook);
        $secret = Webhook::newSecret();
        $w->forceFill(['secret' => $secret])->save();

        return response()->json(self::webhookRow($w) + ['secret' => $secret]);
    }

    public function destroyWebhook(int $webhook): JsonResponse
    {
        Webhook::findOrFail($webhook)->delete();

        return response()->json(null, 204);
    }

    /** "Send a test": a ping, whatever events the webhook is set to - to check the address and the signature. */
    public function testWebhook(int $webhook): JsonResponse
    {
        $w = Webhook::findOrFail($webhook);
        $delivery = Webhooks::deliver($w, 'ping', ['message' => 'A test from OEM Dealer Hub. If you can read this, your webhook works.']);

        return response()->json(self::deliveryRow($delivery->load('webhook')), 202);
    }

    /** Sends a failed call again, from the start of its retries. */
    public function retryDelivery(int $delivery): JsonResponse
    {
        $d = WebhookDelivery::with('webhook')->findOrFail($delivery);
        if ($d->status !== WebhookDelivery::FAILED) {
            return response()->json(['message' => 'Only a failed call can be sent again.', 'errors' => ['status' => ['Only a failed call can be sent again.']]], 422);
        }
        $d->forceFill(['status' => WebhookDelivery::PENDING, 'attempts' => 0, 'error' => null, 'response_code' => null])->save();
        DeliverWebhook::dispatch(Tenant::current()->id(), $d->id);

        return response()->json(self::deliveryRow($d), 202);
    }

    // ---- Matching to the ERP's numbers ----

    /** Dealers, models and options with the number the ERP uses for each. */
    public function mapping(): JsonResponse
    {
        return response()->json([
            'dealers' => DealerLink::with('dealer')->get()->sortBy(fn (DealerLink $l) => $l->dealer->name)
                ->map(fn (DealerLink $l) => ['id' => $l->id, 'name' => $l->dealer->name, 'ref' => $l->dealer_code])->values(),
            'models' => ProductModel::with(['options' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get()
                ->map(fn (ProductModel $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'ref' => $m->erp_ref,
                    'options' => $m->options->map(fn (ProductOption $o) => ['id' => $o->id, 'name' => $o->name, 'ref' => $o->erp_ref])->values(),
                ])->values(),
        ]);
    }

    /** Saves any of them at once: {dealers: {id: "C-1042"}, models: {...}, options: {...}}; empty clears. */
    public function saveMapping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dealers' => ['array'],
            'dealers.*' => ['nullable', 'string', 'max:50'],
            'models' => ['array'],
            'models.*' => ['nullable', 'string', 'max:100'],
            'options' => ['array'],
            'options.*' => ['nullable', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($data) {
            // Each row found inside this manufacturer (and the person's dealer scope): an id from elsewhere is just "not found".
            foreach ($data['dealers'] ?? [] as $id => $ref) {
                DealerLink::findOrFail((int) $id)->update(['dealer_code' => self::clean($ref)]);
            }
            foreach ($data['models'] ?? [] as $id => $ref) {
                ProductModel::findOrFail((int) $id)->update(['erp_ref' => self::clean($ref)]);
            }
            foreach ($data['options'] ?? [] as $id => $ref) {
                ProductOption::findOrFail((int) $id)->update(['erp_ref' => self::clean($ref)]);
            }
        });

        return $this->mapping();
    }

    // ---- Helpers ----

    /** @return array{url?: string, events?: list<string>, active?: bool} */
    private function validatedWebhook(Request $request, UrlGuard $guard, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'url' => [$required, 'string', 'max:500', function (string $attribute, mixed $value, Closure $fail) use ($guard) {
                if ($problem = $guard->problem((string) $value)) {
                    $fail($problem);
                }
            }],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(Webhook::EVENTS))],
            'active' => ['sometimes', 'boolean'],
        ], ['events.required' => 'Choose at least one thing to be told about.', 'events.min' => 'Choose at least one thing to be told about.']);
    }

    private static function clean(?string $ref): ?string
    {
        $ref = trim((string) $ref);

        return $ref === '' ? null : $ref;
    }

    private static function keyRow(ApiKey $k): array
    {
        return [
            'id' => $k->id,
            'name' => $k->name,
            'prefix' => $k->prefix,
            'abilities' => $k->abilities,
            'last_used_at' => $k->last_used_at?->toIso8601String(),
            'created_at' => $k->created_at?->toIso8601String(),
        ];
    }

    private static function webhookRow(Webhook $w): array
    {
        return [
            'id' => $w->id,
            'url' => $w->url,
            'events' => $w->events,
            'active' => $w->active,
            'created_at' => $w->created_at?->toIso8601String(),
        ];
    }

    private static function deliveryRow(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id,
            'uuid' => $d->uuid,
            'webhook_url' => $d->webhook?->url,
            'event' => $d->event,
            'status' => $d->status,
            'attempts' => $d->attempts,
            'response_code' => $d->response_code,
            'error' => $d->error,
            'created_at' => $d->created_at?->toIso8601String(),
            'delivered_at' => $d->delivered_at?->toIso8601String(),
        ];
    }
}
