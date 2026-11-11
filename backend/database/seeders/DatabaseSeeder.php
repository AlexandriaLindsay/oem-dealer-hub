<?php

namespace Database\Seeders;

use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\ProductLine;
use App\Models\ProductModel;
use App\Models\ProductOption;
use App\Models\User;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * Local demo data - two manufacturers and a dealer that carries both.
 * Every account's password is "dealerdock-demo". Two-factor isn't set up, so
 * the first sign-in walks through it.
 *
 * Deliberately not WithoutModelEvents: tenant-owned rows get their
 * manufacturer from the model events.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $acme = Manufacturer::create(['name' => 'Acme Scrapers', 'slug' => 'acme', 'plan' => Plan::PRO]);
        $bolt = Manufacturer::create(['name' => 'Bolt Attachments', 'slug' => 'bolt', 'plan' => Plan::STARTER]);

        $this->member('owner@acme.test', 'Avery Owner', Membership::MANUFACTURER, $acme->id, 'owner');
        $this->member('rep@acme.test', 'Riley Rep', Membership::MANUFACTURER, $acme->id, 'sales_rep');
        $this->member('owner@bolt.test', 'Blake Owner', Membership::MANUFACTURER, $bolt->id, 'owner');

        Tenant::run($acme, function () {
            foreach (['Scrapers', 'Rippers', 'Hitches'] as $name) {
                ProductLine::create(['name' => $name]);
            }
        });
        Tenant::run($bolt, fn () => ProductLine::create(['name' => 'Buckets']));

        $prairie = Dealer::create(['name' => 'Prairie Equipment', 'city' => 'Brandon', 'region' => 'MB', 'country' => 'CA']);
        $northern = Dealer::create(['name' => 'Northern Ag Supply', 'city' => 'Fargo', 'region' => 'ND', 'country' => 'US']);

        [$acmePrairie, $acmeNorthern] = Tenant::run($acme, fn () => [
            DealerLink::create(['dealer_id' => $prairie->id, 'dealer_code' => 'PRA001']),
            DealerLink::create(['dealer_id' => $northern->id, 'dealer_code' => 'NOR001']),
        ]);
        $boltPrairie = Tenant::run($bolt, fn () => DealerLink::create(['dealer_id' => $prairie->id, 'dealer_code' => 'B-1042']));

        // Carries both brands - one login, a brand switcher.
        $this->member('dealer@prairie.test', 'Pat Dealer', Membership::DEALER, $prairie->id, 'dealer_admin');

        $this->machines($acme, $bolt, $acmePrairie, $acmeNorthern, $boltPrairie);
    }

    /** Models and machines: some at each dealer, some still at the factory, and a transfer in the history. */
    private function machines(Manufacturer $acme, Manufacturer $bolt, DealerLink $acmePrairie, DealerLink $acmeNorthern, DealerLink $boltPrairie): void
    {
        $owner = User::where('email', 'owner@acme.test')->first();

        Tenant::run($acme, function () use ($owner, $acmePrairie, $acmeNorthern) {
            $lines = ProductLine::pluck('id', 'name');
            $s400 = ProductModel::create(['product_line_id' => $lines['Scrapers'], 'name' => 'S-400', 'category' => 'Pull-type scraper']);
            $s600 = ProductModel::create(['product_line_id' => $lines['Scrapers'], 'name' => 'S-600', 'category' => 'Pull-type scraper']);
            $r200 = ProductModel::create(['product_line_id' => $lines['Rippers'], 'name' => 'R-200', 'category' => 'Ripper']);
            foreach (['Hydraulic tilt', 'Laser-ready mast', 'Wide tires'] as $option) {
                ProductOption::create(['product_model_id' => $s400->id, 'name' => $option]);
            }

            $plan = [
                // serial, model, dealer, status
                ['AS4-24001', $s400, $acmePrairie, 'inventory'],
                ['AS4-24002', $s400, $acmePrairie, 'demo'],
                ['AS4-24003', $s400, $acmeNorthern, 'sold'],
                ['AS6-24001', $s600, $acmeNorthern, 'rental'],
                ['AS6-24002', $s600, null, 'inventory'],
                ['AR2-24001', $r200, $acmePrairie, 'used'],
                ['AR2-24002', $r200, null, 'inventory'],
            ];

            foreach ($plan as $i => [$serial, $model, $link, $status]) {
                $machine = Machine::add(
                    ['product_model_id' => $model->id, 'serial_no' => $serial, 'production_no' => 'PO-'.(5100 + $i), 'built_at' => now()->subWeeks(10 - $i)],
                    $link,
                    $owner,
                );
                $machine->changeStatus($status, $owner);
            }

            // Went to Northern first, then moved to Prairie.
            $moved = Machine::add(['product_model_id' => $s600->id, 'serial_no' => 'AS6-24003', 'production_no' => 'PO-5200'], $acmeNorthern, $owner);
            $moved->transferTo($acmePrairie, $owner, 'Prairie had a buyer lined up.');
        });

        Tenant::run($bolt, function () use ($boltPrairie) {
            $line = ProductLine::where('name', 'Buckets')->first();
            $model = ProductModel::create(['product_line_id' => $line->id, 'name' => 'B-72 Bucket']);
            Machine::add(['product_model_id' => $model->id, 'serial_no' => 'BB72-0001'], $boltPrairie, null);
        });
    }

    private function member(string $email, string $name, string $orgType, int $orgId, string $role): void
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'dealerdock-demo']);
        Membership::create(['user_id' => $user->id, 'org_type' => $orgType, 'org_id' => $orgId, 'role' => $role]);
    }
}
