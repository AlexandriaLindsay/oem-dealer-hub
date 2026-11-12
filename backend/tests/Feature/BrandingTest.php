<?php

namespace Tests\Feature;

use App\Models\Manufacturer;
use App\Models\User;
use App\Support\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A manufacturer's own colour (Pro): set by owners and admins, seen by staff and dealers. */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme);
    }

    public function test_the_owner_sets_a_colour_and_everyone_working_with_the_brand_gets_it(): void
    {
        $this->actingAs($this->owner)->postJson('/api/m/acme/brand', ['brand_color' => '#C8102E'])->assertOk()->assertJsonPath('brand_color', '#c8102e');

        $this->actingAs($this->owner)->getJson('/api/me')->assertJsonPath('manufacturers.0.brand_color', '#c8102e');
        $dealer = $this->dealerUser($this->dealer('Prairie Equipment', $this->acme));
        $this->actingAs($dealer)->getJson('/api/me')->assertJsonPath('manufacturers.0.brand_color', '#c8102e');
    }

    public function test_clearing_it_goes_back_to_dealerdock_blue(): void
    {
        $this->actingAs($this->owner)->postJson('/api/m/acme/brand', ['brand_color' => '#c8102e'])->assertOk();
        $this->actingAs($this->owner)->postJson('/api/m/acme/brand', ['brand_color' => null])->assertOk();

        $this->assertNull($this->acme->fresh()->brand_color);
    }

    public function test_only_a_six_digit_hex_colour_is_accepted(): void
    {
        foreach (['red', '#fff', 'c8102e', '#c8102e; background:url(x)', '#gggggg'] as $bad) {
            $this->actingAs($this->owner)->postJson('/api/m/acme/brand', ['brand_color' => $bad])
                ->assertUnprocessable()->assertJsonValidationErrors(['brand_color' => 'Use a colour like']);
        }
        $this->assertNull($this->acme->fresh()->brand_color);
    }

    public function test_only_owners_and_admins_on_pro_set_it(): void
    {
        $this->actingAs($this->staff($this->acme, 'sales_rep'))->postJson('/api/m/acme/brand', ['brand_color' => '#c8102e'])->assertForbidden();
        $this->actingAs($this->staff($this->acme, 'admin'))->postJson('/api/m/acme/brand', ['brand_color' => '#c8102e'])->assertOk();

        $this->acme->update(['plan' => Plan::STARTER]);
        $this->actingAs($this->owner)->postJson('/api/m/acme/brand', ['brand_color' => '#00ff00'])->assertForbidden()->assertJsonPath('code', 'upgrade_required');
    }

    public function test_after_a_downgrade_its_kept_but_not_shown(): void
    {
        $this->actingAs($this->owner)->postJson('/api/m/acme/brand', ['brand_color' => '#c8102e'])->assertOk();

        $this->acme->update(['plan' => Plan::STARTER]);
        $this->actingAs($this->owner)->getJson('/api/me')->assertJsonPath('manufacturers.0.brand_color', null);
        $this->assertSame('#c8102e', $this->acme->fresh()->brand_color);

        $this->acme->update(['plan' => Plan::PRO]);
        $this->actingAs($this->owner)->getJson('/api/me')->assertJsonPath('manufacturers.0.brand_color', '#c8102e');
    }
}
