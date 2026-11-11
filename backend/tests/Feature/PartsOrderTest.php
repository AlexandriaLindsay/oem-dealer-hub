<?php

namespace Tests\Feature;

use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\User;
use App\Notifications\PartsOrderSubmitted;
use App\Notifications\PartsOrderUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Dealers send parts orders; the parts desk works through them; everyone hears what happened. */
class PartsOrderTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private DealerLink $link;

    private User $dealerUser;

    private User $parts;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->acme = $this->manufacturer('acme');
        $prairie = $this->dealer('Prairie Equipment', $this->acme);
        $this->link = $this->link($prairie, $this->acme);
        $this->dealerUser = $this->dealerUser($prairie);
        $this->parts = $this->staff($this->acme, 'parts');
    }

    public function test_a_dealer_sends_an_order_and_the_parts_desk_hears_about_it(): void
    {
        $sales = $this->staff($this->acme, 'sales_rep');

        $this->order()
            ->assertCreated()
            ->assertJsonPath('number', '#1001')
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('po_number', 'PO-7781')
            ->assertJsonPath('lines.0.part_number', 'AS4-1102') // tidied: upper case, no spaces
            ->assertJsonPath('lines.1.quantity', 4)
            ->assertJsonPath('can_cancel', true)
            ->assertJsonPath('next', []);

        Notification::assertSentTo($this->parts, PartsOrderSubmitted::class);
        Notification::assertNotSentTo($sales, PartsOrderSubmitted::class);
        Notification::assertNotSentTo($this->dealerUser, PartsOrderSubmitted::class);
    }

    public function test_orders_need_parts_and_somewhere_to_ship(): void
    {
        $this->order(['lines' => []])->assertUnprocessable()->assertJsonValidationErrors(['lines' => 'Add at least one part']);
        $this->order(['lines' => [['part_number' => '', 'quantity' => 1]]])->assertJsonValidationErrors('lines.0.part_number');
        $this->order(['lines' => [['part_number' => 'X-1', 'quantity' => 0]]])->assertJsonValidationErrors('lines.0.quantity');
        $this->order(['ship_to' => ['name' => 'Prairie']])
            ->assertJsonValidationErrors(['ship_to.address' => 'Needed to ship it - or choose to pick it up.', 'ship_to.city']);
        // Picking it up needs no address.
        $this->order(['ship_method' => 'pickup', 'ship_to' => null])->assertCreated()->assertJsonPath('ship_to', null);
    }

    public function test_numbers_count_up_across_the_manufacturer(): void
    {
        $this->order()->assertJsonPath('number', '#1001');
        $other = $this->dealer('Northern Ag', $this->acme);
        $this->order(as: $this->dealerUser($other))->assertJsonPath('number', '#1002');
    }

    public function test_the_parts_desk_starts_ships_and_the_dealer_is_told(): void
    {
        $id = $this->order()->json('id');

        $this->actingAs($this->parts)->getJson("/api/m/acme/parts-orders/{$id}")->assertJsonPath('next', ['in_progress', 'shipped', 'cancelled']);
        $this->move($id, ['status' => 'in_progress'])->assertOk()->assertJsonPath('status', 'in_progress')->assertJsonPath('handled_by', $this->parts->name);
        $this->move($id, ['status' => 'shipped', 'carrier' => 'Purolator', 'tracking_number' => '329018827', 'parts_note' => 'Line 2 backordered, ships Friday.'])
            ->assertOk()
            ->assertJsonPath('status', 'shipped')
            ->assertJsonPath('tracking_number', '329018827')
            ->assertJsonPath('next', []);

        Notification::assertSentToTimes($this->dealerUser, PartsOrderUpdated::class, 2);
        // A shipped order is final.
        $this->move($id, ['status' => 'cancelled', 'parts_note' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['status' => 'A shipped order can\'t be marked cancelled']);
    }

    public function test_cancelling_needs_a_reason_for_the_dealer(): void
    {
        $id = $this->order()->json('id');

        $this->move($id, ['status' => 'cancelled'])->assertUnprocessable()->assertJsonValidationErrors(['parts_note' => 'why it was cancelled']);
        $this->move($id, ['status' => 'cancelled', 'parts_note' => 'Part superseded by AS4-1102B - please reorder.'])->assertOk();
        Notification::assertSentTo($this->dealerUser, PartsOrderUpdated::class);
    }

    public function test_a_dealer_can_take_an_order_back_only_until_the_parts_desk_starts(): void
    {
        $first = $this->order()->json('id');
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/parts-orders/{$first}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');

        $second = $this->order()->json('id');
        $this->move($second, ['status' => 'in_progress']);
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/parts-orders/{$second}/cancel")
            ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'already started']);
    }

    public function test_the_queue_shows_open_orders_oldest_first_and_searches_part_numbers(): void
    {
        $this->order(['po_number' => 'FIRST']);
        $shipped = $this->order(['po_number' => 'SHIPPED'])->json('id');
        $this->move($shipped, ['status' => 'shipped']);
        $this->order(['po_number' => 'THIRD', 'lines' => [['part_number' => 'R2-SHANK-9', 'quantity' => 1]]]);

        $this->actingAs($this->parts)->getJson('/api/m/acme/parts-orders?status=open')
            ->assertJsonPath('total', 2)->assertJsonPath('data.0.po_number', 'FIRST');
        $this->actingAs($this->parts)->getJson('/api/m/acme/parts-orders?q=shank')->assertJsonPath('data.0.po_number', 'THIRD');
        $this->actingAs($this->parts)->getJson('/api/m/acme/parts-orders?q=1002')->assertJsonPath('data.0.po_number', 'SHIPPED');
    }

    public function test_who_can_do_what(): void
    {
        $id = $this->order()->json('id');

        // Dealers can't work the queue; sales reps don't see parts orders; staff don't place them.
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/parts-orders/{$id}/status", ['status' => 'shipped'])->assertForbidden();
        $this->actingAs($this->staff($this->acme, 'sales_rep'))->getJson('/api/m/acme/parts-orders')->assertForbidden();
        $this->order(as: $this->parts)->assertForbidden();
        // Inside sales can look, not act.
        $inside = $this->staff($this->acme, 'inside_sales');
        $this->actingAs($inside)->getJson("/api/m/acme/parts-orders/{$id}")->assertOk()->assertJsonPath('next', []);
        $this->actingAs($inside)->postJson("/api/m/acme/parts-orders/{$id}/status", ['status' => 'in_progress'])->assertForbidden();
    }

    public function test_the_ship_to_starts_from_the_dealers_details(): void
    {
        $this->actingAs($this->dealerUser)->getJson('/api/m/acme/parts-orders/defaults')->assertOk()->assertJsonPath('ship_to.name', 'Prairie Equipment');
    }

    private function order(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->dealerUser)->postJson('/api/m/acme/parts-orders', array_replace([
            'po_number' => 'PO-7781',
            'machine_serial' => 'AS4-24001',
            'ship_method' => 'standard',
            'ship_to' => ['name' => 'Prairie Equipment', 'address' => '1200 Victoria Ave', 'city' => 'Brandon', 'region' => 'MB', 'country' => 'CA'],
            'lines' => [['part_number' => ' as4-1102 ', 'description' => 'Cutting edge', 'quantity' => 2], ['part_number' => 'HW-0450', 'quantity' => 4]],
        ], $overrides));
    }

    private function move(int $id, array $data): TestResponse
    {
        return $this->actingAs($this->parts)->postJson("/api/m/acme/parts-orders/{$id}/status", $data);
    }
}
