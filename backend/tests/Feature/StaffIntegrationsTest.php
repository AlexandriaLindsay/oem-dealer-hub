<?php

namespace Tests\Feature;

use App\Integrations\UrlGuard;
use App\Models\ApiKey;
use App\Models\ErpConnection;
use App\Models\Manufacturer;
use App\Models\StaffAction;
use App\Models\User;
use App\Support\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OEM Dealer Hub staff setting up a customer's integrations (StaffWriteAccess):
 * a reason first, then changes on the Integrations page only, each one
 * logged and shown to the customer.
 */
class StaffIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $staffer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(UrlGuard::class, new UrlGuard(fn (string $host) => ['93.184.216.34']));
        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme);
        $this->staffer = $this->userWithTwoFactor();
        $this->staffer->forceFill(['is_platform_staff' => true, 'name' => 'Sam Support'])->save();
    }

    public function test_staff_give_a_reason_then_can_change_integrations(): void
    {
        $this->actingAs($this->staffer);

        // Not before saying why.
        $this->postJson('/api/m/acme/integrations/api-keys', ['name' => 'ERP', 'abilities' => ['sales_orders:read']])
            ->assertForbidden()->assertJsonPath('code', 'staff_reason_needed');
        $this->getJson('/api/m/acme/integrations')->assertOk()->assertJsonPath('staff_access_until', null);

        $this->postJson('/api/m/acme/staff-access/integrations', ['reason' => ''])->assertJsonValidationErrors('reason');
        $this->postJson('/api/m/acme/staff-access/integrations', ['reason' => 'Setup call with their IT, ticket 4411'])->assertOk()->assertJsonStructure(['until']);
        $this->getJson('/api/m/acme/integrations')->assertJsonPath('staff_access_until', fn ($v) => $v !== null);

        $this->postJson('/api/m/acme/integrations/api-keys', ['name' => 'Nightly ERP sync', 'abilities' => ['sales_orders:read']])->assertCreated();
        $this->postJson('/api/m/acme/integrations/erp', ['base_url' => 'https://erp.acme.example', 'token' => 'tok-123456789012', 'syncs' => []])->assertOk();
        // Refused changes aren't logged.
        $this->postJson('/api/m/acme/integrations/erp', ['base_url' => 'not a url'])->assertUnprocessable();

        $this->assertSame(1, ApiKey::withoutTenant()->count());
        $this->assertSame(1, ErpConnection::withoutTenant()->count());

        $log = StaffAction::orderBy('id')->get()->whereIn('action', ['integrations_access', 'changed_integrations'])->values();
        $this->assertSame(['integrations_access', 'changed_integrations', 'changed_integrations'], $log->pluck('action')->all());
        $this->assertSame('Setup call with their IT, ticket 4411', $log[0]->note);
        $this->assertSame(['Made an API key ("Nightly ERP sync")', 'Changed the ERP connection settings'], $log->slice(1)->pluck('note')->values()->all());
        $this->assertSame('Setup call with their IT, ticket 4411', $log[2]->details['reason']);
    }

    public function test_the_customer_sees_what_staff_changed_but_not_why(): void
    {
        $this->actingAs($this->staffer)->postJson('/api/m/acme/staff-access/integrations', ['reason' => 'Internal reason, not for the customer'])->assertOk();
        $this->actingAs($this->staffer)->postJson('/api/m/acme/integrations/settings', ['erp_owns' => ['prices' => true]])->assertOk();

        $this->actingAs($this->owner)->getJson('/api/m/acme/integrations')->assertOk()
            ->assertJsonPath('staff_changes.0.what', 'Changed who\'s in charge of order status and prices')
            ->assertJsonPath('staff_changes.0.by', 'Sam Support')
            ->assertJsonMissing(['reason' => 'Internal reason, not for the customer'])
            ->assertDontSee('Internal reason');
    }

    public function test_everything_else_stays_read_only_even_with_a_reason(): void
    {
        $this->actingAs($this->staffer)->postJson('/api/m/acme/staff-access/integrations', ['reason' => 'Setting up their ERP'])->assertOk();

        $this->actingAs($this->staffer)->postJson('/api/m/acme/brand', ['brand_color' => '#c8102e'])
            ->assertForbidden()->assertJsonPath('code', 'staff_read_only');
        $this->assertNull($this->acme->fresh()->brand_color);
    }

    public function test_access_is_per_manufacturer_and_runs_out(): void
    {
        $this->manufacturer('bolt');
        $this->actingAs($this->staffer)->postJson('/api/m/acme/staff-access/integrations', ['reason' => 'Setting up their ERP'])->assertOk();

        $this->actingAs($this->staffer)->postJson('/api/m/bolt/integrations/settings', ['erp_owns' => ['prices' => true]])
            ->assertForbidden()->assertJsonPath('code', 'staff_reason_needed');

        $this->travel(2)->hours();
        $this->travel(1)->minutes();
        $this->actingAs($this->staffer)->postJson('/api/m/acme/integrations/settings', ['erp_owns' => ['prices' => true]])
            ->assertForbidden()->assertJsonPath('code', 'staff_reason_needed');
    }

    public function test_only_staff_looking_in_can_open_it(): void
    {
        // The customer's own owner already can change things; this isn't for them.
        $this->actingAs($this->owner)->postJson('/api/m/acme/staff-access/integrations', ['reason' => 'Let me in'])->assertNotFound();
        $this->assertSame(0, StaffAction::count());

        // Still only on a plan with integrations.
        $this->manufacturer('tiny', Plan::STARTER);
        $this->actingAs($this->staffer)->postJson('/api/m/tiny/staff-access/integrations', ['reason' => 'Setting up their ERP'])->assertForbidden();
    }
}
