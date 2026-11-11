<?php

namespace Tests\Feature;

use App\Models\DealerLink;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One dealer login across every brand the dealer carries (docs/PLAN.md section 1). */
class DealerNetworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dealer_sees_every_manufacturer_it_carries(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $this->manufacturer('crane');
        $user = $this->dealerUser($this->dealer('Prairie Equipment', $acme, $bolt));

        $this->actingAs($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonCount(2, 'manufacturers')
            ->assertJsonPath('manufacturers.0.slug', 'acme')
            ->assertJsonPath('manufacturers.0.acting_as', 'dealer')
            ->assertJsonPath('manufacturers.0.dealer.name', 'Prairie Equipment')
            ->assertJsonPath('manufacturers.1.slug', 'bolt');

        $this->actingAs($user)->getJson('/api/m/acme/dashboard')->assertOk()->assertJsonPath('acting_as', 'dealer');
        $this->actingAs($user)->getJson('/api/m/crane/dashboard')->assertNotFound();
    }

    public function test_a_suspended_link_shuts_the_dealer_out_of_that_manufacturer_only(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');
        $dealer = $this->dealer('Prairie Equipment', $acme, $bolt);
        $user = $this->dealerUser($dealer);
        Tenant::run($acme, fn () => DealerLink::where('dealer_id', $dealer->id)->update(['status' => DealerLink::SUSPENDED]));

        $this->actingAs($user)->getJson('/api/m/acme/dashboard')->assertNotFound();
        $this->actingAs($user)->getJson('/api/m/bolt/dashboard')->assertOk();
    }

    public function test_dealers_cant_reach_manufacturer_only_screens(): void
    {
        $acme = $this->manufacturer('acme');
        $user = $this->dealerUser($this->dealer('Prairie Equipment', $acme));

        $this->actingAs($user)->getJson('/api/m/acme/dealers')->assertForbidden()->assertJsonPath('code', 'feature_not_allowed');
        $this->actingAs($user)->getJson('/api/m/acme/staff')->assertForbidden();
        $this->actingAs($user)->getJson('/api/m/acme/product-lines')->assertOk();
    }

    public function test_a_dealer_contact_already_on_dealerdock_isnt_invited_twice(): void
    {
        $acme = $this->manufacturer('acme');
        $bolt = $this->manufacturer('bolt');

        $this->actingAs($this->staff($acme))->postJson('/api/m/acme/dealers', [
            'name' => 'Prairie Equipment', 'contact_name' => 'Pat Lee', 'contact_email' => 'pat@prairie.test',
        ])->assertCreated();

        $this->actingAs($this->staff($bolt))->postJson('/api/m/bolt/dealers', [
            'name' => 'Prairie Equipment West', 'contact_name' => 'Pat Lee', 'contact_email' => 'pat@prairie.test',
        ])->assertCreated();

        $pat = User::where('email', 'pat@prairie.test')->sole();
        $this->assertCount(2, $pat->memberships);
    }
}
