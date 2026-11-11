<?php

namespace Tests\Feature;

use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\ProductLine;
use App\Models\ProductOption;
use App\Tenancy\MissingTenantException;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The boundary between manufacturers (docs/PLAN.md section 3). Every new
 * tenant-owned module adds its own cases here: read by list, read by id,
 * write, search, export.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_only_see_their_own_manufacturers_rows(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        Tenant::run($acme, fn () => ProductLine::create(['name' => 'Scrapers']));
        Tenant::run($bolt, fn () => ProductLine::create(['name' => 'Bolt secret line']));

        $this->actingAs($this->staff($acme))
            ->getJson('/api/m/acme/product-lines')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Scrapers');
    }

    public function test_another_manufacturers_url_is_a_404_not_a_403(): void
    {
        $acme = $this->manufacturer('acme');
        $this->manufacturer('bolt');

        $user = $this->staff($acme);
        $this->actingAs($user)->getJson('/api/m/bolt/product-lines')->assertNotFound();
        $this->actingAs($user)->getJson('/api/m/no-such-slug/product-lines')->assertNotFound();
        $this->actingAs($user)->postJson('/api/m/bolt/product-lines', ['name' => 'Injected'])->assertNotFound();

        $this->assertSame(0, ProductLine::withoutTenant()->where('name', 'Injected')->count());
    }

    public function test_new_rows_are_stamped_with_the_current_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');

        $this->actingAs($this->staff($acme))
            ->postJson('/api/m/acme/product-lines', ['name' => 'Rippers', 'manufacturer_id' => 999])
            ->assertCreated();

        $this->assertSame($acme->id, ProductLine::withoutTenant()->where('name', 'Rippers')->value('manufacturer_id'));
    }

    public function test_the_same_name_is_fine_at_two_manufacturers(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        Tenant::run($bolt, fn () => ProductLine::create(['name' => 'Scrapers']));

        $this->actingAs($this->staff($acme))
            ->postJson('/api/m/acme/product-lines', ['name' => 'Scrapers'])
            ->assertCreated();
    }

    public function test_reading_tenant_data_with_no_manufacturer_set_throws(): void
    {
        $this->expectException(MissingTenantException::class);

        ProductLine::count();
    }

    public function test_a_row_cant_be_saved_into_another_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $line = Tenant::run($bolt, fn () => ProductLine::create(['name' => 'Bolt line']));

        $this->expectException(\LogicException::class);
        Tenant::run($acme, fn () => $line->update(['name' => 'Taken over']));
    }

    public function test_tenant_run_puts_the_previous_manufacturer_back(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');

        Tenant::run($acme, function () use ($acme, $bolt) {
            Tenant::run($bolt, fn () => $this->assertSame($bolt->id, Tenant::current()->id()));
            $this->assertSame($acme->id, Tenant::current()->id());
        });

        $this->assertFalse(Tenant::current()->check());
    }

    public function test_a_manufacturer_only_sees_its_own_dealer_links(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $this->dealer('Shared Equipment', $acme, $bolt);
        $this->dealer('Bolt Only Dealer', $bolt);

        $this->actingAs($this->staff($acme))
            ->getJson('/api/m/acme/dealers')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Shared Equipment');

        $this->assertSame(3, DealerLink::withoutTenant()->count());
    }

    // ---- Products and machines ----

    public function test_product_models_and_options_stay_inside_their_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $this->productModel($acme, 'S-400');
        $boltModel = $this->productModel($bolt, 'Bolt secret model');
        $boltLine = Tenant::run($bolt, fn () => ProductLine::first());
        $user = $this->staff($acme);

        $this->actingAs($user)->getJson('/api/m/acme/product-models')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'S-400');

        // Another manufacturer's line or model id is "not found", never written to.
        $this->actingAs($user)->postJson('/api/m/acme/product-models', ['product_line_id' => $boltLine->id, 'name' => 'Sneaky'])
            ->assertUnprocessable()->assertJsonValidationErrors('product_line_id');
        $this->actingAs($user)->postJson("/api/m/acme/product-models/{$boltModel->id}/options", ['name' => 'Sneaky'])
            ->assertNotFound();

        $this->assertSame(0, ProductOption::withoutTenant()->count());
    }

    public function test_staff_cant_read_or_change_another_manufacturers_machines(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $this->machine($acme, 'ACME-1');
        $boltMachine = $this->machine($bolt, 'BOLT-SECRET-1');
        $user = $this->staff($acme);

        // List and search.
        $this->actingAs($user)->getJson('/api/m/acme/machines')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.serial_no', 'ACME-1');
        $this->actingAs($user)->getJson('/api/m/acme/machines?q=BOLT')
            ->assertOk()->assertJsonPath('total', 0);

        // By id: read, status, transfer.
        $this->actingAs($user)->getJson("/api/m/acme/machines/{$boltMachine->id}")->assertNotFound();
        $this->actingAs($user)->postJson("/api/m/acme/machines/{$boltMachine->id}/status", ['status' => 'sold'])->assertNotFound();
        $this->actingAs($user)->postJson("/api/m/acme/machines/{$boltMachine->id}/transfer", ['dealer_link_id' => null])->assertNotFound();

        $this->assertSame('inventory', Machine::withoutTenant()->find($boltMachine->id)->status);

        // Dashboard counts.
        $this->actingAs($user)->getJson('/api/m/acme/dashboard')->assertJsonPath('machines.inventory', 1);
    }

    public function test_a_machine_cant_be_added_with_another_manufacturers_model_or_dealer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $acmeModel = $this->productModel($acme);
        $boltModel = $this->productModel($bolt, 'B-72');
        $boltLink = $this->link($this->dealer('Bolt Only Dealer', $bolt), $bolt);
        $user = $this->staff($acme);

        $this->actingAs($user)->postJson('/api/m/acme/machines', ['product_model_id' => $boltModel->id, 'serial_no' => 'X1'])
            ->assertUnprocessable()->assertJsonValidationErrors('product_model_id');
        $this->actingAs($user)->postJson('/api/m/acme/machines', ['product_model_id' => $acmeModel->id, 'serial_no' => 'X2', 'dealer_link_id' => $boltLink->id])
            ->assertUnprocessable()->assertJsonValidationErrors('dealer_link_id');

        $this->assertSame(0, Machine::withoutTenant()->count());
    }

    public function test_the_same_serial_number_is_fine_at_two_manufacturers(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $this->machine($bolt, 'SN-100');
        $model = $this->productModel($acme);

        $this->actingAs($this->staff($acme))
            ->postJson('/api/m/acme/machines', ['product_model_id' => $model->id, 'serial_no' => 'SN-100'])
            ->assertCreated();
    }

    public function test_a_dealer_only_sees_their_own_machines_not_another_dealers(): void
    {
        $acme = $this->manufacturer('acme');
        $prairie = $this->dealer('Prairie Equipment', $acme);
        $rival = $this->dealer('Rival Equipment', $acme);
        $this->machine($acme, 'MINE-1', $this->link($prairie, $acme));
        $rivalMachine = $this->machine($acme, 'RIVAL-1', $this->link($rival, $acme));
        $this->machine($acme, 'FACTORY-1');
        $user = $this->dealerUser($prairie);

        $this->actingAs($user)->getJson('/api/m/acme/machines')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.serial_no', 'MINE-1');
        // Asking for the rival's stock by filter or search finds nothing.
        $this->actingAs($user)->getJson('/api/m/acme/machines?dealer='.$rivalMachine->dealer_link_id)->assertJsonPath('total', 0);
        $this->actingAs($user)->getJson('/api/m/acme/machines?q=RIVAL')->assertJsonPath('total', 0);
        $this->actingAs($user)->getJson('/api/m/acme/machines?dealer=none')->assertJsonPath('total', 0);

        $this->actingAs($user)->getJson("/api/m/acme/machines/{$rivalMachine->id}")->assertNotFound();
        $this->actingAs($user)->postJson("/api/m/acme/machines/{$rivalMachine->id}/status", ['status' => 'sold'])->assertNotFound();

        $this->actingAs($user)->getJson('/api/m/acme/dashboard')->assertJsonPath('machines.inventory', 1);
    }

    public function test_a_dealer_at_two_brands_sees_each_brands_machines_separately(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $prairie = $this->dealer('Prairie Equipment', $acme, $bolt);
        $this->machine($acme, 'ACME-1', $this->link($prairie, $acme));
        $this->machine($bolt, 'BOLT-1', $this->link($prairie, $bolt));
        $user = $this->dealerUser($prairie);

        $this->actingAs($user)->getJson('/api/m/acme/machines')->assertJsonPath('total', 1)->assertJsonPath('data.0.serial_no', 'ACME-1');
        $this->actingAs($user)->getJson('/api/m/bolt/machines')->assertJsonPath('total', 1)->assertJsonPath('data.0.serial_no', 'BOLT-1');
    }

    public function test_the_fleet_report_and_export_only_cover_their_own_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $this->machine($acme, 'ACME-1');
        $boltLink = $this->link($this->dealer('Bolt Only Dealer', $bolt), $bolt);
        $boltModel = $this->machine($bolt, 'BOLT-SECRET-1', $boltLink)->product_model_id;
        $user = $this->staff($acme);

        $this->actingAs($user)->getJson('/api/m/acme/fleet')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'by_dealer')
            ->assertJsonCount(1, 'by_model');
        // Filtering by another manufacturer's dealer or model finds nothing.
        $this->actingAs($user)->getJson("/api/m/acme/fleet?dealer={$boltLink->id}")->assertJsonPath('total', 0);
        $this->actingAs($user)->getJson("/api/m/acme/fleet?model={$boltModel}")->assertJsonPath('total', 0);
        $this->actingAs($user)->getJson('/api/m/bolt/fleet')->assertNotFound();
        $this->actingAs($user)->get('/api/m/bolt/fleet/export')->assertNotFound();

        $export = $this->actingAs($user)->get('/api/m/acme/fleet/export')->assertOk();
        $path = $export->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringContainsString('ACME-1', $sheet);
        $this->assertStringNotContainsString('BOLT', $sheet);
    }

    public function test_a_dealer_never_learns_which_dealer_had_a_machine_before(): void
    {
        $acme = $this->manufacturer('acme');
        $prairie = $this->dealer('Prairie Equipment', $acme);
        $rival = $this->dealer('Rival Equipment', $acme);
        $machine = $this->machine($acme, 'MOVED-1', $this->link($rival, $acme));
        Tenant::run($acme, fn () => $machine->transferTo($this->link($prairie, $acme), null));

        $history = $this->actingAs($this->dealerUser($prairie))
            ->getJson("/api/m/acme/machines/{$machine->id}")
            ->assertOk()
            ->json('history');

        $text = json_encode($history);
        $this->assertStringNotContainsString('Rival', $text);
        $this->assertStringContainsString('Moved from another dealer to Prairie Equipment', $text);
    }
}
