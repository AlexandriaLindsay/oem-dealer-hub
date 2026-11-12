<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\User;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "In stock near you": which dealers have which models, for the manufacturer's own website. */
class PublicStockTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme);
        $prairie = Dealer::create(['name' => 'Prairie Equipment', 'city' => 'Brandon', 'region' => 'MB', 'phone' => '204-555-0100']);
        $northern = Dealer::create(['name' => 'Northern Ag', 'city' => 'Saskatoon', 'region' => 'SK']);
        $suspended = Dealer::create(['name' => 'Gone Dealer', 'region' => 'MB']);
        $s400 = $this->productModel($this->acme, 'S-400');
        $s600 = $this->productModel($this->acme, 'S-600');

        Tenant::run($this->acme, function () use ($prairie, $northern, $suspended, $s400, $s600) {
            $p = DealerLink::create(['dealer_id' => $prairie->id, 'status' => DealerLink::ACTIVE]);
            $n = DealerLink::create(['dealer_id' => $northern->id, 'status' => DealerLink::ACTIVE]);
            $g = DealerLink::create(['dealer_id' => $suspended->id, 'status' => DealerLink::SUSPENDED]);
            $add = fn ($serial, $model, $link, $status) => Machine::add(['product_model_id' => $model->id, 'serial_no' => $serial, 'status' => $status], $link, null);
            $add('P1', $s400, $p, 'inventory');
            $add('P2', $s400, $p, 'inventory')->forceFill(['confirmed_at' => now()])->save();
            $add('P3', $s400, $p, 'demo');
            $add('P4', $s600, $p, 'sold');
            $add('P5', $s600, $p, 'rental');
            $add('N1', $s600, $n, 'used');
            $add('G1', $s400, $g, 'inventory');
            $add('F1', $s400, null, 'inventory');
        });
    }

    public function test_its_off_until_the_manufacturer_turns_it_on(): void
    {
        $this->getJson('/api/public/acme/stock')->assertNotFound();
        $this->getJson('/api/public/nobody/stock')->assertNotFound();
    }

    public function test_it_lists_counts_by_dealer_and_model_never_serials(): void
    {
        $this->turnOn();

        $res = $this->getJson('/api/public/acme/stock')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $res->assertJsonPath('manufacturer.name', 'Acme Manufacturing')
            ->assertJsonPath('regions', ['MB', 'SK'])
            ->assertJsonCount(2, 'dealers')
            ->assertJsonPath('dealers.0.name', 'Prairie Equipment')
            ->assertJsonPath('dealers.0.phone', '204-555-0100')
            ->assertJsonPath('dealers.0.models.0.name', 'S-400')
            ->assertJsonPath('dealers.0.models.0.new', 2)
            ->assertJsonPath('dealers.0.models.0.demo', 1)
            ->assertJsonPath('dealers.0.total', 3)
            ->assertJsonPath('dealers.1.name', 'Northern Ag')
            ->assertJsonPath('dealers.1.models.0.used', 1);

        // Sold, rented, factory and suspended dealers' stock never shows; nor does any serial.
        $res->assertJsonMissing(['name' => 'Gone Dealer']);
        $this->assertStringNotContainsString('P1', $res->getContent());
        $this->assertStringNotContainsString('serial', $res->getContent());
    }

    public function test_it_filters_by_model_and_region(): void
    {
        $this->turnOn();
        $s600 = $this->getJson('/api/public/acme/stock')->json('models.1.id');

        $this->getJson("/api/public/acme/stock?model={$s600}")->assertJsonCount(1, 'dealers')->assertJsonPath('dealers.0.name', 'Northern Ag');
        $this->getJson('/api/public/acme/stock?region=mb')->assertJsonCount(1, 'dealers')->assertJsonPath('dealers.0.name', 'Prairie Equipment');
    }

    public function test_confirmed_only_shows_stock_dealers_have_confirmed_lately(): void
    {
        $this->turnOn(confirmedOnly: true);

        $this->getJson('/api/public/acme/stock')->assertJsonCount(1, 'dealers')->assertJsonPath('dealers.0.total', 1);

        $this->travel(46)->days();
        $this->getJson('/api/public/acme/stock')->assertJsonCount(0, 'dealers');
    }

    public function test_it_needs_pro_and_an_owner_or_admin(): void
    {
        $this->turnOn();
        $this->actingAs($this->staff($this->acme, 'sales_rep'))->postJson('/api/m/acme/public-stock', ['enabled' => false, 'confirmed_only' => false])->assertForbidden();

        $this->acme->update(['plan' => Plan::STARTER]);
        $this->getJson('/api/public/acme/stock')->assertNotFound();
        $this->actingAs($this->owner)->getJson('/api/m/acme/public-stock')->assertForbidden();
    }

    public function test_one_manufacturers_switch_never_shows_another(): void
    {
        $this->turnOn();
        $bolt = $this->manufacturer('bolt');
        $this->productModel($bolt, 'B-72');

        $this->getJson('/api/public/bolt/stock')->assertNotFound();
        $this->getJson('/api/public/acme/stock')->assertJsonMissing(['name' => 'B-72']);
    }

    private function turnOn(bool $confirmedOnly = false): void
    {
        $this->actingAs($this->owner)->postJson('/api/m/acme/public-stock', ['enabled' => true, 'confirmed_only' => $confirmedOnly])
            ->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('page_url', rtrim(config('app.frontend_url'), '/').'/stock/acme');
    }
}
