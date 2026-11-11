<?php

namespace Tests;

use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\ProductLine;
use App\Models\ProductModel;
use App\Models\User;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function manufacturer(string $slug, string $plan = Plan::PRO): Manufacturer
    {
        return Manufacturer::create(['name' => ucfirst($slug).' Manufacturing', 'slug' => $slug, 'plan' => $plan]);
    }

    /** A staff member of $manufacturer, with two-factor already set up. */
    protected function staff(Manufacturer $manufacturer, string $role = 'owner', ?array $overrides = null): User
    {
        $user = $this->userWithTwoFactor();
        Membership::create([
            'user_id' => $user->id,
            'org_type' => Membership::MANUFACTURER,
            'org_id' => $manufacturer->id,
            'role' => $role,
            'feature_overrides' => $overrides,
        ]);

        return $user;
    }

    /** A dealer linked to each of $manufacturers. */
    protected function dealer(string $name, Manufacturer ...$manufacturers): Dealer
    {
        $dealer = Dealer::create(['name' => $name]);
        foreach ($manufacturers as $m) {
            Tenant::run($m, fn () => DealerLink::create(['dealer_id' => $dealer->id, 'status' => DealerLink::ACTIVE]));
        }

        return $dealer;
    }

    /** One of $dealer's people, with two-factor already set up. */
    protected function dealerUser(Dealer $dealer, string $role = 'dealer_admin'): User
    {
        $user = $this->userWithTwoFactor();
        Membership::create(['user_id' => $user->id, 'org_type' => Membership::DEALER, 'org_id' => $dealer->id, 'role' => $role]);

        return $user;
    }

    /** $dealer's link with $manufacturer. */
    protected function link(Dealer $dealer, Manufacturer $manufacturer): DealerLink
    {
        return Tenant::run($manufacturer, fn () => DealerLink::where('dealer_id', $dealer->id)->firstOrFail());
    }

    /** A model at $manufacturer, in a product line of the same name (made if needed). */
    protected function productModel(Manufacturer $manufacturer, string $name = 'S-400'): ProductModel
    {
        return Tenant::run($manufacturer, function () use ($name) {
            $line = ProductLine::firstOrCreate(['name' => 'Line for '.$name]);

            return ProductModel::create(['product_line_id' => $line->id, 'name' => $name]);
        });
    }

    /** A machine at $manufacturer, with $link's dealer (or still at the manufacturer). */
    protected function machine(Manufacturer $manufacturer, string $serial, ?DealerLink $link = null, string $status = 'inventory', ?ProductModel $model = null): Machine
    {
        // The manufacturer's first model by id (not "whichever the database returns first").
        $model ??= Tenant::run($manufacturer, fn () => ProductModel::orderBy('id')->first()) ?? $this->productModel($manufacturer);

        return Tenant::run($manufacturer, function () use ($model, $serial, $link, $status) {
            $machine = Machine::add(['product_model_id' => $model->id, 'serial_no' => $serial], $link, null);
            $machine->changeStatus($status, null);

            return $machine;
        });
    }

    protected function userWithTwoFactor(): User
    {
        $user = User::factory()->create();
        // Two-factor set up, and the current terms agreed to - a user who's past every first-time step.
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
            'terms_version' => config('dealerdock.legal.version'), 'terms_accepted_at' => now(),
        ])->save();

        return $user;
    }
}
