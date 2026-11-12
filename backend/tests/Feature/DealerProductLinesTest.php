<?php

namespace Tests\Feature;

use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\PriceList;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The product lines a dealer carries: they limit the dealer's catalog and quotes, and nothing chosen means every line. */
class DealerProductLinesTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    private DealerLink $link;

    private User $dealerUser;

    private ProductModel $scraper;

    private ProductModel $ripper;

    private ProductOption $shank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme);
        $prairie = $this->dealer('Prairie Equipment', $this->acme);
        $this->link = $this->link($prairie, $this->acme);
        $this->dealerUser = $this->dealerUser($prairie);
        $this->scraper = $this->productModel($this->acme, 'S-400'); // line "Line for S-400"
        $this->ripper = $this->productModel($this->acme, 'R-200');  // line "Line for R-200"

        $this->shank = Tenant::run($this->acme, function () {
            $shank = ProductOption::create(['product_model_id' => $this->ripper->id, 'name' => 'Extra shank']);
            $list = PriceList::create(['name' => 'US dealers', 'currency' => 'USD']);
            $list->forceFill(['is_default' => true])->save();
            $list->items()->create(['item_type' => 'model', 'item_id' => $this->scraper->id, 'price_cents' => 4850000]);
            $list->items()->create(['item_type' => 'model', 'item_id' => $this->ripper->id, 'price_cents' => 1900000]);
            $list->items()->create(['item_type' => 'option', 'item_id' => $shank->id, 'price_cents' => 45000]);

            return $shank;
        });
    }

    public function test_a_dealer_with_no_lines_chosen_carries_every_line(): void
    {
        $this->actingAs($this->owner)->getJson('/api/m/acme/dealers')->assertJsonPath('0.product_line_ids', []);

        $this->actingAs($this->dealerUser)->getJson('/api/m/acme/catalog')
            ->assertJsonCount(2, 'lines');
    }

    public function test_the_dealers_catalog_shows_only_the_lines_they_carry(): void
    {
        $this->carry([$this->scraper->product_line_id])
            ->assertOk()
            ->assertJsonPath('product_line_ids', [$this->scraper->product_line_id]);

        $this->actingAs($this->dealerUser)->getJson('/api/m/acme/catalog')
            ->assertJsonCount(1, 'lines')
            ->assertJsonPath('lines.0.models.0.name', 'S-400')
            ->assertJsonMissing(['name' => 'R-200']);

        // Staff still see the whole catalog.
        $this->actingAs($this->owner)->getJson('/api/m/acme/catalog')->assertJsonCount(2, 'lines');
        // The pivot row carries the manufacturer, like every tenant-owned row.
        $this->assertSame($this->acme->id, DB::table('dealer_product_lines')->value('manufacturer_id'));
    }

    public function test_a_dealer_cant_quote_a_model_or_option_from_a_line_they_dont_carry(): void
    {
        $this->carry([$this->scraper->product_line_id]);

        $this->quote([['kind' => 'model', 'item_id' => $this->ripper->id, 'quantity' => 1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.item_id' => "R-200 is in the Line for R-200 line, which your dealership doesn't carry"]);
        $this->quote([['kind' => 'option', 'item_id' => $this->shank->id, 'quantity' => 1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.item_id');

        $this->quote([['kind' => 'model', 'item_id' => $this->scraper->id, 'quantity' => 1]])
            ->assertCreated()
            ->assertJsonPath('total', '48500.00');
    }

    public function test_clearing_the_lines_goes_back_to_every_line(): void
    {
        $this->carry([$this->scraper->product_line_id]);
        $this->carry([])->assertOk()->assertJsonPath('product_line_ids', []);

        $this->actingAs($this->dealerUser)->getJson('/api/m/acme/catalog')->assertJsonCount(2, 'lines');
        $this->quote([['kind' => 'model', 'item_id' => $this->ripper->id, 'quantity' => 1]])->assertCreated();
    }

    public function test_only_owners_and_admins_choose_a_dealers_lines(): void
    {
        $line = [$this->scraper->product_line_id];

        // The dealer can't widen (or narrow) their own lines.
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/dealers/{$this->link->id}/product-lines", ['product_line_ids' => $line])->assertForbidden();
        $this->actingAs($this->staff($this->acme, 'sales_rep'))->postJson("/api/m/acme/dealers/{$this->link->id}/product-lines", ['product_line_ids' => $line])->assertForbidden();
        $this->actingAs($this->staff($this->acme, 'admin'))->postJson("/api/m/acme/dealers/{$this->link->id}/product-lines", ['product_line_ids' => $line])->assertOk();
    }

    public function test_the_list_must_be_sent_even_when_empty(): void
    {
        $this->actingAs($this->owner)->postJson("/api/m/acme/dealers/{$this->link->id}/product-lines", [])
            ->assertJsonValidationErrors('product_line_ids');
        $this->carry([999999])->assertJsonValidationErrors(['product_line_ids.0' => "That isn't one of your product lines."]);
    }

    private function carry(array $lineIds)
    {
        return $this->actingAs($this->owner)->postJson("/api/m/acme/dealers/{$this->link->id}/product-lines", ['product_line_ids' => $lineIds]);
    }

    private function quote(array $lines)
    {
        return $this->actingAs($this->dealerUser)->postJson('/api/m/acme/quotes', [
            'customer' => ['company' => 'Hill Farms', 'contact_name' => 'Dana Hill'],
            'valid_until' => now()->addDays(30)->toDateString(),
            'lines' => $lines,
        ]);
    }
}
