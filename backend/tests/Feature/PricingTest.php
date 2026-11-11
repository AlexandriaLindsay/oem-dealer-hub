<?php

namespace Tests\Feature;

use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\PriceList;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Models\User;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Price lists, prices, assigning dealers, the catalog dealers see, and the plan's limit. */
class PricingTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    private ProductModel $s400;

    private ProductOption $tilt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme);
        $this->s400 = $this->productModel($this->acme, 'S-400');
        $this->tilt = Tenant::run($this->acme, fn () => ProductOption::create(['product_model_id' => $this->s400->id, 'name' => 'Hydraulic tilt']));
    }

    public function test_the_first_list_is_the_default_and_prices_are_set_in_cents(): void
    {
        $id = $this->newList('US dealers')->assertCreated()->assertJsonPath('is_default', true)->json('id');

        $this->actingAs($this->owner)->postJson("/api/m/acme/price-lists/{$id}/prices", ['prices' => [
            ['type' => 'model', 'id' => $this->s400->id, 'price' => '48,500'],
            ['type' => 'option', 'id' => $this->tilt->id, 'price' => '2150.5'],
        ]])
            ->assertOk()
            ->assertJsonPath('items_count', 2)
            ->assertJsonPath('lines.0.models.0.price', '48500.00')
            ->assertJsonPath('lines.0.models.0.options.0.price', '2150.50');

        $this->assertSame(4850000, (int) Tenant::run($this->acme, fn () => PriceList::find($id)->items()->where('item_type', 'model')->value('price_cents')));
    }

    public function test_clearing_a_price_means_price_on_request_and_bad_prices_are_refused(): void
    {
        $id = $this->newList('US dealers')->json('id');
        $this->setPrice($id, '48500');

        $this->actingAs($this->owner)->postJson("/api/m/acme/price-lists/{$id}/prices", ['prices' => [['type' => 'model', 'id' => $this->s400->id, 'price' => 'lots']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['prices.0.price' => '"lots" isn\'t a price']);
        $this->actingAs($this->owner)->postJson("/api/m/acme/price-lists/{$id}/prices", ['prices' => [['type' => 'model', 'id' => $this->s400->id, 'price' => '-5']]])
            ->assertUnprocessable();

        $this->actingAs($this->owner)->postJson("/api/m/acme/price-lists/{$id}/prices", ['prices' => [['type' => 'model', 'id' => $this->s400->id, 'price' => '']]])
            ->assertOk()->assertJsonPath('lines.0.models.0.price', null);
    }

    public function test_a_dealer_sees_their_own_list_or_the_default(): void
    {
        $usd = $this->newList('US dealers', 'USD')->json('id');
        $cad = $this->newList('Canadian dealers', 'CAD')->json('id');
        $this->setPrice($usd, '48500');
        $this->setPrice($cad, '66000');
        $prairie = $this->dealer('Prairie Equipment', $this->acme);
        $northern = $this->dealer('Northern Ag', $this->acme);
        $prairieLink = $this->link($prairie, $this->acme);

        $this->actingAs($this->owner)->postJson("/api/m/acme/dealers/{$prairieLink->id}/price-list", ['price_list_id' => $cad])
            ->assertOk()->assertJsonPath('price_list_id', $cad);
        $this->assertSame($cad, Tenant::run($this->acme, fn () => DealerLink::find($prairieLink->id)->price_list_id));

        // Prairie: their own (CAD) list. And only the currency - not the list's name.
        $this->actingAs($this->dealerUser($prairie))->getJson('/api/m/acme/catalog')
            ->assertOk()
            ->assertJsonPath('price_list', ['currency' => 'CAD'])
            ->assertJsonPath('lines.0.models.0.price', '66000.00');
        // Northern has no list of its own: the default.
        $this->actingAs($this->dealerUser($northern))->getJson('/api/m/acme/catalog')
            ->assertJsonPath('price_list.currency', 'USD')
            ->assertJsonPath('lines.0.models.0.price', '48500.00');

        // Back to the default.
        $this->actingAs($this->owner)->postJson("/api/m/acme/dealers/{$prairieLink->id}/price-list", ['price_list_id' => null])->assertOk();
        $this->assertNull(Tenant::run($this->acme, fn () => DealerLink::find($prairieLink->id)->price_list_id));
    }

    public function test_a_dealer_cant_ask_for_another_list(): void
    {
        $usd = $this->newList('US dealers')->json('id');
        $secret = $this->newList('Key accounts')->json('id');
        $this->setPrice($usd, '48500');
        $this->setPrice($secret, '39000');
        $prairie = $this->dealer('Prairie Equipment', $this->acme);
        $dealer = $this->dealerUser($prairie);

        $this->actingAs($dealer)->getJson("/api/m/acme/catalog?price_list={$secret}")->assertJsonPath('lines.0.models.0.price', '48500.00');
        $this->actingAs($dealer)->getJson('/api/m/acme/price-lists')->assertForbidden();
        $this->actingAs($dealer)->getJson("/api/m/acme/price-lists/{$secret}")->assertForbidden();
        $this->assertStringNotContainsString('39000', $this->actingAs($dealer)->getJson('/api/m/acme/catalog')->getContent());
    }

    public function test_staff_who_manage_pricing_can_look_at_any_list(): void
    {
        $this->newList('US dealers');
        $other = $this->newList('Key accounts')->json('id');
        $this->setPrice($other, '39000');

        $this->actingAs($this->owner)->getJson("/api/m/acme/catalog?price_list={$other}")
            ->assertJsonPath('price_list.name', 'Key accounts')->assertJsonPath('lines.0.models.0.price', '39000.00');
        // A sales rep sees prices, but only the default list.
        $this->actingAs($this->staff($this->acme, 'sales_rep'))->getJson("/api/m/acme/catalog?price_list={$other}")
            ->assertJsonPath('price_list.name', 'US dealers');
    }

    public function test_copying_a_list_and_moving_the_default(): void
    {
        $usd = $this->newList('US dealers')->json('id');
        $this->setPrice($usd, '48500');
        $copy = $this->newList('US - 2027', 'USD', $usd)->assertJsonPath('items_count', 1)->assertJsonPath('is_default', false)->json('id');

        // The default can't be deleted while others exist; move it first.
        $this->actingAs($this->owner)->deleteJson("/api/m/acme/price-lists/{$usd}")
            ->assertUnprocessable()->assertJsonValidationErrors(['price_list' => 'default list']);
        $this->actingAs($this->owner)->postJson("/api/m/acme/price-lists/{$copy}/default")->assertOk();
        $this->actingAs($this->owner)->deleteJson("/api/m/acme/price-lists/{$usd}")->assertNoContent();

        $this->assertTrue(Tenant::run($this->acme, fn () => PriceList::find($copy)->is_default));
        $this->assertSame(1, Tenant::run($this->acme, fn () => PriceList::where('is_default', true)->count()));
    }

    public function test_deleting_a_list_puts_its_dealers_back_on_the_default(): void
    {
        $this->newList('US dealers');
        $special = $this->newList('Special')->json('id');
        $link = $this->link($this->dealer('Prairie Equipment', $this->acme), $this->acme);
        $this->actingAs($this->owner)->postJson("/api/m/acme/dealers/{$link->id}/price-list", ['price_list_id' => $special]);

        $this->actingAs($this->owner)->deleteJson("/api/m/acme/price-lists/{$special}")->assertNoContent();

        $this->assertNull(Tenant::run($this->acme, fn () => DealerLink::find($link->id)->price_list_id));
    }

    public function test_starter_includes_one_price_list_and_downgrading_checks_it(): void
    {
        $this->newList('US dealers');
        $this->newList('Canadian dealers', 'CAD');
        $this->actingAs($this->owner)->getJson('/api/m/acme/billing')
            ->assertJsonPath('plans.0.blockers.0', 'You have 2 price lists; Starter includes 1. Delete the ones you don\'t need first - their dealers go back to the default list.');

        $this->acme->update(['plan' => Plan::STARTER]);
        $this->newList('Third')->assertUnprocessable()->assertJsonValidationErrors(['name' => 'Upgrade to Pro']);
    }

    public function test_only_pricing_staff_manage_lists(): void
    {
        $finance = $this->staff($this->acme, 'finance');
        $this->actingAs($finance)->postJson('/api/m/acme/price-lists', ['name' => 'By finance', 'currency' => 'USD'])->assertCreated();
        $this->actingAs($this->staff($this->acme, 'sales_rep'))->postJson('/api/m/acme/price-lists', ['name' => 'By rep', 'currency' => 'USD'])->assertForbidden();
        $this->actingAs($this->owner)->postJson('/api/m/acme/price-lists', ['name' => 'Euro', 'currency' => 'EUR'])->assertUnprocessable();
    }

    public function test_no_list_yet_is_an_empty_catalog_not_an_error(): void
    {
        $this->actingAs($this->dealerUser($this->dealer('Prairie Equipment', $this->acme)))->getJson('/api/m/acme/catalog')
            ->assertOk()->assertJsonPath('price_list', null)->assertJsonPath('lines', []);
    }

    private function newList(string $name, string $currency = 'USD', ?int $copyFrom = null)
    {
        return $this->actingAs($this->owner)->postJson('/api/m/acme/price-lists', array_filter(['name' => $name, 'currency' => $currency, 'copy_from' => $copyFrom]));
    }

    private function setPrice(int $list, string $price): void
    {
        $this->actingAs($this->owner)->postJson("/api/m/acme/price-lists/{$list}/prices", ['prices' => [['type' => 'model', 'id' => $this->s400->id, 'price' => $price]]])->assertOk();
    }
}
