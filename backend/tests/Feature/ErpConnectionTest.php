<?php

namespace Tests\Feature;

use App\Integrations\ErpSync;
use App\Integrations\UrlGuard;
use App\Models\DealerLink;
use App\Models\ErpConnection;
use App\Models\ErpSyncRun;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\PriceList;
use App\Models\ProductModel;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** OEM Dealer Hub calling the manufacturer's ERP API (ErpSync). */
class ErpConnectionTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    private User $dealerUser;

    private DealerLink $prairie;

    private ProductModel $s400;

    private PriceList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['93.184.216.34']));

        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme, 'owner');
        $dealer = $this->dealer('Prairie Equipment', $this->acme);
        $this->dealerUser = $this->dealerUser($dealer);
        $this->prairie = DealerLink::withoutTenant()->where('dealer_id', $dealer->id)->first();
        $this->s400 = $this->productModel($this->acme, 'S-400');
        $this->list = Tenant::run($this->acme, function () {
            $this->s400->update(['erp_ref' => 'ITEM-S400']);
            $this->prairie->update(['dealer_code' => 'C-1042']);
            $list = PriceList::create(['name' => 'US', 'currency' => 'USD']);
            $list->forceFill(['is_default' => true])->save();
            $list->items()->create(['item_type' => 'model', 'item_id' => $this->s400->id, 'price_cents' => 4850000]);

            return $list;
        });
    }

    public function test_the_token_is_kept_secret_and_bad_addresses_are_refused(): void
    {
        $this->connect()->assertOk()->assertJsonPath('token_set', true)->assertJsonPath('token_hint', '…cret')->assertJsonMissing(['token' => 'erp-token-very-secret']);
        $this->assertSame('erp-token-very-secret', Tenant::run($this->acme, fn () => ErpConnection::first()->token));
        $this->assertStringNotContainsString('erp-token-very-secret', (string) ErpConnection::withoutTenant()->toBase()->value('token'));

        // Saving again with the box empty keeps it.
        $this->connect(['token' => ''])->assertJsonPath('token_set', true);
        $this->actingAs($this->owner)->getJson('/api/m/acme/integrations/erp')->assertOk()->assertJsonMissing(['token' => 'erp-token-very-secret'])->assertJsonPath('connection.base_url', 'https://erp.acme.example/api');

        $this->connect(['base_url' => 'http://erp.acme.example'])->assertJsonValidationErrors('base_url');
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['10.0.0.5']));
        $this->connect(['base_url' => 'https://erp.internal'])->assertJsonValidationErrors('base_url');
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['93.184.216.34']));
        $this->connect(['auth_header' => 'Host'])->assertJsonValidationErrors('auth_header');
        $this->connect(['syncs' => ['prices' => ['on' => true, 'path' => '/prices']]])->assertJsonValidationErrors(['syncs.prices.fields.item', 'syncs.prices.price_list_id']);
    }

    public function test_a_new_sales_order_is_sent_to_the_erp_and_gets_its_number(): void
    {
        Http::fake(['erp.acme.example/*' => Http::response(['result' => ['OrderNo' => 'SO-55120']], 201)]);
        $this->connect(['syncs' => ['orders_push' => ['on' => true, 'path' => '/salesOrders', 'ref_field' => 'result.OrderNo']]]);

        $id = $this->wonOrder();

        Http::assertSent(fn (SentRequest $r) => $r->method() === 'POST'
            && $r->url() === 'https://erp.acme.example/api/salesOrders'
            && $r->header('X-API-Key') === ['erp-token-very-secret']
            && $r->header('Idempotency-Key') === ['oemdh-sales-order-'.$id]
            && $r['number'] === 'SO-1001' && $r['dealer']['dealer_code'] === 'C-1042' && $r['lines'][0]['item']['erp_ref'] === 'ITEM-S400');
        $this->assertSame('SO-55120', SalesOrder::withoutTenant()->find($id)->erp_ref);
        $run = ErpSyncRun::withoutTenant()->sole();
        $this->assertSame([ErpSyncRun::OK, 'orders_push', $id], [$run->status, $run->sync, $run->sales_order_id]);
    }

    public function test_a_refused_order_fails_at_once_and_can_be_sent_again(): void
    {
        Http::fakeSequence('erp.acme.example/*')->push(['error' => 'bad token'], 401)->push(['OrderNo' => 'A-1'], 200);
        $this->connect(['syncs' => ['orders_push' => ['on' => true, 'path' => '/salesOrders', 'ref_field' => 'OrderNo']]]);
        $id = $this->wonOrder();

        $run = ErpSyncRun::withoutTenant()->sole();
        $this->assertSame(ErpSyncRun::FAILED, $run->status);
        $this->assertStringContainsString('401', $run->message);
        $this->actingAs($this->owner)->getJson('/api/m/acme/integrations/erp')->assertJsonPath('runs.0.sales_order.number', 'SO-1001')->assertJsonPath('runs.0.status', 'failed');

        $this->actingAs($this->owner)->postJson("/api/m/acme/integrations/erp/runs/{$run->id}/retry")->assertAccepted();
        $this->assertSame(ErpSyncRun::OK, $run->fresh()->status);
        $this->assertSame('A-1', SalesOrder::withoutTenant()->find($id)->erp_ref);
        $this->actingAs($this->owner)->postJson("/api/m/acme/integrations/erp/runs/{$run->id}/retry")->assertUnprocessable();
    }

    public function test_order_status_is_fetched_page_by_page_and_moves_orders_step_by_step(): void
    {
        $id = $this->wonOrder();
        Tenant::run($this->acme, fn () => SalesOrder::find($id)->updateDetails(['erp_ref' => 'SO-55120']));
        Http::fake([
            'erp.acme.example/api/orders?since=*' => Http::response(['value' => [['No' => 'SO-99999', 'Status' => 'Released']], '@odata.nextLink' => 'https://erp.acme.example/api/orders/page2']),
            'erp.acme.example/api/orders/page2' => Http::response(['value' => [['No' => 'SO-55120', 'Status' => 'Delivered'], ['No' => 'SO-55120', 'Status' => 'Picking']]]),
        ]);
        $this->connect(['syncs' => ['order_status' => [
            'on' => true, 'path' => '/orders?since={since}', 'list' => 'value', 'next' => '@odata.nextLink',
            'fields' => ['order' => 'No', 'status' => 'Status'],
            'statuses' => [['erp' => 'released', 'ours' => 'in_production'], ['erp' => 'Delivered', 'ours' => 'delivered']],
        ]]]);

        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/webhooks', ['url' => 'https://hooks.acme.example/h', 'events' => ['sales_order.status_changed']])->assertCreated();

        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/erp/sync/order_status')->assertAccepted();

        $order = SalesOrder::withoutTenant()->find($id);
        // received → shipped → delivered: it passed through shipped, stamping it.
        $this->assertSame(SalesOrder::DELIVERED, $order->status);
        $this->assertNotNull($order->shipped_at);
        $run = ErpSyncRun::withoutTenant()->where('sync', 'order_status')->sole();
        $this->assertSame(ErpSyncRun::PARTIAL, $run->status);
        $this->assertSame(['moved' => 1, 'unchanged' => 1, 'not_ours' => 1, 'errors' => 0], $run->counts);
        $this->assertStringContainsString('"Picking"', implode(' ', $run->problems));
        Http::assertSent(fn (SentRequest $r) => str_contains($r->url(), 'since=1970-01-01T00%3A00%3A00Z'));

        // Webhooks for those moves say they came from the ERP.
        $sources = WebhookDelivery::withoutTenant()->where('event', 'sales_order.status_changed')->get()->pluck('payload.source')->unique()->values()->all();
        $this->assertSame(['erp'], $sources);

        // Next time it asks only for what changed.
        $this->assertNotNull(Tenant::run($this->acme, fn () => ErpConnection::first()->since('order_status')));
    }

    public function test_a_next_page_on_another_host_never_gets_the_token(): void
    {
        Http::fake(['erp.acme.example/*' => Http::response(['items' => [], 'next' => 'https://evil.example/steal'])]);
        $this->connect(['syncs' => ['machines' => ['on' => true, 'path' => '/machines', 'list' => 'items', 'next' => 'next', 'fields' => ['serial_no' => 'Serial']]]]);

        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/erp/sync/machines')->assertAccepted();

        Http::assertNotSent(fn (SentRequest $r) => str_contains($r->url(), 'evil.example'));
        $run = ErpSyncRun::withoutTenant()->sole();
        $this->assertSame(ErpSyncRun::FAILED, $run->status);
        $this->assertStringContainsString('evil.example', $run->message);
    }

    public function test_prices_are_fetched_onto_a_list_skipping_items_we_dont_sell(): void
    {
        Http::fake(['erp.acme.example/*' => Http::response([['ItemNo' => 'ITEM-S400', 'UnitPrice' => 49999.5], ['ItemNo' => 'SCREW-12', 'UnitPrice' => 0.12]])]);
        $this->connect(['syncs' => ['prices' => ['on' => true, 'path' => '/prices', 'price_list_id' => $this->list->id, 'fields' => ['item' => 'ItemNo', 'price' => 'UnitPrice']]]]);

        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/erp/sync/prices')->assertAccepted();

        $this->assertSame(4999950, $this->list->items()->withoutGlobalScopes()->value('price_cents'));
        $run = ErpSyncRun::withoutTenant()->sole();
        $this->assertSame([ErpSyncRun::OK, ['updated' => 1, 'not_ours' => 1]], [$run->status, $run->counts]);
    }

    public function test_one_bad_price_saves_nothing(): void
    {
        Http::fake(['erp.acme.example/*' => Http::response([['ItemNo' => 'ITEM-S400', 'UnitPrice' => 'call us']])]);
        $this->connect(['syncs' => ['prices' => ['on' => true, 'path' => '/prices', 'price_list_id' => $this->list->id, 'fields' => ['item' => 'ItemNo', 'price' => 'UnitPrice']]]]);

        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/erp/sync/prices')->assertAccepted();

        $this->assertSame(4850000, $this->list->items()->withoutGlobalScopes()->value('price_cents'));
        $run = ErpSyncRun::withoutTenant()->sole();
        $this->assertSame(ErpSyncRun::FAILED, $run->status);
        $this->assertStringContainsString('call us', implode(' ', $run->problems));
        // A failed run is tried again from the same place.
        $this->assertNull(Tenant::run($this->acme, fn () => ErpConnection::first()->since('prices')));
    }

    public function test_machines_are_fetched_by_serial_each_row_on_its_own(): void
    {
        $this->machine($this->acme, 'AS4-1', null, 'inventory', $this->s400);
        Http::fake(['erp.acme.example/*' => Http::response(['data' => ['rows' => [
            ['Serial' => 'AS4-1', 'Customer' => ['No' => 'C-1042'], 'State' => 'Sold'],
            ['Serial' => 'AS4-2', 'Item' => 'ITEM-S400', 'State' => 'In stock'],
            ['Serial' => 'AS4-3', 'Item' => 'NOPE'],
        ]]])]);
        $this->connect(['syncs' => ['machines' => [
            'on' => true, 'path' => '/machines', 'list' => 'data.rows',
            'fields' => ['serial_no' => 'Serial', 'model' => 'Item', 'dealer_code' => 'Customer.No', 'status' => 'State'],
            'statuses' => [['erp' => 'In stock', 'ours' => 'inventory']],
        ]]]);

        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/erp/sync/machines')->assertAccepted();

        $one = Machine::withoutTenant()->where('serial_no', 'AS4-1')->sole();
        $this->assertSame([$this->prairie->id, 'sold'], [$one->dealer_link_id, $one->status]);
        $this->assertSame('inventory', Machine::withoutTenant()->where('serial_no', 'AS4-2')->sole()->status);
        $this->assertFalse(Machine::withoutTenant()->where('serial_no', 'AS4-3')->exists());
        $run = ErpSyncRun::withoutTenant()->sole();
        $this->assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0, 'errors' => 1], $run->counts);
        $this->assertStringContainsString('NOPE', implode(' ', $run->problems));
    }

    public function test_item_numbers_find_their_model_by_its_start_and_missing_models_can_be_added(): void
    {
        $s400b = $this->productModel($this->acme, 'S-400B');
        Tenant::run($this->acme, fn () => $s400b->update(['erp_ref' => 'ITEM-S400B']));
        Http::fake(['erp.acme.example/*' => Http::response(['Result' => [
            ['Serial' => 'B-1', 'Item' => 'ITEM-S400_2001_SS'],   // a build of S-400
            ['Serial' => 'B-2', 'Item' => 'ITEM-S400B_X'],        // the longer match wins
            ['Serial' => 'B-3', 'Item' => 'ITEM-S400BX'],         // no separator: not S-400B
            ['Serial' => 'B-4', 'Item' => 'ITEM-S400BX'],         // the model added for B-3 is reused
            ['Serial' => 'B-5', 'Item' => 'X9', 'Name' => 'S-400'], // a name we have wins over adding one
        ]])]);
        $settings = ['on' => true, 'path' => '/machines', 'list' => 'Result', 'fields' => ['serial_no' => 'Serial', 'model' => 'Item', 'model_name' => 'Name']];

        // Off (the default): try it says what's missing, and nothing is added.
        $this->connect(['syncs' => ['machines' => $settings]]);
        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/erp/preview/machines')->assertOk()
            ->assertJsonPath('rows.0.outcome', 'Would add B-1 as S-400.')
            ->assertJsonPath('rows.1.outcome', 'Would add B-2 as S-400B.')
            ->assertJsonPath('rows.2.outcome', 'Can\'t add B-3: no model has item number "ITEM-S400BX" (or the start of it).');

        // On: the missing model is added once, in its own product line, with the item number as its ERP number.
        $this->connect(['syncs' => ['machines' => $settings + ['add_models' => true]]]);
        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/erp/preview/machines')->assertOk()
            ->assertJsonPath('rows.2.outcome', 'Would add B-3, and a new model "ITEM-S400BX".');
        $this->assertFalse(ProductModel::withoutTenant()->where('name', 'ITEM-S400BX')->exists());

        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/erp/sync/machines')->assertAccepted();

        $modelOf = fn (string $serial) => Machine::withoutTenant()->where('serial_no', $serial)->sole()->product_model_id;
        $this->assertSame($this->s400->id, $modelOf('B-1'));
        $this->assertSame($s400b->id, $modelOf('B-2'));
        $this->assertSame($this->s400->id, $modelOf('B-5'));
        $added = ProductModel::withoutTenant()->where('name', 'ITEM-S400BX')->sole();
        $this->assertSame('ITEM-S400BX', $added->erp_ref);
        $this->assertSame($this->acme->id, $added->manufacturer_id);
        $this->assertSame('From your ERP', $added->productLine()->withoutGlobalScopes()->value('name'));
        $this->assertSame([$added->id, $added->id], [$modelOf('B-3'), $modelOf('B-4')]);
        $run = ErpSyncRun::withoutTenant()->sole();
        $this->assertSame(['created' => 5, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'new_models' => 1], $run->counts);
        $this->assertStringContainsString('Added 1 model', $run->message);
        $this->assertSame(1, ProductModel::withoutTenant()->where('manufacturer_id', $this->acme->id)->count() - 2);
    }

    public function test_try_it_shows_what_would_happen_and_changes_nothing(): void
    {
        Http::fake(['erp.acme.example/*' => Http::response([['Serial' => 'NEW-1', 'Item' => 'ITEM-S400'], ['Serial' => ''], ['Serial' => 'NEW-2', 'Item' => 'NOPE']])]);
        $this->connect(['syncs' => ['machines' => ['on' => false, 'path' => '/machines', 'fields' => ['serial_no' => 'Serial', 'model' => 'Item']]]]);

        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/erp/preview/machines')->assertOk()
            ->assertJsonPath('found', 3)
            ->assertJsonPath('rows.0.outcome', 'Would add NEW-1 as S-400.')
            ->assertJsonPath('rows.1.outcome', 'Has no serial number, so it can\'t be used.')
            ->assertJsonPath('rows.2.outcome', 'Can\'t add NEW-2: no model has item number "NOPE" (or the start of it).');
        $this->assertSame(0, Machine::withoutTenant()->count());
        $this->assertSame(0, ErpSyncRun::withoutTenant()->count());
        // Off: can't be run.
        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/erp/sync/machines')->assertUnprocessable();
    }

    public function test_the_schedule_fetches_only_for_pro_accounts_with_a_sync_on(): void
    {
        Http::fake(['*' => Http::response([])]);
        $this->connect(['syncs' => ['machines' => ['on' => true, 'path' => '/machines', 'fields' => ['serial_no' => 'Serial']]]]);
        $starter = $this->manufacturer('bolt', Plan::STARTER);
        Tenant::run($starter, fn () => ErpConnection::create(['base_url' => 'https://erp.bolt.example', 'syncs' => ['machines' => ['on' => true, 'path' => '/m', 'fields' => ['serial_no' => 'S']]], 'active' => true]));

        $this->artisan('erp:sync')->expectsOutput('Queued 1 ERP sync.')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame([$this->acme->id], ErpSyncRun::withoutTenant()->pluck('manufacturer_id')->all());
        $this->actingAs($this->staff($starter))->getJson('/api/m/bolt/integrations/erp')->assertForbidden();
    }

    public function test_path_to_follows_the_allowed_moves(): void
    {
        $this->assertSame(['shipped', 'delivered'], ErpSync::pathTo('received', 'delivered'));
        $this->assertSame(['cancelled'], ErpSync::pathTo('in_production', 'cancelled'));
        $this->assertNull(ErpSync::pathTo('shipped', 'in_production'));
        $this->assertNull(ErpSync::pathTo('shipped', 'cancelled'));
    }

    // ---- Helpers ----

    private function connect(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/erp', array_replace_recursive([
            'base_url' => 'https://erp.acme.example/api',
            'auth_header' => 'X-API-Key',
            'token' => 'erp-token-very-secret',
            'syncs' => [],
        ], $overrides));
    }

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
