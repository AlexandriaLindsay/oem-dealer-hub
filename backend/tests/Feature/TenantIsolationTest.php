<?php

namespace Tests\Feature;

use App\Models\DealerLink;
use App\Models\ProductLine;
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
}
