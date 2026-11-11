<?php

namespace Tests;

use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\Membership;
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

    protected function userWithTwoFactor(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        return $user;
    }
}
