<?php

namespace Tests\Feature;

use App\Models\DealerLink;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanAndFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_starter_stops_at_ten_dealers_and_pro_doesnt(): void
    {
        $starter = $this->manufacturer('acme', Plan::STARTER);
        $owner = $this->staff($starter);
        foreach (range(1, 10) as $i) {
            $this->dealer("Dealer {$i}", $starter);
        }

        $this->actingAs($owner)->postJson('/api/m/acme/dealers', ['name' => 'Number eleven'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $starter->update(['plan' => Plan::PRO]);
        $this->actingAs($owner)->postJson('/api/m/acme/dealers', ['name' => 'Number eleven'])->assertCreated();
        $this->assertSame(11, Tenant::run($starter, fn () => DealerLink::count()));
    }

    public function test_starter_stops_at_three_staff(): void
    {
        $starter = $this->manufacturer('acme', Plan::STARTER);
        $owner = $this->staff($starter);
        $this->staff($starter, 'admin');
        $this->staff($starter, 'sales_rep');

        $this->actingAs($owner)->postJson('/api/m/acme/staff', ['name' => 'Four', 'email' => 'four@acme.test', 'role' => 'parts'])
            ->assertUnprocessable();
    }

    public function test_a_trial_gets_pro_limits(): void
    {
        $trial = $this->manufacturer('acme', Plan::TRIAL);

        $this->assertNull(Plan::limit($trial, 'dealers'));
        $this->assertTrue(Plan::includes($trial, 'broadcasts'));
    }

    public function test_dashboard_shows_usage_against_the_plan(): void
    {
        $starter = $this->manufacturer('acme', Plan::STARTER);
        $this->dealer('One', $starter);

        $this->actingAs($this->staff($starter))->getJson('/api/m/acme/dashboard')
            ->assertOk()
            ->assertJsonPath('plan.label', 'Starter')
            ->assertJsonPath('usage.dealers.used', 1)
            ->assertJsonPath('usage.dealers.limit', 10)
            ->assertJsonPath('usage.staff.used', 1);
    }

    public function test_role_defaults_and_per_user_overrides(): void
    {
        $acme = $this->manufacturer('acme');

        // A sales rep can see dealers but not add them...
        $rep = $this->staff($acme, 'sales_rep');
        $this->actingAs($rep)->getJson('/api/m/acme/dealers')->assertOk();
        $this->actingAs($rep)->postJson('/api/m/acme/dealers', ['name' => 'X'])->assertForbidden();

        // ...unless their own override turns it on; an override can also take one away.
        $trusted = $this->staff($acme, 'sales_rep', ['manage_dealers' => true, 'dealers' => true]);
        $this->actingAs($trusted)->postJson('/api/m/acme/dealers', ['name' => 'X'])->assertCreated();

        $limited = $this->staff($acme, 'admin', ['manage_staff' => false]);
        $this->actingAs($limited)->getJson('/api/m/acme/staff')->assertForbidden();
    }

    public function test_an_override_cant_unlock_a_feature_the_plan_doesnt_have(): void
    {
        $starter = $this->manufacturer('acme', Plan::STARTER);
        $staff = $this->staff($starter, 'owner', ['broadcasts' => true]);

        $features = $this->actingAs($staff)->getJson('/api/me')->assertOk()->json('manufacturers.0.features');

        $this->assertNotContains('broadcasts', $features);
        $this->actingAs($staff)->getJson('/api/m/acme/dashboard')->assertOk();
    }
}
