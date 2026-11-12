<?php

namespace Tests\Feature;

use App\Models\Manufacturer;
use App\Models\StaffAction;
use App\Models\User;
use App\Support\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** OEM Dealer Hub staff opening any manufacturer in the app: everything visible, nothing changeable, every look logged. */
class StaffViewTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private Manufacturer $bolt;

    private User $staffer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->manufacturer('acme');
        $this->bolt = $this->manufacturer('bolt', Plan::STARTER);
        $this->staffer = $this->userWithTwoFactor();
        $this->staffer->forceFill(['is_platform_staff' => true])->save();
    }

    public function test_staff_get_every_manufacturer_in_the_switcher_marked_as_a_staff_view(): void
    {
        $me = $this->actingAs($this->staffer)->getJson('/api/me')->assertOk();

        $this->assertSame(['acme', 'bolt'], collect($me->json('manufacturers'))->pluck('slug')->all());
        $me->assertJsonPath('manufacturers.0.staff_view', true)
            ->assertJsonPath('manufacturers.0.role', 'owner')
            ->assertJsonPath('manufacturers.0.acting_as', 'manufacturer');
    }

    public function test_customers_dont_get_a_staff_view(): void
    {
        $owner = $this->staff($this->acme);

        $this->actingAs($owner)->getJson('/api/me')
            ->assertJsonCount(1, 'manufacturers')
            ->assertJsonPath('manufacturers.0.staff_view', false);
        $this->actingAs($owner)->getJson('/api/m/bolt/dashboard')->assertNotFound();
    }

    public function test_staff_can_read_but_not_change_anything(): void
    {
        $this->dealer('Prairie Equipment', $this->acme);
        $this->actingAs($this->staffer);

        $this->getJson('/api/m/acme/dashboard')->assertOk();
        $this->getJson('/api/m/acme/dealers')->assertOk()->assertSee('Prairie Equipment');

        $this->postJson('/api/m/acme/brand', ['brand_color' => '#c8102e'])
            ->assertForbidden()->assertJsonPath('code', 'staff_read_only');
        $this->assertNull($this->acme->fresh()->brand_color);
    }

    public function test_each_manufacturer_opened_is_logged_once_a_working_day(): void
    {
        $this->actingAs($this->staffer);
        $this->getJson('/api/m/acme/dashboard')->assertOk();
        $this->getJson('/api/m/acme/dealers')->assertOk();
        $this->getJson('/api/m/bolt/dashboard')->assertOk();

        $this->assertSame(['Acme Manufacturing', 'Bolt Manufacturing'], StaffAction::where('action', 'opened_in_app')->orderBy('id')->pluck('subject_label')->all());
        $this->assertSame($this->staffer->id, StaffAction::first()->user_id);

        $this->travel(9)->hours();
        $this->getJson('/api/m/acme/dashboard')->assertOk();
        $this->assertSame(3, StaffAction::where('action', 'opened_in_app')->count());
    }

    public function test_where_staff_also_work_for_a_manufacturer_they_work_normally(): void
    {
        $user = $this->staff($this->acme);
        $user->forceFill(['is_platform_staff' => true])->save();

        $this->actingAs($user)->getJson('/api/me')
            ->assertJsonPath('manufacturers.0.slug', 'acme')->assertJsonPath('manufacturers.0.staff_view', false)
            ->assertJsonPath('manufacturers.1.slug', 'bolt')->assertJsonPath('manufacturers.1.staff_view', true);
        $this->actingAs($user)->postJson('/api/m/acme/brand', ['brand_color' => '#c8102e'])->assertOk();
    }

    public function test_staff_without_an_authenticator_get_no_staff_view(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_staff' => true, 'terms_version' => config('dealerdock.legal.version')])->save();
        config(['dealerdock.require_two_factor' => false]);

        $this->actingAs($user)->getJson('/api/me')->assertJsonCount(0, 'manufacturers');
        $this->actingAs($user)->getJson('/api/m/acme/dashboard')->assertNotFound();
    }
}
