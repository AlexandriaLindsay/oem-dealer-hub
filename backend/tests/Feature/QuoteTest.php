<?php

namespace Tests\Feature;

use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\PriceList;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Models\Quote;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Dealers' quotes: priced from their own list, numbered, locked once sent, readable by sales staff. */
class QuoteTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private DealerLink $link;

    private User $dealerUser;

    private ProductModel $s400;

    private ProductOption $tilt;

    private ProductOption $mast;

    private PriceList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->manufacturer('acme');
        $prairie = $this->dealer('Prairie Equipment', $this->acme);
        $this->link = $this->link($prairie, $this->acme);
        $this->dealerUser = $this->dealerUser($prairie);
        $this->s400 = $this->productModel($this->acme, 'S-400');

        [$this->tilt, $this->mast, $this->list] = Tenant::run($this->acme, function () {
            $tilt = ProductOption::create(['product_model_id' => $this->s400->id, 'name' => 'Hydraulic tilt']);
            $mast = ProductOption::create(['product_model_id' => $this->s400->id, 'name' => 'Laser-ready mast']); // left unpriced
            $list = PriceList::create(['name' => 'US dealers', 'currency' => 'USD']);
            $list->forceFill(['is_default' => true])->save();
            $list->items()->create(['item_type' => 'model', 'item_id' => $this->s400->id, 'price_cents' => 4850000]);
            $list->items()->create(['item_type' => 'option', 'item_id' => $tilt->id, 'price_cents' => 215000]);

            return [$tilt, $mast, $list];
        });
    }

    public function test_a_quote_is_priced_from_the_dealers_list_and_totalled(): void
    {
        $this->quote([
            ['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => 2],
            ['kind' => 'option', 'item_id' => $this->tilt->id, 'quantity' => 2],
            ['kind' => 'custom', 'description' => 'Freight to Brandon', 'quantity' => 1, 'unit_price' => '850'],
        ], discount: '1000')
            ->assertCreated()
            ->assertJsonPath('number', 'Q-1001')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('lines.0.description', 'S-400')
            ->assertJsonPath('lines.0.unit_price', '48500.00')
            ->assertJsonPath('lines.0.line_total', '97000.00')
            ->assertJsonPath('lines.1.description', 'S-400 - Hydraulic tilt')
            ->assertJsonPath('lines.2.list_price', null)
            ->assertJsonPath('subtotal', '102150.00') // 97,000 + 4,300 + 850
            ->assertJsonPath('discount', '1000.00')
            ->assertJsonPath('total', '101150.00')
            ->assertJsonPath('can_edit', true);
    }

    public function test_the_dealer_can_change_a_price_and_the_list_price_is_kept_alongside(): void
    {
        $this->quote([['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => 1, 'unit_price' => '46,900']])
            ->assertJsonPath('lines.0.list_price', '48500.00')
            ->assertJsonPath('lines.0.unit_price', '46900.00');
    }

    public function test_price_on_request_needs_a_typed_price(): void
    {
        $this->quote([['kind' => 'option', 'item_id' => $this->mast->id, 'quantity' => 1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.unit_price' => '"price on request" - enter the price you\'re quoting']);

        $this->quote([['kind' => 'option', 'item_id' => $this->mast->id, 'quantity' => 1, 'unit_price' => '3400']])->assertCreated();
    }

    public function test_bad_lines_and_discounts_are_refused(): void
    {
        $this->quote([['kind' => 'custom', 'quantity' => 1, 'unit_price' => '10']])->assertJsonValidationErrors('lines.0.description');
        $this->quote([['kind' => 'custom', 'description' => 'Setup', 'quantity' => 1]])->assertJsonValidationErrors('lines.0.unit_price');
        $this->quote([['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => 0]])->assertJsonValidationErrors('lines.0.quantity');
        $this->quote([['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => 1]], discount: '50000')
            ->assertJsonValidationErrors(['discount' => 'more than the quote\'s total']);
        $this->assertSame(0, Quote::withoutTenant()->count());
    }

    public function test_numbers_count_up_per_dealer(): void
    {
        $this->quote()->assertJsonPath('number', 'Q-1001');
        $this->quote()->assertJsonPath('number', 'Q-1002');

        // Another dealer starts at their own first number.
        $other = $this->dealer('Northern Ag', $this->acme);
        $this->actingAs($this->dealerUser($other))->postJson('/api/m/acme/quotes', ['customer' => ['contact_name' => 'Jo']])
            ->assertJsonPath('number', 'Q-1001');
    }

    public function test_a_sent_quote_is_locked_and_can_be_reopened_or_copied(): void
    {
        $id = $this->quote([['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => 1]])->json('id');

        $this->moveTo($id, 'sent')->assertOk()->assertJsonPath('can_edit', false);
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/quotes/{$id}", ['customer' => ['contact_name' => 'Changed']])
            ->assertUnprocessable()->assertJsonValidationErrors(['status' => 'Only a draft can be changed']);
        $this->actingAs($this->dealerUser)->deleteJson("/api/m/acme/quotes/{$id}")->assertUnprocessable();

        $this->moveTo($id, 'draft')->assertOk()->assertJsonPath('can_edit', true);
        $this->moveTo($id, 'sent');
        // Won or lost is the manufacturer's call.
        $this->moveTo($id, 'won')->assertUnprocessable()->assertJsonValidationErrors(['status' => 'marks quotes won or lost']);
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/quotes/{$id}/outcome", ['status' => 'won'])->assertForbidden();
        $this->assertSame('sent', Quote::withoutTenant()->find($id)->status);

        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/quotes/{$id}/copy")
            ->assertCreated()->assertJsonPath('number', 'Q-1002')->assertJsonPath('status', 'draft')->assertJsonPath('lines.0.description', 'S-400');
    }

    public function test_an_empty_quote_cant_be_sent(): void
    {
        $id = $this->quote()->json('id');
        $this->moveTo($id, 'sent')->assertUnprocessable()->assertJsonValidationErrors(['status' => 'at least one line']);
    }

    public function test_sent_quotes_keep_their_prices_but_drafts_and_copies_follow_the_list(): void
    {
        $sent = $this->quote([['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => 1]])->json('id');
        $this->moveTo($sent, 'sent');
        $draft = $this->quote([['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => 1]])->json('id');
        $custom = $this->quote([['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => 1, 'unit_price' => '45000']])->json('id');

        Tenant::run($this->acme, fn () => $this->list->items()->where('item_type', 'model')->update(['price_cents' => 5000000]));

        $this->actingAs($this->dealerUser)->getJson("/api/m/acme/quotes/{$sent}")->assertJsonPath('total', '48500.00');
        // A draft re-prices when it's saved again.
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/quotes/{$draft}", [
            'customer' => ['contact_name' => 'Dana'], 'lines' => [['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => 1]],
        ])->assertJsonPath('total', '50000.00');
        // A copy takes today's list price, but keeps a price the dealer chose.
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/quotes/{$sent}/copy")->assertJsonPath('total', '50000.00');
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/quotes/{$custom}/copy")->assertJsonPath('total', '45000.00');
    }

    public function test_sales_staff_read_every_dealers_sent_quotes_but_cant_change_them(): void
    {
        $id = $this->quote([['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => 1]])->json('id');
        $rep = $this->staff($this->acme, 'sales_rep');

        // A dealer's draft is theirs alone.
        $this->actingAs($rep)->getJson('/api/m/acme/quotes')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($rep)->getJson("/api/m/acme/quotes/{$id}")->assertNotFound();

        $this->moveTo($id, 'sent')->assertOk();
        $this->actingAs($rep)->getJson('/api/m/acme/quotes')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.dealer', 'Prairie Equipment');
        $this->actingAs($rep)->getJson('/api/m/acme/quotes?status=draft')->assertUnprocessable();
        $this->actingAs($rep)->getJson("/api/m/acme/quotes/{$id}")->assertOk()->assertJsonPath('can_edit', false)->assertJsonPath('can_close', true);
        $this->actingAs($rep)->postJson('/api/m/acme/quotes', ['customer' => ['contact_name' => 'X']])->assertForbidden();
        $this->actingAs($rep)->postJson("/api/m/acme/quotes/{$id}/status", ['status' => 'sent'])->assertForbidden();
        // Warranty and parts staff don't see quotes at all.
        $this->actingAs($this->staff($this->acme, 'warranty'))->getJson('/api/m/acme/quotes')->assertForbidden();
    }

    public function test_the_manufacturer_closes_a_sent_quote_and_it_leaves_the_dealers_view(): void
    {
        $id = $this->quote([['kind' => 'model', 'item_id' => $this->s400->id, 'quantity' => 1]])->json('id');
        $owner = $this->staff($this->acme, 'owner');
        $close = fn (User $who, string $status) => $this->actingAs($who)->postJson("/api/m/acme/quotes/{$id}/outcome", ['status' => $status]);

        // A draft isn't the manufacturer's to see, let alone close.
        $close($owner, 'won')->assertNotFound();

        $this->moveTo($id, 'sent');
        $close($owner, 'sent')->assertUnprocessable()->assertJsonValidationErrors('status');
        $close($this->staff($this->acme, 'warranty'), 'won')->assertForbidden();
        $close($owner, 'won')->assertOk()->assertJsonPath('status', 'won')->assertJsonPath('can_close', false);
        $this->assertNotNull(Quote::withoutTenant()->find($id)->decided_at);

        // Final: not closed twice, not reopened by the dealer.
        $close($owner, 'lost')->assertUnprocessable()->assertJsonValidationErrors(['status' => 'already marked won']);

        // Gone from the dealer's view; still in the manufacturer's.
        $this->actingAs($this->dealerUser)->getJson('/api/m/acme/quotes')->assertJsonPath('total', 0);
        $this->actingAs($this->dealerUser)->getJson("/api/m/acme/quotes/{$id}")->assertNotFound();
        $this->moveTo($id, 'draft')->assertNotFound();
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/quotes/{$id}/copy")->assertNotFound();
        $this->actingAs($owner)->getJson('/api/m/acme/quotes?status=won')->assertJsonPath('total', 1);
    }

    public function test_search_by_customer_or_number(): void
    {
        $this->quote(customer: ['company' => 'Hill Farms', 'contact_name' => 'Dana']);
        $this->quote(customer: ['contact_name' => 'Sam Ortiz']);

        $this->actingAs($this->dealerUser)->getJson('/api/m/acme/quotes?q=hill')->assertJsonPath('total', 1);
        $this->actingAs($this->dealerUser)->getJson('/api/m/acme/quotes?q=Q-1002')->assertJsonPath('data.0.customer.contact_name', 'Sam Ortiz');
    }

    public function test_no_price_list_yet_says_so(): void
    {
        Tenant::run($this->acme, fn () => PriceList::query()->delete());

        $this->quote()->assertUnprocessable()->assertJsonValidationErrors(['customer' => 'set up a price list']);
    }

    private function quote(array $lines = [], ?string $discount = null, array $customer = ['company' => 'Hill Farms', 'contact_name' => 'Dana Hill']): TestResponse
    {
        return $this->actingAs($this->dealerUser)->postJson('/api/m/acme/quotes', array_filter([
            'customer' => $customer,
            'valid_until' => now()->addDays(30)->toDateString(),
            'discount' => $discount,
            'lines' => $lines,
        ], fn ($v) => $v !== null));
    }

    private function moveTo(int $id, string $status): TestResponse
    {
        return $this->actingAs($this->dealerUser)->postJson("/api/m/acme/quotes/{$id}/status", ['status' => $status]);
    }
}
