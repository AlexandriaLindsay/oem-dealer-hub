<?php

namespace Tests\Feature;

use App\Integrations\UrlGuard;
use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\PriceList;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** What a manufacturer's ERP sends back: order updates, machines, prices - and who's in charge of what. */
class InboundApiTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    private User $dealerUser;

    private Dealer $prairie;

    private ProductModel $s400;

    private ProductOption $tilt;

    private PriceList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['93.184.216.34']));
        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme, 'owner');
        $this->prairie = $this->dealer('Prairie Equipment', $this->acme);
        $this->dealerUser = $this->dealerUser($this->prairie);
        $this->s400 = $this->productModel($this->acme, 'S-400');

        [$this->tilt, $this->list] = Tenant::run($this->acme, function () {
            DealerLink::where('dealer_id', $this->prairie->id)->update(['dealer_code' => 'C-1042']);
            $this->s400->update(['erp_ref' => 'ITEM-S400']);
            $tilt = $this->s400->options()->create(['name' => 'Hydraulic tilt', 'erp_ref' => 'OPT-TILT']);
            $list = PriceList::create(['name' => 'US dealers', 'currency' => 'USD']);
            $list->forceFill(['is_default' => true])->save();
            $list->items()->create(['item_type' => 'model', 'item_id' => $this->s400->id, 'price_cents' => 4850000]);

            return [$tilt, $list];
        });
    }

    // ---- Sales orders ----

    public function test_the_erp_sets_its_number_and_moves_an_order(): void
    {
        Http::fake();
        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/webhooks', ['url' => 'https://erp.example.com/h', 'events' => ['sales_order.status_changed']])->assertCreated();
        $key = $this->key(['sales_orders:write']);
        $this->wonOrder();

        $this->withToken($key)->patchJson('/api/v1/sales-orders/SO-1001', ['erp_ref' => 'SO-ERP-77', 'status' => 'in_production'])
            ->assertOk()->assertJsonPath('data.erp_ref', 'SO-ERP-77')->assertJsonPath('data.status', 'in_production');

        $order = SalesOrder::withoutTenant()->first();
        $this->assertNotNull($order->started_at);
        $this->assertNull($order->handled_by);
        // Their webhook hears about it, marked as coming from the API - so the ERP can ignore its own echo.
        Http::assertSent(fn (SentRequest $r) => $r['event'] === 'sales_order.status_changed' && $r['source'] === 'api');
    }

    public function test_a_refused_move_changes_nothing(): void
    {
        $key = $this->key(['sales_orders:write']);
        $this->wonOrder();

        $this->withToken($key)->patchJson('/api/v1/sales-orders/SO-1001', ['erp_ref' => 'SO-ERP-77', 'status' => 'delivered'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'A received order can\'t be marked delivered']);
        $this->withToken($key)->patchJson('/api/v1/sales-orders/SO-1001', ['status' => 'cancelled'])
            ->assertUnprocessable()->assertJsonValidationErrors(['cancel_reason' => 'Say why']);

        $order = SalesOrder::withoutTenant()->first();
        $this->assertSame('received', $order->status);
        $this->assertNull($order->erp_ref);

        $this->withToken($key)->patchJson('/api/v1/sales-orders/SO-9999', ['status' => 'shipped'])->assertNotFound();
    }

    public function test_a_finished_order_still_takes_its_erp_number(): void
    {
        $key = $this->key(['sales_orders:write']);
        $this->wonOrder();
        $this->withToken($key)->patchJson('/api/v1/sales-orders/SO-1001', ['status' => 'cancelled', 'cancel_reason' => 'Customer withdrew'])->assertOk();

        $this->withToken($key)->patchJson('/api/v1/sales-orders/SO-1001', ['erp_ref' => 'SO-ERP-1'])->assertOk()->assertJsonPath('data.erp_ref', 'SO-ERP-1');
        $this->withToken($key)->patchJson('/api/v1/sales-orders/SO-1001', ['po_number' => 'X'])->assertUnprocessable();
    }

    public function test_when_the_erp_owns_status_only_the_api_moves_orders(): void
    {
        $key = $this->key(['sales_orders:write']);
        $id = $this->wonOrder();
        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/settings', ['erp_owns' => ['order_status' => true]])
            ->assertOk()->assertJsonPath('erp_owns.order_status', true)->assertJsonPath('erp_owns.prices', false);

        $this->actingAs($this->owner)->getJson("/api/m/acme/sales-orders/{$id}")->assertJsonPath('next', [])->assertJsonPath('status_from_erp', true);
        $this->actingAs($this->owner)->postJson("/api/m/acme/sales-orders/{$id}/status", ['status' => 'in_production'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'comes from your ERP']);
        // Details are still staff's to fill in.
        $this->actingAs($this->owner)->postJson("/api/m/acme/sales-orders/{$id}", ['po_number' => 'PO-1'])->assertOk();

        $this->withToken($key)->patchJson('/api/v1/sales-orders/SO-1001', ['status' => 'in_production'])->assertOk();

        // On Starter the switch is kept but doesn't apply - there's no API to move them.
        $this->acme->update(['plan' => Plan::STARTER]);
        $this->actingAs($this->owner)->postJson("/api/m/acme/sales-orders/{$id}/status", ['status' => 'shipped'])->assertOk();
    }

    public function test_a_read_only_key_cant_write(): void
    {
        $key = $this->key(['sales_orders:read']);
        $this->wonOrder();

        $this->withToken($key)->patchJson('/api/v1/sales-orders/SO-1001', ['status' => 'in_production'])->assertForbidden()->assertJsonPath('code', 'missing_ability');
        $this->withToken($key)->postJson('/api/v1/machines', ['machines' => [['serial_no' => 'X', 'model' => 'ITEM-S400']]])->assertForbidden();
        $this->withToken($key)->postJson('/api/v1/prices', ['price_list' => $this->list->id, 'prices' => [['item' => 'ITEM-S400', 'price' => '1']]])->assertForbidden();
        $this->withToken($key)->getJson('/api/v1/catalog')->assertForbidden();
    }

    // ---- Catalog ----

    public function test_the_catalog_lists_what_to_match(): void
    {
        $this->withToken($this->key(['catalog:read']))->getJson('/api/v1/catalog')->assertOk()
            ->assertJsonPath('dealers.0.name', 'Prairie Equipment')
            ->assertJsonPath('dealers.0.dealer_code', 'C-1042')
            ->assertJsonPath('models.0.erp_ref', 'ITEM-S400')
            ->assertJsonPath('models.0.options.0.erp_ref', 'OPT-TILT')
            ->assertJsonPath('price_lists.0.id', $this->list->id)
            ->assertJsonPath('price_lists.0.is_default', true);
    }

    // ---- Machines ----

    public function test_machines_are_added_and_updated_by_serial(): void
    {
        $key = $this->key(['machines:write']);

        $first = $this->withToken($key)->postJson('/api/v1/machines', ['machines' => [
            ['serial_no' => 'AS4-30001', 'model' => 'ITEM-S400', 'dealer_code' => 'C-1042', 'production_no' => 'WO-88'],
            ['serial_no' => 'AS4-30002', 'model_name' => 'S-400'],
        ]])->assertOk();
        $first->assertJsonPath('results.0.result', 'created')->assertJsonPath('results.1.result', 'created')->assertJsonPath('summary.created', 2);

        $shipped = Machine::withoutTenant()->where('serial_no', 'AS4-30001')->first();
        $this->assertSame(DealerLink::withoutTenant()->where('dealer_code', 'C-1042')->value('id'), $shipped->dealer_link_id);
        $this->assertNotNull($shipped->shipped_at);
        $this->assertSame('inventory', $shipped->status);
        $this->assertNull(Machine::withoutTenant()->where('serial_no', 'AS4-30002')->value('dealer_link_id'));

        // Again: a status change, a move to a dealer, one left alone, one sent back to the factory.
        $second = $this->withToken($key)->postJson('/api/v1/machines', ['machines' => [
            ['serial_no' => 'AS4-30001', 'status' => 'demo'],
            ['serial_no' => 'AS4-30002', 'dealer_code' => 'C-1042'],
            ['serial_no' => 'AS4-30002', 'dealer_code' => 'C-1042'],
        ]])->assertOk();
        $second->assertJsonPath('results.0.result', 'updated')->assertJsonPath('results.1.result', 'updated')->assertJsonPath('results.2.result', 'unchanged');
        $this->assertSame('demo', $shipped->fresh()->status);
        // Leaving dealer_code out left the dealer alone.
        $this->assertNotNull($shipped->fresh()->dealer_link_id);

        $this->withToken($key)->postJson('/api/v1/machines', ['machines' => [['serial_no' => 'AS4-30001', 'dealer_code' => null]]])->assertJsonPath('results.0.result', 'updated');
        $this->assertNull($shipped->fresh()->dealer_link_id);

        // Each change is in the machine's history, as from the ERP.
        $notes = Tenant::run($this->acme, fn () => $shipped->events()->pluck('note')->unique()->values()->all());
        $this->assertSame(['From your ERP'], $notes);
    }

    public function test_bad_machine_rows_are_reported_and_the_rest_still_go_in(): void
    {
        $other = $this->dealer('Suspended Sales', $this->acme);
        Tenant::run($this->acme, fn () => DealerLink::where('dealer_id', $other->id)->update(['dealer_code' => 'C-9', 'status' => DealerLink::SUSPENDED]));

        $res = $this->withToken($this->key(['machines:write']))->postJson('/api/v1/machines', ['machines' => [
            ['serial_no' => 'NEW-1', 'model' => 'NOPE'],
            ['serial_no' => 'NEW-2', 'model' => 'ITEM-S400', 'dealer_code' => 'C-404'],
            ['serial_no' => 'NEW-3', 'model' => 'ITEM-S400', 'dealer_code' => 'C-9'],
            ['serial_no' => 'NEW-4'],
            ['serial_no' => 'NEW-5', 'model' => 'ITEM-S400', 'status' => 'stolen'],
            ['model' => 'ITEM-S400'],
            ['serial_no' => 'GOOD-1', 'model' => 'ITEM-S400'],
        ]])->assertOk();

        $res->assertJsonPath('results.0.errors.model', 'No model has item number "NOPE". Add it under Integrations → Match your ERP\'s numbers.')
            ->assertJsonPath('results.1.errors.dealer_code', 'No active dealer has customer number "C-404". Add it under Integrations → Match your ERP\'s numbers.')
            ->assertJsonPath('results.2.result', 'error')
            ->assertJsonPath('results.3.result', 'error')
            ->assertJsonPath('results.4.result', 'error')
            ->assertJsonPath('results.5.result', 'error')
            ->assertJsonPath('results.6.result', 'created')
            ->assertJsonPath('summary.error', 6);
        $this->assertSame(['GOOD-1'], Machine::withoutTenant()->pluck('serial_no')->all());

        $this->withToken($this->key(['machines:write']))->postJson('/api/v1/machines', ['machines' => []])->assertUnprocessable();
    }

    // ---- Prices ----

    public function test_the_erp_sets_prices_by_its_item_numbers(): void
    {
        $key = $this->key(['prices:write']);

        $this->withToken($key)->postJson('/api/v1/prices', ['price_list' => $this->list->id, 'prices' => [
            ['item' => 'ITEM-S400', 'price' => '49900.00'],
            ['item' => 'OPT-TILT', 'price' => '2150'],
        ]])->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame(['model:'.$this->s400->id => 4990000, 'option:'.$this->tilt->id => 215000], Tenant::run($this->acme, fn () => $this->list->priceMap()));

        // Null takes it off the list: price on request.
        $this->withToken($key)->postJson('/api/v1/prices', ['price_list' => $this->list->id, 'prices' => [['item' => 'OPT-TILT', 'price' => null]]])->assertOk();
        $this->assertArrayNotHasKey('option:'.$this->tilt->id, Tenant::run($this->acme, fn () => $this->list->priceMap()));
    }

    public function test_one_bad_price_row_changes_nothing(): void
    {
        $key = $this->key(['prices:write']);
        Tenant::run($this->acme, fn () => $this->s400->options()->create(['name' => 'Twin', 'erp_ref' => 'ITEM-S400']));

        $this->withToken($key)->postJson('/api/v1/prices', ['price_list' => $this->list->id, 'prices' => [
            ['item' => 'OPT-TILT', 'price' => '100'],
            ['item' => 'MISSING', 'price' => '100'],
            ['item' => 'ITEM-S400', 'price' => '100'],
            ['item' => 'OPT-TILT', 'price' => 'cheap'],
        ]])->assertUnprocessable()->assertJsonValidationErrors([
            'prices.1.item' => 'No model or option has item number "MISSING"',
            'prices.2.item' => 'More than one',
            'prices.3.price' => 'isn\'t a price',
        ]);
        $this->assertSame(['model:'.$this->s400->id => 4850000], Tenant::run($this->acme, fn () => $this->list->priceMap()));

        $this->withToken($key)->postJson('/api/v1/prices', ['price_list' => 999999, 'prices' => [['item' => 'OPT-TILT', 'price' => '1']]])->assertNotFound();
    }

    public function test_when_the_erp_owns_prices_the_price_list_page_cant_change_them(): void
    {
        $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/settings', ['erp_owns' => ['prices' => true]])->assertOk();

        $this->actingAs($this->owner)->getJson("/api/m/acme/price-lists/{$this->list->id}")->assertJsonPath('prices_from_erp', true);
        $this->actingAs($this->owner)->postJson("/api/m/acme/price-lists/{$this->list->id}/prices", ['prices' => [['type' => 'model', 'id' => $this->s400->id, 'price' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['prices' => 'Prices come from your ERP']);
        $this->withToken($this->key(['prices:write']))->postJson('/api/v1/prices', ['price_list' => $this->list->id, 'prices' => [['item' => 'ITEM-S400', 'price' => '1']]])->assertOk();

        // Only owners and admins choose who's in charge.
        $this->actingAs($this->staff($this->acme, 'inside_sales'))->postJson('/api/m/acme/integrations/settings', ['erp_owns' => ['prices' => false]])->assertForbidden();
    }

    // ---- Helpers ----

    /** @param  list<string>  $abilities */
    private function key(array $abilities): string
    {
        return $this->actingAs($this->owner)->postJson('/api/m/acme/integrations/api-keys', ['name' => 'ERP', 'abilities' => $abilities])->assertCreated()->json('key');
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
