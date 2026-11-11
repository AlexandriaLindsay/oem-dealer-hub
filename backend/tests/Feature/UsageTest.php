<?php

namespace Tests\Feature;

use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\UsageDay;
use App\Models\User;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** What's counted for the usage report, what isn't, the report itself, and the 13-month limit. */
class UsageTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    private DealerLink $prairie;

    private User $prairieUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->manufacturer('acme'); // Pro
        $this->owner = $this->staff($this->acme);
        $p = $this->dealer('Prairie Equipment', $this->acme);
        $this->prairie = $this->link($p, $this->acme);
        $this->prairieUser = $this->dealerUser($p);
        $this->dealer('Northern Ag', $this->acme); // never uses it
    }

    public function test_each_page_visit_counts_once_per_person_per_day(): void
    {
        $this->visit($this->prairieUser, 'machines');
        $this->visit($this->prairieUser, 'machines');
        $this->visit($this->prairieUser, 'prices');

        $rows = UsageDay::withoutTenant()->orderBy('area')->get();
        $this->assertSame([['machines', 2], ['prices', 1]], $rows->map(fn ($r) => [$r->area, $r->count])->all());
        $this->assertSame($this->prairie->id, $rows->first()->dealer_link_id);
        $this->assertSame($this->prairieUser->id, $rows->first()->user_id);
        $this->assertSame(now()->toDateString(), $rows->first()->day->toDateString());
    }

    public function test_api_requests_on_their_own_dont_count(): void
    {
        // The machine page loads models for its filters - that isn't "using Products".
        $this->actingAs($this->prairieUser)->getJson('/api/m/acme/machines')->assertOk();
        $this->actingAs($this->prairieUser)->getJson('/api/m/acme/product-models')->assertOk();

        $this->assertSame(0, UsageDay::withoutTenant()->count());
    }

    public function test_only_the_area_is_kept(): void
    {
        $this->actingAs($this->prairieUser)->postJson('/api/m/acme/usage/visits', ['area' => 'machines', 'path' => '/m/acme/machines/12?q=SN-SECRET'])->assertNoContent();

        $row = UsageDay::withoutTenant()->sole();
        $this->assertSame(['id', 'manufacturer_id', 'user_id', 'dealer_link_id', 'day', 'area', 'count'], array_keys($row->getAttributes()));
        $this->assertStringNotContainsString('SECRET', json_encode($row->getAttributes()));
    }

    public function test_areas_the_person_cant_use_and_unknown_areas_dont_count(): void
    {
        $this->visit($this->prairieUser, 'broadcasts'); // staff only
        $this->visit($this->prairieUser, 'fleet'); // staff only
        $this->visit($this->owner, 'announcements'); // dealers only
        $this->actingAs($this->owner)->postJson('/api/m/acme/usage/visits', ['area' => 'billing'])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson('/api/m/acme/usage/visits', ['area' => 'anything'])->assertUnprocessable();

        $this->assertSame(0, UsageDay::withoutTenant()->count());
    }

    public function test_accounts_without_the_report_record_nothing(): void
    {
        $this->acme->update(['plan' => Plan::STARTER]);

        $this->visit($this->prairieUser, 'machines');

        $this->assertSame(0, UsageDay::withoutTenant()->count());
        $this->actingAs($this->owner)->getJson('/api/m/acme/usage')->assertForbidden()->assertJsonPath('code', 'upgrade_required');
    }

    public function test_a_lapsed_account_records_nothing(): void
    {
        $this->acme->update(['plan' => Plan::EXPIRED]);

        $this->actingAs($this->prairieUser)->postJson('/api/m/acme/usage/visits', ['area' => 'machines'])->assertStatus(402);

        $this->assertSame(0, UsageDay::withoutTenant()->count());
    }

    public function test_the_report_shows_who_uses_it_and_who_doesnt(): void
    {
        $this->visit($this->prairieUser, 'quotes');
        $this->visit($this->prairieUser, 'quotes');
        $this->visit($this->prairieUser, 'machines');
        $this->visit($this->owner, 'machines');
        // More than anything else, but every sign-in lands there - so it isn't "used most".
        foreach (range(1, 3) as $i) {
            $this->visit($this->prairieUser, 'dashboard');
        }
        // An older day, still inside 30 days.
        Tenant::run($this->acme, fn () => UsageDay::create(['user_id' => $this->prairieUser->id, 'dealer_link_id' => $this->prairie->id, 'day' => now()->subDays(10), 'area' => 'documents', 'count' => 4]));

        $report = $this->actingAs($this->owner)->getJson('/api/m/acme/usage?days=30')->assertOk()->json();

        $this->assertSame(['dealers_total' => 2, 'dealers_active' => 1, 'people_active' => 2, 'visits' => 11], $report['summary']);
        $this->assertCount(30, $report['daily']);
        $this->assertSame(1, end($report['daily'])['dealers']);
        $this->assertSame('Prairie Equipment', $report['dealers'][0]['name']);
        $this->assertSame(2, $report['dealers'][0]['days_active']);
        $this->assertSame(['documents', 'quotes', 'machines'], $report['dealers'][0]['top_areas']);
        // The dealer who never used it is listed too.
        $this->assertSame(['Northern Ag', 0, null], [$report['dealers'][1]['name'], $report['dealers'][1]['visits'], $report['dealers'][1]['last_active']]);
        $machines = collect($report['areas'])->firstWhere('area', 'machines');
        $this->assertSame([1, 1], [$machines['dealer_visits'], $machines['staff_visits']]);
    }

    public function test_a_suspended_dealer_isnt_counted_as_active_or_listed(): void
    {
        $this->visit($this->prairieUser, 'machines');
        Tenant::run($this->acme, fn () => $this->prairie->update(['status' => DealerLink::SUSPENDED]));

        $report = $this->actingAs($this->owner)->getJson('/api/m/acme/usage')->assertOk()->json();

        $this->assertSame([1, 0], [$report['summary']['dealers_total'], $report['summary']['dealers_active']]);
        $this->assertSame(['Northern Ag'], array_column($report['dealers'], 'name'));
    }

    public function test_only_owners_and_admins_see_the_report(): void
    {
        $this->actingAs($this->staff($this->acme, 'sales_rep'))->getJson('/api/m/acme/usage')->assertForbidden();
        $this->actingAs($this->prairieUser)->getJson('/api/m/acme/usage')->assertForbidden();
        $this->actingAs($this->staff($this->acme, 'admin'))->getJson('/api/m/acme/usage?days=90')->assertOk()->assertJsonCount(90, 'daily');
        // No ?days= means the last 30.
        $this->actingAs($this->owner)->getJson('/api/m/acme/usage')->assertOk()->assertJsonPath('days', 30);
        $this->actingAs($this->owner)->getJson('/api/m/acme/usage?days=7')->assertUnprocessable();
    }

    public function test_counts_older_than_13_months_are_deleted(): void
    {
        Tenant::run($this->acme, function () {
            UsageDay::create(['user_id' => $this->owner->id, 'day' => now()->subMonths(14), 'area' => 'machines', 'count' => 1]);
            UsageDay::create(['user_id' => $this->owner->id, 'day' => now()->subMonths(12), 'area' => 'machines', 'count' => 1]);
        });

        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertSame(1, UsageDay::withoutTenant()->count());
    }

    private function visit(User $user, string $area): void
    {
        $this->actingAs($user)->postJson('/api/m/acme/usage/visits', ['area' => $area])->assertNoContent();
    }
}
