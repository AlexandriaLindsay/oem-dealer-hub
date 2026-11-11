<?php

namespace Database\Seeders;

use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\ProductLine;
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

        Tenant::run($acme, function () use ($prairie, $northern) {
            DealerLink::create(['dealer_id' => $prairie->id, 'dealer_code' => 'PRA001']);
            DealerLink::create(['dealer_id' => $northern->id, 'dealer_code' => 'NOR001']);
        });
        Tenant::run($bolt, fn () => DealerLink::create(['dealer_id' => $prairie->id, 'dealer_code' => 'B-1042']));

        // Carries both brands - one login, a brand switcher.
        $this->member('dealer@prairie.test', 'Pat Dealer', Membership::DEALER, $prairie->id, 'dealer_admin');
    }

    private function member(string $email, string $name, string $orgType, int $orgId, string $role): void
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'dealerdock-demo']);
        Membership::create(['user_id' => $user->id, 'org_type' => $orgType, 'org_id' => $orgId, 'role' => $role]);
    }
}
