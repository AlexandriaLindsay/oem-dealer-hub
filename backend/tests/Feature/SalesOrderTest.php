<?php

namespace Tests\Feature;

use App\Models\Manufacturer;
use App\Models\PriceList;
use App\Models\ProductModel;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $dealerUser;

    private User $owner;

    private ProductModel $s400;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->manufacturer('acme');
        $this->dealerUser = $this->dealerUser($this->dealer('Prairie Equipment', $this->acme));
        $this->owner = $this->staff($this->acme, 'owner');
        $this->s400 = $this->productModel($this->acme, 'S-400');

        Tenant::run($this->acme, function () {
            $list = PriceList::create(['name' => 'US dealers', 'currency' => 'USD']);
            $list->forceFill(['is_default' => true])->save();
            $list->items()->create(['item_type' => 'model', 'item_id' => $this->s400->id, 'price_cents' => 4850000]);
        });
    }

    public function test_a_won_quote_becomes_a_sales_order_with_its_lines_and_totals(): void
    {
        $quote = $this->sentQuote(quantity: 2, discount: '1000', customer: ['company' => 'Hill Farms', 'contact_name' => 'Dana Hill']);

        $won = $this->actingAs($this->owner)->postJson("/api/m/acme/quotes/{$quote}/outcome", ['status' => 'won'])->assertOk();
        $won->assertJsonPath('sales_order.number', 'SO-1001')->assertJsonPath('sales_order.status', 'received');

        $order = $this->actingAs($this->owner)->getJson('/api/m/acme/sales-orders/'.$won->json('sales_order.id'))->assertOk();
        $order->assertJsonPath('status', 'received')
            ->assertJsonPath('customer.company', 'Hill Farms')
            ->assertJsonPath('dealer', 'Prairie Equipment')
            ->assertJsonPath('quote', 'Q-1001')
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('subtotal', '97000.00')
            ->assertJsonPath('discount', '1000.00')
            ->assertJsonPath('total', '96000.00')
            ->assertJsonPath('lines.0.description', 'S-400')
            ->assertJsonPath('lines.0.quantity', 2)
            ->assertJsonPath('lines.0.line_total', '97000.00')
            ->assertJsonPath('next', ['in_production', 'shipped', 'cancelled']);

        // Numbers count up across the manufacturer.
        $second = $this->sentQuote();
        $this->actingAs($this->owner)->postJson("/api/m/acme/quotes/{$second}/outcome", ['status' => 'won'])->assertJsonPath('sales_order.number', 'SO-1002');
    }

    public function test_a_lost_quote_makes_no_order(): void
    {
        $quote = $this->sentQuote();
        $this->actingAs($this->owner)->postJson("/api/m/acme/quotes/{$quote}/outcome", ['status' => 'lost'])->assertOk()->assertJsonPath('sales_order', null);

        $this->assertSame(0, SalesOrder::withoutTenant()->count());
    }

    public function test_an_order_moves_through_production_shipping_and_delivery(): void
    {
        $id = $this->wonOrder();
        $move = fn (string $to, array $extra = []) => $this->actingAs($this->owner)->postJson("/api/m/acme/sales-orders/{$id}/status", ['status' => $to] + $extra);

        $move('delivered')->assertUnprocessable()->assertJsonValidationErrors(['status' => 'A received order can\'t be marked delivered']);
        $move('in_production')->assertOk()->assertJsonPath('status', 'in_production')->assertJsonPath('handled_by', $this->owner->name);
        $move('shipped')->assertOk()->assertJsonPath('next', ['delivered']);
        // Shipped can't be cancelled - it's on the truck.
        $move('cancelled', ['cancel_reason' => 'Changed mind'])->assertUnprocessable();
        $move('delivered')->assertOk()->assertJsonPath('next', [])->assertJsonPath('can_edit', false);

        $order = SalesOrder::withoutTenant()->find($id);
        $this->assertNotNull($order->started_at);
        $this->assertNotNull($order->shipped_at);
        $this->assertNotNull($order->delivered_at);

        // A delivered order's details are final.
        $this->actingAs($this->owner)->postJson("/api/m/acme/sales-orders/{$id}", ['po_number' => 'X'])->assertUnprocessable();
    }

    public function test_cancelling_needs_a_reason(): void
    {
        $id = $this->wonOrder();

        $this->actingAs($this->owner)->postJson("/api/m/acme/sales-orders/{$id}/status", ['status' => 'cancelled'])
            ->assertUnprocessable()->assertJsonValidationErrors(['cancel_reason' => 'Say why']);
        $this->actingAs($this->owner)->postJson("/api/m/acme/sales-orders/{$id}/status", ['status' => 'cancelled', 'cancel_reason' => 'Customer financing fell through'])
            ->assertOk()->assertJsonPath('cancel_reason', 'Customer financing fell through')->assertJsonPath('next', []);
    }

    public function test_staff_fill_in_the_po_requested_date_and_notes(): void
    {
        $id = $this->wonOrder();

        $this->actingAs($this->owner)->postJson("/api/m/acme/sales-orders/{$id}", ['po_number' => 'HF-118', 'requested_date' => '2027-03-01', 'notes' => 'Deliver to the north yard.'])
            ->assertOk()->assertJsonPath('po_number', 'HF-118')->assertJsonPath('requested_date', '2027-03-01')->assertJsonPath('notes', 'Deliver to the north yard.');
        $order = SalesOrder::withoutTenant()->find($id);
        $this->assertSame('HF-118', $order->po_number);
        $this->assertSame('2027-03-01', $order->requested_date->toDateString());

        // Search by PO, number or customer.
        $this->actingAs($this->owner)->getJson('/api/m/acme/sales-orders?q=HF-118')->assertJsonPath('total', 1);
        $this->actingAs($this->owner)->getJson('/api/m/acme/sales-orders?q=SO-1001')->assertJsonPath('total', 1);
        $this->actingAs($this->owner)->getJson('/api/m/acme/sales-orders?q=nobody')->assertJsonPath('total', 0);
        $this->actingAs($this->owner)->getJson('/api/m/acme/sales-orders?status=open')->assertJsonPath('total', 1);
    }

    public function test_who_sees_and_works_sales_orders(): void
    {
        $id = $this->wonOrder();
        $finance = $this->staff($this->acme, 'finance');
        $rep = $this->staff($this->acme, 'sales_rep');

        // Finance and sales read them; only those who manage orders move them.
        $this->actingAs($finance)->getJson("/api/m/acme/sales-orders/{$id}")->assertOk()->assertJsonPath('next', [])->assertJsonPath('can_edit', false);
        $this->actingAs($finance)->postJson("/api/m/acme/sales-orders/{$id}/status", ['status' => 'in_production'])->assertForbidden();
        $this->actingAs($rep)->getJson('/api/m/acme/sales-orders')->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($rep)->postJson("/api/m/acme/sales-orders/{$id}", ['po_number' => 'X'])->assertForbidden();
        $this->actingAs($this->staff($this->acme, 'inside_sales'))->postJson("/api/m/acme/sales-orders/{$id}/status", ['status' => 'in_production'])->assertOk();

        // Dealers, warranty and parts staff don't see sales orders at all.
        $this->actingAs($this->dealerUser)->getJson('/api/m/acme/sales-orders')->assertForbidden();
        $this->actingAs($this->dealerUser)->getJson("/api/m/acme/sales-orders/{$id}")->assertForbidden();
        $this->actingAs($this->staff($this->acme, 'warranty'))->getJson('/api/m/acme/sales-orders')->assertForbidden();
        $this->actingAs($this->staff($this->acme, 'parts'))->getJson('/api/m/acme/sales-orders')->assertForbidden();
    }

    public function test_quotes_won_before_sales_orders_existed_get_one_once(): void
    {
        // Won the old way: status set, no order made.
        $old = $this->sentQuote();
        Quote::withoutTenant()->find($old)->forceFill(['status' => Quote::WON, 'decided_at' => now()->subDays(10)])->save();
        $this->wonOrder(); // and one won today, which already has its order

        $this->artisan('sales-orders:backfill')->expectsOutput('Made 1 sales order for quotes already won.')->assertSuccessful();
        $order = SalesOrder::withoutTenant()->where('quote_id', $old)->firstOrFail();
        $this->assertSame('SO-1002', $order->label());
        $this->assertSame(now()->subDays(10)->toDateString(), $order->created_at->toDateString());
        $this->assertSame(1, $order->lines()->count());

        // Running it again makes nothing new.
        $this->artisan('sales-orders:backfill')->expectsOutput('Made 0 sales orders for quotes already won.')->assertSuccessful();
        $this->assertSame(2, SalesOrder::withoutTenant()->count());
    }

    private function sentQuote(int $quantity = 1, ?string $discount = null, array $customer = ['contact_name' => 'Dana Hill']): int
    {
        $id = $this->actingAs($this->dealerUser)->postJson('/api/m/acme/quotes', array_filter([
            'customer' => $customer,
            'discount' => $discount,
            'lines' => [['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => $quantity]],
        ], fn ($v) => $v !== null))->assertCreated()->json('id');
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/quotes/{$id}/status", ['status' => 'sent'])->assertOk();

        return $id;
    }

    private function wonOrder(): int
    {
        // The quote first: making it signs in as the dealer.
        $quote = $this->sentQuote();

        return $this->actingAs($this->owner)->postJson("/api/m/acme/quotes/{$quote}/outcome", ['status' => 'won'])->assertOk()->json('sales_order.id');
    }
}
