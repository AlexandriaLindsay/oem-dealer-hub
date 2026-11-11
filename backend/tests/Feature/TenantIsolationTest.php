<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\DealerLink;
use App\Models\Document;
use App\Models\EndUser;
use App\Models\Machine;
use App\Models\MachineImport;
use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\PartsOrder;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductLine;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Models\Quote;
use App\Models\Registration;
use App\Models\Territory;
use App\Models\UsageDay;
use App\Tenancy\MissingTenantException;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    // ---- Registrations, documents and end users ----

    public function test_staff_cant_read_or_review_another_manufacturers_registrations(): void
    {
        Storage::fake('documents');
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $boltLink = $this->link($this->dealer('Bolt Only Dealer', $bolt), $bolt);
        [$boltRegistration, $boltDoc] = $this->registrationWithDocument($bolt, $this->machine($bolt, 'BOLT-1', $boltLink));
        $user = $this->staff($acme);

        $this->actingAs($user)->getJson('/api/m/acme/registrations')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($user)->getJson("/api/m/acme/registrations/{$boltRegistration->id}")->assertNotFound();
        $this->actingAs($user)->get("/api/m/acme/registrations/{$boltRegistration->id}/documents/{$boltDoc->id}")->assertNotFound();
        $this->actingAs($user)->postJson("/api/m/acme/registrations/{$boltRegistration->id}/approve")->assertNotFound();
        $this->actingAs($user)->postJson("/api/m/acme/registrations/{$boltRegistration->id}/reject", ['reason' => 'x'])->assertNotFound();

        $this->assertSame('pending', Registration::withoutTenant()->find($boltRegistration->id)->status);
    }

    public function test_a_dealer_only_sees_their_own_registrations_and_documents(): void
    {
        Storage::fake('documents');
        $acme = $this->manufacturer('acme');
        $prairie = $this->dealer('Prairie Equipment', $acme);
        $rival = $this->dealer('Rival Equipment', $acme);
        $this->registrationWithDocument($acme, $this->machine($acme, 'MINE-1', $this->link($prairie, $acme)));
        [$rivalRegistration, $rivalDoc] = $this->registrationWithDocument($acme, $this->machine($acme, 'RIVAL-1', $this->link($rival, $acme)));
        $user = $this->dealerUser($prairie);

        $this->actingAs($user)->getJson('/api/m/acme/registrations')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.machine.serial_no', 'MINE-1');
        $this->actingAs($user)->getJson("/api/m/acme/registrations/{$rivalRegistration->id}")->assertNotFound();
        $this->actingAs($user)->get("/api/m/acme/registrations/{$rivalRegistration->id}/documents/{$rivalDoc->id}")->assertNotFound();
        $this->actingAs($user)->getJson('/api/m/acme/dashboard')->assertJsonPath('registrations_pending', 1);
    }

    public function test_a_document_is_only_reachable_through_its_own_registration(): void
    {
        Storage::fake('documents');
        $acme = $this->manufacturer('acme');
        $link = $this->link($this->dealer('Prairie Equipment', $acme), $acme);
        [$mine] = $this->registrationWithDocument($acme, $this->machine($acme, 'SN-1', $link));
        [, $otherDoc] = $this->registrationWithDocument($acme, $this->machine($acme, 'SN-2', $link));

        // A document id paired with a different registration's id is "not found".
        $this->actingAs($this->staff($acme))->get("/api/m/acme/registrations/{$mine->id}/documents/{$otherDoc->id}")->assertNotFound();
    }

    public function test_a_dealer_cant_register_another_dealers_or_manufacturers_machine(): void
    {
        Storage::fake('documents');
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $prairie = $this->dealer('Prairie Equipment', $acme);
        $rival = $this->dealer('Rival Equipment', $acme);
        $rivalMachine = $this->machine($acme, 'RIVAL-1', $this->link($rival, $acme));
        $boltMachine = $this->machine($bolt, 'BOLT-1', $this->link($this->dealer('Bolt Dealer', $bolt), $bolt));
        $user = $this->dealerUser($prairie);

        foreach ([$rivalMachine, $boltMachine] as $machine) {
            $this->actingAs($user)->postJson('/api/m/acme/registrations', [
                'machine_id' => $machine->id, 'type' => 'rental', 'delivered_on' => now()->toDateString(),
                'customer' => ['contact_name' => 'X', 'phone' => '1'],
            ])->assertNotFound();
        }

        $this->assertSame(0, Registration::withoutTenant()->count());
    }

    public function test_end_users_with_the_same_email_stay_separate_per_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        Tenant::run($acme, fn () => EndUser::fromCustomer(['contact_name' => 'Dana', 'email' => 'dana@example.com']));
        Tenant::run($bolt, fn () => EndUser::fromCustomer(['contact_name' => 'Dana', 'email' => 'dana@example.com']));

        $this->assertSame(2, EndUser::withoutTenant()->count());
        $this->assertSame(1, Tenant::run($acme, fn () => EndUser::count()));
    }

    // ---- Price lists ----

    public function test_price_lists_stay_inside_their_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $boltModel = $this->productModel($bolt, 'B-72');
        $boltList = Tenant::run($bolt, function () use ($boltModel) {
            $list = PriceList::create(['name' => 'Bolt secret prices', 'currency' => 'USD']);
            $list->forceFill(['is_default' => true])->save();
            $list->items()->create(['item_type' => 'model', 'item_id' => $boltModel->id, 'price_cents' => 777700]);

            return $list;
        });
        $acmeLink = $this->link($this->dealer('Acme Dealer', $acme), $acme);
        $user = $this->staff($acme);

        $this->actingAs($user)->getJson('/api/m/acme/price-lists')->assertOk()->assertJsonCount(0);
        $this->actingAs($user)->getJson("/api/m/acme/price-lists/{$boltList->id}")->assertNotFound();
        $this->actingAs($user)->getJson("/api/m/acme/catalog?price_list={$boltList->id}")->assertJsonPath('price_list', null);
        $this->actingAs($user)->postJson("/api/m/acme/price-lists/{$boltList->id}/prices", ['prices' => [['type' => 'model', 'id' => $boltModel->id, 'price' => '1']]])->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/m/acme/price-lists/{$boltList->id}")->assertNotFound();
        // Can't put an Acme dealer on Bolt's list, or price Bolt's model on an Acme list.
        $this->actingAs($user)->postJson("/api/m/acme/dealers/{$acmeLink->id}/price-list", ['price_list_id' => $boltList->id])
            ->assertUnprocessable()->assertJsonValidationErrors('price_list_id');
        $acmeList = $this->actingAs($user)->postJson('/api/m/acme/price-lists', ['name' => 'Acme', 'currency' => 'USD'])->json('id');
        $this->actingAs($user)->postJson("/api/m/acme/price-lists/{$acmeList}/prices", ['prices' => [['type' => 'model', 'id' => $boltModel->id, 'price' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors('prices.0.id');

        $this->assertSame(777700, (int) PriceListItem::withoutTenant()->where('price_list_id', $boltList->id)->value('price_cents'));
        $this->assertSame(1, PriceListItem::withoutTenant()->count());
    }

    public function test_a_dealer_at_two_brands_sees_each_brands_own_prices(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $prairie = $this->dealer('Prairie Equipment', $acme, $bolt);
        foreach ([[$acme, 'S-400', 4850000], [$bolt, 'B-72', 320000]] as [$m, $model, $cents]) {
            $pm = $this->productModel($m, $model);
            Tenant::run($m, function () use ($pm, $cents) {
                $list = PriceList::create(['name' => 'Dealers', 'currency' => 'USD']);
                $list->forceFill(['is_default' => true])->save();
                $list->items()->create(['item_type' => 'model', 'item_id' => $pm->id, 'price_cents' => $cents]);
            });
        }
        $user = $this->dealerUser($prairie);

        $this->actingAs($user)->getJson('/api/m/acme/catalog')->assertJsonPath('lines.0.models.0.name', 'S-400')->assertJsonPath('lines.0.models.0.price', '48500.00');
        $this->actingAs($user)->getJson('/api/m/bolt/catalog')->assertJsonPath('lines.0.models.0.name', 'B-72')->assertJsonPath('lines.0.models.0.price', '3200.00');
    }

    // ---- Quotes ----

    public function test_a_dealer_only_sees_their_own_quotes(): void
    {
        $acme = $this->manufacturer('acme');
        $prairie = $this->dealer('Prairie Equipment', $acme);
        $rival = $this->dealer('Rival Equipment', $acme);
        Tenant::run($acme, function () {
            $list = PriceList::create(['name' => 'US', 'currency' => 'USD']);
            $list->forceFill(['is_default' => true])->save();
        });
        $rivalUser = $this->dealerUser($rival);
        $rivalQuote = $this->actingAs($rivalUser)->postJson('/api/m/acme/quotes', ['customer' => ['contact_name' => 'Rival Secret Customer']])->json('id');
        $user = $this->dealerUser($prairie);

        $this->actingAs($user)->getJson('/api/m/acme/quotes')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($user)->getJson('/api/m/acme/quotes?q=Secret')->assertJsonPath('total', 0);
        $this->actingAs($user)->getJson("/api/m/acme/quotes/{$rivalQuote}")->assertNotFound();
        $this->actingAs($user)->postJson("/api/m/acme/quotes/{$rivalQuote}", ['customer' => ['contact_name' => 'Hijacked']])->assertNotFound();
        $this->actingAs($user)->postJson("/api/m/acme/quotes/{$rivalQuote}/status", ['status' => 'sent'])->assertNotFound();
        $this->actingAs($user)->postJson("/api/m/acme/quotes/{$rivalQuote}/copy")->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/m/acme/quotes/{$rivalQuote}")->assertNotFound();

        $this->assertSame('Rival Secret Customer', Quote::withoutTenant()->find($rivalQuote)->customer['contact_name']);
    }

    public function test_quotes_stay_inside_their_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $boltModel = $this->productModel($bolt, 'B-72');
        $boltDealer = $this->dealer('Bolt Dealer', $bolt);
        $acmeDealer = $this->dealer('Acme Dealer', $acme);
        foreach ([$acme, $bolt] as $m) {
            Tenant::run($m, function () {
                $list = PriceList::create(['name' => 'US', 'currency' => 'USD']);
                $list->forceFill(['is_default' => true])->save();
            });
        }
        $boltQuote = $this->actingAs($this->dealerUser($boltDealer))->postJson('/api/m/bolt/quotes', ['customer' => ['contact_name' => 'Bolt customer']])->json('id');
        $acmeUser = $this->dealerUser($acmeDealer);

        $this->actingAs($this->staff($acme))->getJson('/api/m/acme/quotes')->assertJsonPath('total', 0);
        $this->actingAs($this->staff($acme))->getJson("/api/m/acme/quotes/{$boltQuote}")->assertNotFound();
        // An Acme quote can't use Bolt's model.
        $this->actingAs($acmeUser)->postJson('/api/m/acme/quotes', [
            'customer' => ['contact_name' => 'X'], 'lines' => [['kind' => 'model', 'item_id' => $boltModel->id, 'quantity' => 1, 'unit_price' => '1']],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.item_id');
    }

    // ---- Parts orders ----

    public function test_parts_orders_stay_with_their_dealer_and_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $prairie = $this->dealer('Prairie Equipment', $acme);
        $rival = $this->dealer('Rival Equipment', $acme);
        $boltDealer = $this->dealer('Bolt Dealer', $bolt);
        $body = ['ship_method' => 'pickup', 'po_number' => 'SECRET-PO', 'lines' => [['part_number' => 'SECRET-PART', 'quantity' => 1]]];
        $rivalOrder = $this->actingAs($this->dealerUser($rival))->postJson('/api/m/acme/parts-orders', $body)->json('id');
        $boltOrder = $this->actingAs($this->dealerUser($boltDealer))->postJson('/api/m/bolt/parts-orders', $body)->json('id');
        $user = $this->dealerUser($prairie);

        // Another dealer's order: not listed, not found by id or search, can't be cancelled.
        $this->actingAs($user)->getJson('/api/m/acme/parts-orders')->assertJsonPath('total', 0);
        $this->actingAs($user)->getJson('/api/m/acme/parts-orders?q=SECRET')->assertJsonPath('total', 0);
        $this->actingAs($user)->getJson("/api/m/acme/parts-orders/{$rivalOrder}")->assertNotFound();
        $this->actingAs($user)->postJson("/api/m/acme/parts-orders/{$rivalOrder}/cancel")->assertNotFound();
        // Another manufacturer's: Acme's parts desk can't see or move it.
        $desk = $this->staff($acme, 'parts');
        $this->actingAs($desk)->getJson('/api/m/acme/parts-orders')->assertJsonPath('total', 1);
        $this->actingAs($desk)->getJson("/api/m/acme/parts-orders/{$boltOrder}")->assertNotFound();
        $this->actingAs($desk)->postJson("/api/m/acme/parts-orders/{$boltOrder}/status", ['status' => 'shipped'])->assertNotFound();

        $this->assertSame('submitted', PartsOrder::withoutTenant()->find($rivalOrder)->status);
        $this->assertSame('submitted', PartsOrder::withoutTenant()->find($boltOrder)->status);
        // Each manufacturer numbers its own orders.
        $this->assertSame(1001, PartsOrder::withoutTenant()->find($boltOrder)->number);
    }

    // ---- Broadcasts ----

    public function test_broadcasts_stay_inside_their_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $shared = $this->dealer('Prairie Equipment', $acme, $bolt);
        $boltOnly = $this->link($this->dealer('Bolt Only Dealer', $bolt), $bolt);
        $boltBroadcast = $this->actingAs($this->staff($bolt))->postJson('/api/m/bolt/broadcasts', ['title' => 'Bolt secret', 'body' => 'x', 'audience' => 'all'])->json('id');
        $acmeOwner = $this->staff($acme);
        $dealerUser = $this->dealerUser($shared);

        // Acme can't see or withdraw Bolt's, or send to Bolt's dealer.
        $this->actingAs($acmeOwner)->getJson('/api/m/acme/broadcasts')->assertJsonCount(0);
        $this->actingAs($acmeOwner)->getJson("/api/m/acme/broadcasts/{$boltBroadcast}")->assertNotFound();
        $this->actingAs($acmeOwner)->postJson("/api/m/acme/broadcasts/{$boltBroadcast}/withdraw")->assertNotFound();
        $this->actingAs($acmeOwner)->postJson('/api/m/acme/broadcasts', ['title' => 'x', 'body' => 'y', 'audience' => 'selected', 'dealer_link_ids' => [$boltOnly->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('dealer_link_ids.0');

        // A dealer at both brands sees Bolt's announcement under Bolt only.
        $this->actingAs($dealerUser)->getJson('/api/m/bolt/announcements')->assertJsonCount(1);
        $this->actingAs($dealerUser)->getJson('/api/m/acme/announcements')->assertJsonCount(0);
        $this->actingAs($dealerUser)->getJson("/api/m/acme/announcements/{$boltBroadcast}")->assertNotFound();
        $this->assertNull(Broadcast::withoutTenant()->find($boltBroadcast)->withdrawn_at);
    }

    // ---- Territories ----

    public function test_territories_stay_inside_their_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $acmeOwner = $this->staff($acme);
        $boltOwner = $this->staff($bolt);
        $boltDealer = $this->dealer('Bolt Dealer', $bolt);
        $boltLink = $this->link($boltDealer, $bolt);
        $boltRep = $this->staff($bolt, 'sales_rep');
        $boltRepMembership = Membership::where('user_id', $boltRep->id)->value('id');
        $boltTerritory = $this->actingAs($boltOwner)->postJson('/api/m/bolt/territories', ['name' => 'Secret West'])->assertCreated()->json('id');
        $acmeTerritory = $this->actingAs($acmeOwner)->postJson('/api/m/acme/territories', ['name' => 'Canada'])->assertCreated()->json('id');

        // Bolt's territories: not listed, not renamed, deleted or staffed from Acme.
        $this->actingAs($acmeOwner)->getJson('/api/m/acme/territories')->assertJsonCount(1, 'territories')->assertJsonMissing(['name' => 'Secret West']);
        $this->actingAs($acmeOwner)->patchJson("/api/m/acme/territories/{$boltTerritory}", ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($acmeOwner)->deleteJson("/api/m/acme/territories/{$boltTerritory}")->assertNotFound();
        $this->actingAs($acmeOwner)->putJson("/api/m/acme/territories/{$boltTerritory}/reps", ['membership_ids' => []])->assertNotFound();
        // Bolt's people and dealers can't be put in Acme's territory, nor Acme's dealers in Bolt's.
        $this->actingAs($acmeOwner)->putJson("/api/m/acme/territories/{$acmeTerritory}/reps", ['membership_ids' => [$boltRepMembership]])->assertUnprocessable();
        $this->actingAs($acmeOwner)->postJson("/api/m/acme/dealers/{$boltLink->id}/territory", ['territory_id' => $acmeTerritory])->assertNotFound();
        $acmeLink = $this->link($this->dealer('Acme Dealer', $acme), $acme);
        $this->actingAs($acmeOwner)->postJson("/api/m/acme/dealers/{$acmeLink->id}/territory", ['territory_id' => $boltTerritory])->assertUnprocessable();

        $this->assertSame('Secret West', Territory::withoutTenant()->find($boltTerritory)->name);
        $this->assertNull($boltLink->fresh()->territory_id);
        $this->assertSame(0, DB::table('territory_reps')->count());
    }

    public function test_a_rep_limited_at_one_manufacturer_isnt_limited_at_another(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $this->dealer('Acme One', $acme);
        $this->dealer('Acme Two', $acme);
        $this->dealer('Bolt One', $bolt);
        $this->dealer('Bolt Two', $bolt);
        // The same person is a sales rep at both; only Acme puts them in a territory (with no dealers).
        $rep = $this->staff($acme, 'sales_rep');
        Membership::create(['user_id' => $rep->id, 'org_type' => Membership::MANUFACTURER, 'org_id' => $bolt->id, 'role' => 'sales_rep']);
        $acmeTerritory = $this->actingAs($this->staff($acme))->postJson('/api/m/acme/territories', ['name' => 'Empty'])->json('id');
        $this->putJson("/api/m/acme/territories/{$acmeTerritory}/reps", ['membership_ids' => [Membership::where('user_id', $rep->id)->where('org_id', $acme->id)->value('id')]])->assertOk();

        $this->actingAs($rep)->getJson('/api/m/acme/dealers')->assertJsonCount(0);
        $this->actingAs($rep)->getJson('/api/m/bolt/dealers')->assertJsonCount(2);
    }

    // ---- Usage analytics ----

    public function test_usage_is_counted_and_reported_per_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $shared = $this->dealer('Prairie Equipment', $acme, $bolt);
        $user = $this->dealerUser($shared);

        // The same person using both brands is counted under each separately.
        $visit = fn (string $slug) => $this->actingAs($user)->postJson("/api/m/{$slug}/usage/visits", ['area' => 'machines'])->assertNoContent();
        $visit('bolt');
        $visit('bolt');
        $visit('acme');

        $this->actingAs($this->staff($acme))->getJson('/api/m/acme/usage')->assertOk()->assertJsonPath('summary.visits', 1)
            ->assertJsonPath('dealers.0.visits', 1);
        $this->actingAs($this->staff($bolt))->getJson('/api/m/bolt/usage')->assertOk()->assertJsonPath('summary.visits', 2);
        $this->assertSame([1, 2], UsageDay::withoutTenant()->orderBy('manufacturer_id')->pluck('count')->all());
        // Each brand's row carries that brand's own dealer link.
        $this->assertSame(
            DealerLink::withoutTenant()->where('dealer_id', $shared->id)->orderBy('manufacturer_id')->pluck('id')->all(),
            UsageDay::withoutTenant()->orderBy('manufacturer_id')->pluck('dealer_link_id')->all(),
        );
    }

    // ---- Billing ----

    public function test_billing_and_dealer_status_stay_inside_their_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $boltLink = $this->link($this->dealer('Bolt Dealer', $bolt), $bolt);
        $user = $this->staff($acme);

        foreach (['get' => '/api/m/bolt/billing', 'post' => '/api/m/bolt/billing/portal'] as $method => $url) {
            $this->actingAs($user)->{$method.'Json'}($url)->assertNotFound();
        }
        $this->actingAs($user)->postJson('/api/m/bolt/billing/checkout', ['plan' => 'pro', 'interval' => 'monthly'])->assertNotFound();
        // Another manufacturer's dealer can't be suspended from here.
        $this->actingAs($user)->postJson("/api/m/acme/dealers/{$boltLink->id}/status", ['status' => 'suspended'])->assertNotFound();
        $this->assertSame(DealerLink::ACTIVE, DealerLink::withoutTenant()->find($boltLink->id)->status);
    }

    // ---- Onboarding and logo ----

    public function test_onboarding_and_the_logo_stay_inside_their_manufacturer(): void
    {
        Storage::fake('documents');
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $this->machine($bolt, 'BOLT-1', $this->link($this->dealer('Bolt Dealer', $bolt), $bolt));
        $bolt->forceFill(['logo_path' => $bolt->id.'/branding/logo.png', 'logo_mime' => 'image/png', 'logo_updated_at' => now()])->save();
        Storage::disk('documents')->put($bolt->id.'/branding/logo.png', 'bolt logo');
        $user = $this->staff($acme);

        // Bolt's machines and dealers don't tick Acme's steps.
        $steps = collect($this->actingAs($user)->getJson('/api/m/acme/onboarding')->json('steps'))->pluck('done', 'key');
        $this->assertFalse($steps['machines']);
        $this->assertFalse($steps['dealers']);

        // Acme has no logo; Bolt's isn't reachable from Acme.
        $this->actingAs($user)->get('/api/m/acme/logo')->assertNotFound();
        $this->actingAs($user)->get('/api/m/bolt/logo')->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/m/bolt/logo')->assertNotFound();
        Storage::disk('documents')->assertExists($bolt->id.'/branding/logo.png');
    }

    // ---- Machine imports ----

    public function test_an_import_only_sees_its_own_manufacturers_models_dealers_and_machines(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $this->productModel($acme, 'S-400');
        $this->productModel($bolt, 'B-72');
        $boltLink = $this->link($this->dealer('Bolt Only Dealer', $bolt), $bolt);
        Tenant::run($bolt, fn () => $boltLink->update(['dealer_code' => 'BOLT-D1']));
        $boltMachine = $this->machine($bolt, 'SHARED-1', $boltLink, 'sold');
        $user = $this->staff($acme);

        $post = fn (string $csv, string $mode = 'add_update') => $this->actingAs($user)->post('/api/m/acme/machines/import', [
            'file' => UploadedFile::fake()->createWithContent('fleet.csv', $csv), 'mode' => $mode,
        ], ['Accept' => 'application/json']);

        $rows = $post("Serial,Model,Dealer\nX-1,B-72,\nX-2,S-400,BOLT-D1\nX-3,S-400,Bolt Only Dealer\nSHARED-1,S-400,\n")
            ->assertCreated()->json('rows');

        // Bolt's model and dealer don't exist here; Bolt's serial is a new machine here.
        $this->assertSame(['error', 'error', 'error', 'create'], array_column($rows, 'action'));
        $this->assertSame('sold', Machine::withoutTenant()->find($boltMachine->id)->status);
        $this->assertSame($bolt->id, Machine::withoutTenant()->find($boltMachine->id)->manufacturer_id);
        $this->assertSame(1, Tenant::run($acme, fn () => Machine::count()));
    }

    public function test_import_history_and_reports_stay_inside_their_manufacturer(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $boltImport = Tenant::run($bolt, fn () => MachineImport::create([
            'file_name' => 'bolt-secret.csv', 'sha256' => str_repeat('0', 64), 'mode' => 'add', 'rows_total' => 1,
            'created_count' => 1, 'updated_count' => 0, 'unchanged_count' => 0, 'skipped_count' => 0, 'failed_count' => 0,
            'results' => [['line' => 2, 'serial' => 'BOLT-1', 'action' => 'create', 'messages' => [], 'changes' => []]],
        ]));
        $user = $this->staff($acme);

        $this->actingAs($user)->getJson('/api/m/acme/machine-imports')->assertOk()->assertJsonCount(0);
        $this->actingAs($user)->get("/api/m/acme/machine-imports/{$boltImport->id}/report")->assertNotFound();
        $this->actingAs($user)->get("/api/m/bolt/machine-imports/{$boltImport->id}/report")->assertNotFound();
    }

    // ---- Document library ----

    public function test_the_library_stays_inside_its_manufacturer(): void
    {
        Storage::fake('documents');
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $boltDoc = $this->libraryDocument($bolt, 'Bolt secret manual');
        $this->machine($bolt, 'BOLT-1');
        $user = $this->staff($acme);

        $this->actingAs($user)->getJson('/api/m/acme/documents')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($user)->get("/api/m/acme/documents/{$boltDoc->id}/file")->assertNotFound();
        $this->actingAs($user)->postJson("/api/m/acme/documents/{$boltDoc->id}", ['title' => 'x', 'kind' => 'manual', 'audience' => 'everyone'])->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/m/acme/documents/{$boltDoc->id}")->assertNotFound();
        // Another manufacturer's serial isn't found either.
        $this->actingAs($user)->getJson('/api/m/acme/documents/for-serial?serial=BOLT-1')->assertNotFound();
        // Nor can a document be linked to another manufacturer's model.
        $boltModel = Tenant::run($bolt, fn () => ProductModel::first());
        $this->actingAs($user)->post('/api/m/acme/documents', [
            'title' => 'x', 'kind' => 'manual', 'audience' => 'everyone',
            'file' => UploadedFile::fake()->createWithContent('m.pdf', '%PDF-1.4'),
            'models' => [['id' => $boltModel->id]],
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('models.0.id');

        $this->assertSame('Bolt secret manual', Document::withoutTenant()->find($boltDoc->id)->title);
    }

    public function test_dealers_never_see_staff_only_documents(): void
    {
        Storage::fake('documents');
        $acme = $this->manufacturer('acme');
        $prairie = $this->dealer('Prairie Equipment', $acme);
        $this->libraryDocument($acme, 'Operator manual');
        $internal = $this->libraryDocument($acme, 'Internal warranty costs', Document::STAFF);
        $machine = $this->machine($acme, 'SN-1', $this->link($prairie, $acme));
        $user = $this->dealerUser($prairie);

        $this->actingAs($user)->getJson('/api/m/acme/documents')->assertJsonPath('total', 1)->assertJsonPath('data.0.title', 'Operator manual');
        $this->actingAs($user)->getJson('/api/m/acme/documents?q=warranty')->assertJsonPath('total', 0);
        $this->actingAs($user)->get("/api/m/acme/documents/{$internal->id}/file")->assertNotFound();
        $this->actingAs($user)->getJson('/api/m/acme/documents/for-serial?serial=SN-1')->assertJsonCount(1, 'documents');
        $this->actingAs($user)->getJson("/api/m/acme/machines/{$machine->id}/documents")->assertJsonCount(1);

        // Staff see both.
        $this->actingAs($this->staff($acme))->getJson('/api/m/acme/documents')->assertJsonPath('total', 2);
    }

    public function test_serial_lookup_of_another_dealers_machine_shows_only_its_model_and_manuals(): void
    {
        Storage::fake('documents');
        $acme = $this->manufacturer('acme');
        $prairie = $this->dealer('Prairie Equipment', $acme);
        $rival = $this->dealer('Rival Equipment', $acme);
        $this->libraryDocument($acme, 'Operator manual');
        $rivalMachine = $this->machine($acme, 'RIVAL-1', $this->link($rival, $acme), 'sold');
        $user = $this->dealerUser($prairie);

        $response = $this->actingAs($user)->getJson('/api/m/acme/documents/for-serial?serial=RIVAL-1')
            ->assertOk()
            ->assertJsonPath('machine.model', 'S-400')
            ->assertJsonCount(1, 'documents');

        $this->assertSame(['serial_no', 'model', 'product_line'], array_keys($response->json('machine')));
        $this->assertStringNotContainsString('Rival', $response->getContent());
        $this->assertStringNotContainsString('sold', $response->getContent());
        // The machine itself stays out of reach.
        $this->actingAs($user)->getJson("/api/m/acme/machines/{$rivalMachine->id}/documents")->assertNotFound();
    }

    private function libraryDocument(Manufacturer $manufacturer, string $title, string $audience = Document::EVERYONE): Document
    {
        return Tenant::run($manufacturer, function () use ($title, $audience) {
            $path = Tenant::current()->id().'/library/'.md5($title).'.pdf';
            Storage::disk('documents')->put($path, '%PDF-1.4 '.$title);

            return Document::create([
                'title' => $title, 'kind' => 'manual', 'audience' => $audience, 'original_name' => 'm.pdf', 'path' => $path,
                'mime' => 'application/pdf', 'size' => 20, 'sha256' => str_repeat('0', 64), 'scan_status' => 'clean',
            ]);
        });
    }

    /** A pending registration for $machine with one stored document. */
    private function registrationWithDocument(Manufacturer $manufacturer, Machine $machine): array
    {
        return Tenant::run($manufacturer, function () use ($machine) {
            $registration = Registration::create([
                'machine_id' => $machine->id, 'dealer_link_id' => $machine->dealer_link_id, 'type' => 'sale',
                'delivered_on' => now(), 'customer' => ['contact_name' => 'Secret Customer'],
            ]);
            $path = Tenant::current()->id().'/registrations/'.$registration->id.'/doc.pdf';
            Storage::disk('documents')->put($path, '%PDF-1.4 secret');
            $doc = $registration->documents()->create([
                'kind' => 'bill_of_sale', 'original_name' => 'bill.pdf', 'path' => $path, 'mime' => 'application/pdf',
                'size' => 15, 'sha256' => str_repeat('0', 64), 'scan_status' => 'clean',
            ]);

            return [$registration, $doc];
        });
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
