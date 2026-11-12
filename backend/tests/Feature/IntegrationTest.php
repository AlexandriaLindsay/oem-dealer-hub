<?php

namespace Tests\Feature;

use App\Integrations\UrlGuard;
use App\Integrations\Webhooks;
use App\Jobs\DeliverWebhook;
use App\Models\ApiKey;
use App\Models\Manufacturer;
use App\Models\PriceList;
use App\Models\ProductModel;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    private User $dealerUser;

    private ProductModel $s400;

    protected function setUp(): void
    {
        parent::setUp();
        // Any host "resolves" to a public address, so tests never touch DNS.
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['93.184.216.34']));

        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme, 'owner');
        $prairie = $this->dealer('Prairie Equipment', $this->acme);
        $this->dealerUser = $this->dealerUser($prairie);
        $this->s400 = $this->productModel($this->acme, 'S-400');
        Tenant::run($this->acme, function () {
            $list = PriceList::create(['name' => 'US', 'currency' => 'USD']);
            $list->forceFill(['is_default' => true])->save();
            $list->items()->create(['item_type' => 'model', 'item_id' => $this->s400->id, 'price_cents' => 4850000]);
        });
    }

    // ---- API keys and the API ----

    public function test_a_key_is_shown_once_and_reads_sales_orders(): void
    {
        $made = $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/api-keys', ['name' => 'Nightly ERP sync', 'abilities' => ['sales_orders:read']])
            ->assertCreated()->assertJsonPath('name', 'Nightly ERP sync');
        $plain = $made->json('key');
        $this->assertStringStartsWith('odh_', $plain);
        $this->assertSame(substr($plain, 0, 12), $made->json('prefix'));

        // Never stored or shown again.
        $this->assertSame(0, ApiKey::withoutTenant()->where('key_hash', $plain)->count());
        $this->actingAs($this->owner)->getJson('/api/m/acme/integrations')->assertJsonMissing(['key' => $plain])->assertJsonPath('api_keys.0.prefix', $made->json('prefix'));

        $this->wonOrder();
        Tenant::run($this->acme, fn () => $this->s400->update(['erp_ref' => 'ITEM-S400']));
        $list = $this->withToken($plain)->getJson('/api/v1/sales-orders')->assertOk()->assertJsonPath('total', 1);
        $list->assertJsonPath('data.0.number', 'SO-1001')
            ->assertJsonPath('data.0.dealer.name', 'Prairie Equipment')
            ->assertJsonPath('data.0.lines.0.item.erp_ref', 'ITEM-S400')
            ->assertJsonPath('data.0.total', '48500.00');
        $this->withToken($plain)->getJson('/api/v1/sales-orders/SO-1001')->assertOk()->assertJsonPath('data.number', 'SO-1001');
        $this->withToken($plain)->getJson('/api/v1/sales-orders/SO-9999')->assertNotFound();
        $this->withToken($plain)->getJson('/api/v1/sales-orders?updated_since='.urlencode(now()->addDay()->toIso8601String()))->assertJsonPath('total', 0);

        $this->assertNotNull(ApiKey::withoutTenant()->first()->last_used_at);
    }

    public function test_bad_revoked_and_unable_keys_are_refused(): void
    {
        $this->getJson('/api/v1/sales-orders')->assertUnauthorized();
        $this->withToken('odh_not-a-real-key')->getJson('/api/v1/sales-orders')->assertUnauthorized();

        $plain = $this->key();
        $id = ApiKey::withoutTenant()->first()->id;
        $this->actingAs($this->owner)->deleteJson("/api/m/acme/integrations/api-keys/{$id}")->assertNoContent();
        $this->withToken($plain)->getJson('/api/v1/sales-orders')->assertUnauthorized();

        // A key without the ability.
        [$key, $noAbility] = Tenant::run($this->acme, fn () => ApiKey::generate('Other', ['something:else'], null));
        Tenant::run($this->acme, fn () => $key->save());
        $this->withToken($noAbility)->getJson('/api/v1/sales-orders')->assertForbidden()->assertJsonPath('code', 'missing_ability');

        // Asking for a made-up ability is refused when making a key.
        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/api-keys', ['name' => 'X', 'abilities' => ['machines:delete']])->assertUnprocessable();
    }

    public function test_the_api_stops_on_starter_and_when_paused(): void
    {
        $plain = $this->key();

        $this->acme->update(['plan' => Plan::STARTER]);
        $this->withToken($plain)->getJson('/api/v1/sales-orders')->assertForbidden()->assertJsonPath('code', 'upgrade_required');
        $this->actingAs($this->owner)->getJson('/api/m/acme/integrations')->assertForbidden();

        $this->acme->update(['plan' => Plan::PRO]);
        $this->withToken($plain)->getJson('/api/v1/sales-orders')->assertOk();
    }

    public function test_only_owners_and_admins_manage_integrations(): void
    {
        $this->actingAs($this->staff($this->acme, 'admin'))->getJson('/api/m/acme/integrations')->assertOk();
        $this->actingAs($this->staff($this->acme, 'inside_sales'))->getJson('/api/m/acme/integrations')->assertForbidden();
        $this->actingAs($this->dealerUser)->getJson('/api/m/acme/integrations')->assertForbidden();
        $this->actingAs($this->dealerUser)->postJson('/api/m/acme/integrations/api-keys', ['name' => 'X', 'abilities' => ['sales_orders:read']])->assertForbidden();
    }

    // ---- Webhooks ----

    public function test_a_won_quote_is_sent_to_the_webhook_signed(): void
    {
        Http::fake(['erp.example.com/*' => Http::response('ok')]);
        $made = $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/webhooks', ['url' => 'https://erp.example.com/hooks/odh', 'events' => ['sales_order.created']])->assertCreated();
        $secret = $made->json('secret');
        $this->assertStringStartsWith('whsec_', $secret);

        $this->wonOrder();

        Http::assertSent(function (SentRequest $r) use ($secret) {
            [$t, $sig] = sscanf($r->header('X-OEMDH-Signature')[0], 't=%d,v1=%s');

            return $r->url() === 'https://erp.example.com/hooks/odh'
                && $r->header('X-OEMDH-Event')[0] === 'sales_order.created'
                && $r['event'] === 'sales_order.created'
                && $r['manufacturer'] === 'acme'
                && $r['data']['number'] === 'SO-1001'
                && $r['data']['dealer']['name'] === 'Prairie Equipment'
                && hash_equals(hash_hmac('sha256', $t.'.'.$r->body(), $secret), $sig);
        });
        $delivery = WebhookDelivery::withoutTenant()->first();
        $this->assertSame(WebhookDelivery::DELIVERED, $delivery->status);
        $this->assertSame(200, $delivery->response_code);
        // The secret is stored encrypted, not as written.
        $this->assertNotSame($secret, Webhook::withoutTenant()->first()->getRawOriginal('secret'));
    }

    public function test_status_and_detail_changes_go_only_to_webhooks_that_want_them(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/webhooks', ['url' => 'https://erp.example.com/moves', 'events' => ['sales_order.status_changed']])->assertCreated();
        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/webhooks', ['url' => 'https://erp.example.com/details', 'events' => ['sales_order.updated']])->assertCreated();
        $order = $this->wonOrder();

        $this->actingAs($this->owner)->postJson("/api/m/acme/sales-orders/{$order}/status", ['status' => 'in_production'])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/m/acme/sales-orders/{$order}", ['erp_ref' => 'SO-ERP-55'])->assertOk()->assertJsonPath('erp_ref', 'SO-ERP-55');

        Http::assertSentCount(2);
        Http::assertSent(fn (SentRequest $r) => $r->url() === 'https://erp.example.com/moves' && $r['data']['status'] === 'in_production');
        Http::assertSent(fn (SentRequest $r) => $r->url() === 'https://erp.example.com/details' && $r['data']['erp_ref'] === 'SO-ERP-55');
    }

    public function test_a_paused_webhook_and_a_starter_plan_send_nothing(): void
    {
        Http::fake();
        $id = $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/webhooks', ['url' => 'https://erp.example.com/h', 'events' => ['sales_order.created']])->json('id');
        $this->actingAs($this->owner)->postJson("/api/m/acme/integrations/webhooks/{$id}", ['active' => false])->assertOk()->assertJsonPath('active', false);
        $this->wonOrder();

        $this->actingAs($this->owner)->postJson("/api/m/acme/integrations/webhooks/{$id}", ['active' => true])->assertOk();
        $this->acme->update(['plan' => Plan::STARTER]);
        $this->wonOrder();

        Http::assertNothingSent();
        $this->assertSame(0, WebhookDelivery::withoutTenant()->count());
    }

    public function test_private_and_plain_http_addresses_are_refused(): void
    {
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => match ($host) {
            'inside.example.com' => ['10.0.0.5'],
            'nowhere.example.com' => [],
            default => ['93.184.216.34'],
        }));
        $add = fn (string $url) => $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/webhooks', ['url' => $url, 'events' => ['sales_order.created']]);

        $add('http://erp.example.com/h')->assertUnprocessable()->assertJsonValidationErrors(['url' => 'https://']);
        $add('https://127.0.0.1/h')->assertUnprocessable()->assertJsonValidationErrors(['url' => 'private network']);
        $add('https://169.254.169.254/latest/meta-data')->assertUnprocessable()->assertJsonValidationErrors('url');
        $add('https://inside.example.com/h')->assertUnprocessable()->assertJsonValidationErrors(['url' => 'private network']);
        $add('https://nowhere.example.com/h')->assertUnprocessable()->assertJsonValidationErrors(['url' => 'can\'t be found']);
        $add('https://user:pass@erp.example.com/h')->assertUnprocessable()->assertJsonValidationErrors('url');
        $add('https://erp.example.com/h')->assertCreated();
    }

    public function test_an_address_that_turns_private_later_is_not_called(): void
    {
        Http::fake();
        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/webhooks', ['url' => 'https://erp.example.com/h', 'events' => ['sales_order.created']])->assertCreated();
        // The name now points inside a private network.
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['192.168.1.20']));

        $this->wonOrder();

        Http::assertNothingSent();
        $delivery = WebhookDelivery::withoutTenant()->first();
        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
        $this->assertStringContainsString('Address not allowed', $delivery->error);
    }

    public function test_a_failed_call_is_retried_then_marked_failed_and_can_be_sent_again(): void
    {
        // Down for three tries (the first send, and two by hand below), then back up.
        Http::fake(['*' => Http::sequence()->push('down', 503)->push('down', 503)->push('down', 503)->push('ok', 200)]);
        $id = $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/webhooks', ['url' => 'https://erp.example.com/h', 'events' => ['sales_order.created']])->json('id');
        $delivery = Tenant::run($this->acme, fn () => Webhooks::deliver(Webhook::find($id), 'ping', ['x' => 1]));

        // A 503 on an early try: waits a minute, then tries again.
        $job = (new DeliverWebhook($this->acme->id, $delivery->id))->withFakeQueueInteractions();
        $job->handle(app(UrlGuard::class));
        $job->assertReleased(60);
        $this->assertSame(WebhookDelivery::PENDING, $delivery->fresh()->status);

        // On the last try it's marked failed, with what the address said.
        $last = (new DeliverWebhook($this->acme->id, $delivery->id))->withFakeQueueInteractions();
        $last->job->attempts = WebhookDelivery::MAX_ATTEMPTS;
        $last->handle(app(UrlGuard::class));
        $last->assertNotReleased();
        $failed = WebhookDelivery::withoutTenant()->find($delivery->id);
        $this->assertSame(WebhookDelivery::FAILED, $failed->status);
        $this->assertSame(503, $failed->response_code);
        $this->assertStringContainsString('503', $failed->error);

        // Sent again by hand: the address is back up.
        $this->actingAs($this->owner)->postJson("/api/m/acme/integrations/deliveries/{$delivery->id}/retry")->assertAccepted();
        $this->assertSame(WebhookDelivery::DELIVERED, $failed->fresh()->status);
        // Only a failed call can be retried.
        $this->actingAs($this->owner)->postJson("/api/m/acme/integrations/deliveries/{$delivery->id}/retry")->assertUnprocessable();
    }

    public function test_test_button_rotate_and_delete(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $made = $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/webhooks', ['url' => 'https://erp.example.com/h', 'events' => ['sales_order.created']]);
        $id = $made->json('id');

        $this->actingAs($this->owner)->postJson("/api/m/acme/integrations/webhooks/{$id}/test")->assertAccepted()->assertJsonPath('event', 'ping');
        Http::assertSent(fn (SentRequest $r) => $r['event'] === 'ping');

        $rotated = $this->actingAs($this->owner)->postJson("/api/m/acme/integrations/webhooks/{$id}/rotate")->assertOk()->json('secret');
        $this->assertNotSame($made->json('secret'), $rotated);
        $this->assertSame($rotated, Tenant::run($this->acme, fn () => Webhook::find($id)->secret));

        $this->actingAs($this->owner)->deleteJson("/api/m/acme/integrations/webhooks/{$id}")->assertNoContent();
        $this->assertSame(0, Webhook::withoutTenant()->count());
    }

    public function test_old_webhook_calls_are_pruned_after_30_days(): void
    {
        Http::fake();
        $id = $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/webhooks', ['url' => 'https://erp.example.com/h', 'events' => ['sales_order.created']])->json('id');
        Tenant::run($this->acme, function () use ($id) {
            Webhooks::deliver(Webhook::find($id), 'ping', [])->forceFill(['created_at' => now()->subDays(31)])->save();
            Webhooks::deliver(Webhook::find($id), 'ping', []);
        });

        $this->artisan('webhooks:prune')->assertSuccessful();
        $this->assertSame(1, WebhookDelivery::withoutTenant()->count());
    }

    // ---- Matching to the ERP's numbers ----

    public function test_dealers_models_and_options_get_their_erp_numbers(): void
    {
        $map = $this->actingAs($this->owner)->getJson('/api/m/acme/integrations/mapping')->assertOk();
        $link = $map->json('dealers.0.id');
        $option = Tenant::run($this->acme, fn () => $this->s400->options()->create(['name' => 'Hydraulic tilt']));

        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/mapping', [
            'dealers' => [$link => ' C-1042 '],
            'models' => [$this->s400->id => 'ITEM-S400'],
            'options' => [$option->id => 'OPT-TILT'],
        ])->assertOk()
            ->assertJsonPath('dealers.0.ref', 'C-1042')
            ->assertJsonPath('models.0.ref', 'ITEM-S400')
            ->assertJsonPath('models.0.options.0.ref', 'OPT-TILT');

        // Empty clears.
        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/mapping', ['models' => [$this->s400->id => '']])->assertJsonPath('models.0.ref', null);
    }

    // ---- Helpers ----

    private function key(): string
    {
        return $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/api-keys', ['name' => 'ERP', 'abilities' => ['sales_orders:read']])->json('key');
    }

    /** A quote sent by the dealer and won by the owner; returns the sales order id. */
    private function wonOrder(): int
    {
        $quote = $this->actingAs($this->dealerUser)->postJson('/api/m/acme/quotes', [
            'customer' => ['contact_name' => 'Dana Hill'],
            'lines' => [['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => 1]],
        ])->assertCreated()->json('id');
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/quotes/{$quote}/status", ['status' => 'sent'])->assertOk();

        return $this->actingAs($this->owner)->postJson("/api/m/acme/quotes/{$quote}/outcome", ['status' => 'won'])->assertOk()->json('sales_order.id');
    }
}
