<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\PriceList;
use App\Models\Quote;
use App\Models\Territory;
use App\Models\User;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Setting territories up, and what they narrow down for the reps covering them. */
class TerritoryTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    private Dealer $prairie;

    private Dealer $northern;

    private DealerLink $prairieLink;

    private DealerLink $northernLink;

    private Territory $canada;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme);
        $this->prairie = $this->dealer('Prairie Equipment', $this->acme);
        $this->northern = $this->dealer('Northern Ag', $this->acme);
        $this->prairieLink = $this->link($this->prairie, $this->acme);
        $this->northernLink = $this->link($this->northern, $this->acme);

        // Canada holds Prairie; the rep covers Canada.
        $this->canada = Territory::find($this->actingAs($this->owner)->postJson('/api/m/acme/territories', ['name' => 'Canada'])->assertCreated()->json('id'));
        $this->actingAs($this->owner)->postJson("/api/m/acme/dealers/{$this->prairieLink->id}/territory", ['territory_id' => $this->canada->id])->assertOk();
        $this->rep = $this->staff($this->acme, 'sales_rep');
        $this->actingAs($this->owner)->putJson("/api/m/acme/territories/{$this->canada->id}/reps", ['membership_ids' => [$this->membershipOf($this->rep)]])
            ->assertOk()->assertJsonPath('rep_ids', [$this->membershipOf($this->rep)]);
    }

    // ---- Setting up ----

    public function test_the_page_lists_territories_reps_and_every_dealer(): void
    {
        $this->staff($this->acme, 'parts'); // can't cover a territory, so not offered

        $this->actingAs($this->owner)->getJson('/api/m/acme/territories')->assertOk()
            ->assertJsonPath('territories.0', ['id' => $this->canada->id, 'name' => 'Canada', 'rep_ids' => [$this->membershipOf($this->rep)], 'dealer_count' => 1])
            ->assertJsonCount(1, 'reps')
            ->assertJsonPath('reps.0.role', 'sales_rep')
            ->assertJsonPath('dealers.0.name', 'Northern Ag')
            ->assertJsonPath('dealers.0.territory_id', null)
            ->assertJsonPath('dealers.1.territory_id', $this->canada->id);
        // The dealer list shows each dealer's territory.
        $this->actingAs($this->owner)->getJson('/api/m/acme/dealers')->assertJsonPath('1.territory', ['id' => $this->canada->id, 'name' => 'Canada']);
    }

    public function test_names_are_unique_and_renaming_works(): void
    {
        $this->actingAs($this->owner)->postJson('/api/m/acme/territories', ['name' => 'Canada'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name' => 'already have a territory']);
        $this->actingAs($this->owner)->patchJson("/api/m/acme/territories/{$this->canada->id}", ['name' => 'Western Canada'])->assertOk();

        $this->assertSame('Western Canada', $this->canada->fresh()->name);
    }

    public function test_only_sales_staff_can_cover_a_territory(): void
    {
        $parts = $this->staff($this->acme, 'parts');
        $admin = $this->staff($this->acme, 'admin');

        foreach ([$parts, $admin, $this->owner] as $user) {
            $this->actingAs($this->owner)->putJson("/api/m/acme/territories/{$this->canada->id}/reps", ['membership_ids' => [$this->membershipOf($user)]])
                ->assertUnprocessable()->assertJsonValidationErrors(['membership_ids.0' => 'Only your sales reps']);
        }
        // An empty list takes everyone off it.
        $this->actingAs($this->owner)->putJson("/api/m/acme/territories/{$this->canada->id}/reps", ['membership_ids' => []])->assertOk()->assertJsonPath('rep_ids', []);
    }

    public function test_only_owners_and_admins_manage_territories_on_pro(): void
    {
        $this->actingAs($this->rep)->getJson('/api/m/acme/territories')->assertForbidden();
        $this->actingAs($this->rep)->postJson("/api/m/acme/dealers/{$this->northernLink->id}/territory", ['territory_id' => $this->canada->id])->assertForbidden();
        $this->actingAs($this->dealerUser($this->prairie))->getJson('/api/m/acme/territories')->assertForbidden();
        $this->actingAs($this->staff($this->acme, 'admin'))->getJson('/api/m/acme/territories')->assertOk();

        $this->acme->update(['plan' => Plan::STARTER]);
        $this->actingAs($this->owner)->getJson('/api/m/acme/territories')->assertForbidden()->assertJsonPath('code', 'upgrade_required');
    }

    public function test_deleting_a_territory_frees_its_dealers_and_reps(): void
    {
        $this->actingAs($this->owner)->deleteJson("/api/m/acme/territories/{$this->canada->id}")->assertNoContent();

        $this->assertNull($this->prairieLink->fresh()->territory_id);
        $this->actingAs($this->rep)->getJson('/api/m/acme/dealers')->assertJsonCount(2);
    }

    // ---- What a rep in a territory sees ----

    public function test_a_rep_in_a_territory_sees_only_its_dealers(): void
    {
        $this->actingAs($this->rep)->getJson('/api/m/acme/dealers')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Prairie Equipment');
        $this->actingAs($this->rep)->getJson('/api/me')->assertJsonPath('manufacturers.0.territories', ['Canada']);

        // Moving Northern into Canada brings it into view.
        $this->actingAs($this->owner)->postJson("/api/m/acme/dealers/{$this->northernLink->id}/territory", ['territory_id' => $this->canada->id])->assertOk();
        $this->actingAs($this->rep)->getJson('/api/m/acme/dealers')->assertJsonCount(2);
    }

    public function test_a_rep_sees_their_dealers_machines_and_the_factorys_stock_but_no_one_elses(): void
    {
        $this->machine($this->acme, 'MINE-1', $this->prairieLink);
        $theirs = $this->machine($this->acme, 'THEIRS-1', $this->northernLink);
        $this->machine($this->acme, 'FACTORY-1');

        $serials = collect($this->actingAs($this->rep)->getJson('/api/m/acme/machines')->assertOk()->json('data'))->pluck('serial_no')->sort()->values()->all();
        $this->assertSame(['FACTORY-1', 'MINE-1'], $serials);
        $this->actingAs($this->rep)->getJson("/api/m/acme/machines/{$theirs->id}")->assertNotFound();

        $fleet = $this->actingAs($this->rep)->getJson('/api/m/acme/fleet')->assertOk();
        $this->assertSame(2, $fleet->json('total'));
        $this->assertEqualsCanonicalizing(['Prairie Equipment', 'At the factory (Acme Manufacturing)'], collect($fleet->json('by_dealer'))->pluck('name')->all());
    }

    public function test_a_rep_sees_only_their_dealers_quotes_parts_orders_and_registrations(): void
    {
        // Quotes need prices to quote from.
        Tenant::run($this->acme, fn () => PriceList::create(['name' => 'Retail', 'currency' => 'USD'])->forceFill(['is_default' => true])->save());
        // Sent, since manufacturer staff never see a dealer's drafts.
        $quote = function (Dealer $d) {
            $id = $this->actingAs($this->dealerUser($d))->postJson('/api/m/acme/quotes', ['customer' => ['contact_name' => 'Customer of '.$d->name]])->assertCreated()->json('id');
            Quote::withoutTenant()->find($id)->forceFill(['status' => Quote::SENT])->save();

            return $id;
        };
        $order = fn (Dealer $d) => $this->actingAs($this->dealerUser($d))->postJson('/api/m/acme/parts-orders', ['ship_method' => 'pickup', 'lines' => [['part_number' => 'P-1', 'quantity' => 1]]])->assertCreated()->json('id');
        $quote($this->prairie);
        $theirQuote = $quote($this->northern);
        $order($this->prairie);
        $theirOrder = $order($this->northern);
        $inside = $this->staff($this->acme, 'inside_sales');
        $this->actingAs($this->owner)->putJson("/api/m/acme/territories/{$this->canada->id}/reps", ['membership_ids' => [$this->membershipOf($this->rep), $this->membershipOf($inside)]])->assertOk();

        $this->actingAs($this->rep)->getJson('/api/m/acme/quotes')->assertJsonPath('total', 1);
        $this->actingAs($this->rep)->getJson("/api/m/acme/quotes/{$theirQuote}")->assertNotFound();
        $this->actingAs($this->rep)->postJson("/api/m/acme/quotes/{$theirQuote}/outcome", ['status' => 'won'])->assertNotFound();
        // The owner wins it; the order it becomes stays outside the rep's view too.
        $theirSalesOrder = $this->actingAs($this->owner)->postJson("/api/m/acme/quotes/{$theirQuote}/outcome", ['status' => 'won'])->assertOk()->json('sales_order.id');
        $this->actingAs($this->rep)->getJson('/api/m/acme/sales-orders')->assertJsonPath('total', 0);
        $this->actingAs($this->rep)->getJson("/api/m/acme/sales-orders/{$theirSalesOrder}")->assertNotFound();
        $this->actingAs($this->owner)->getJson('/api/m/acme/sales-orders')->assertJsonPath('total', 1);
        $this->actingAs($inside)->getJson('/api/m/acme/parts-orders')->assertJsonPath('total', 1);
        $this->actingAs($inside)->getJson("/api/m/acme/parts-orders/{$theirOrder}")->assertNotFound();
        $this->actingAs($this->rep)->getJson('/api/m/acme/registrations')->assertOk();
        // The parts desk works every order - parts staff aren't limited by territories.
        $this->actingAs($this->staff($this->acme, 'parts'))->getJson('/api/m/acme/parts-orders')->assertJsonPath('total', 2);
    }

    public function test_a_rep_can_only_send_stock_to_their_own_dealers(): void
    {
        $inside = $this->staff($this->acme, 'inside_sales');
        $this->actingAs($this->owner)->putJson("/api/m/acme/territories/{$this->canada->id}/reps", ['membership_ids' => [$this->membershipOf($inside)]])->assertOk();
        $machine = $this->machine($this->acme, 'FACTORY-1');

        $this->actingAs($inside)->postJson("/api/m/acme/machines/{$machine->id}/transfer", ['dealer_link_id' => $this->northernLink->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['dealer_link_id' => 'Choose one of your active dealers']);
        $this->actingAs($inside)->postJson("/api/m/acme/machines/{$machine->id}/transfer", ['dealer_link_id' => $this->prairieLink->id])->assertOk();
    }

    public function test_a_limited_sender_broadcasts_only_to_their_dealers_and_sees_others_counted_not_named(): void
    {
        $inside = $this->staff($this->acme, 'inside_sales');
        $this->actingAs($this->owner)->putJson("/api/m/acme/territories/{$this->canada->id}/reps", ['membership_ids' => [$this->membershipOf($inside)]])->assertOk();

        $this->actingAs($inside)->postJson('/api/m/acme/broadcasts', ['title' => 'Hi', 'body' => 'Hello', 'audience' => 'selected', 'dealer_link_ids' => [$this->northernLink->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('dealer_link_ids.0');
        // "All" means all of theirs.
        $mine = $this->actingAs($inside)->postJson('/api/m/acme/broadcasts', ['title' => 'Hi', 'body' => 'Hello', 'audience' => 'all'])->assertCreated()->json('id');
        $this->actingAs($this->owner)->getJson("/api/m/acme/broadcasts/{$mine}")->assertJsonCount(1, 'recipients');

        // The owner's company-wide broadcast: the limited sender sees their dealers, and a count of the rest.
        $everyone = $this->actingAs($this->owner)->postJson('/api/m/acme/broadcasts', ['title' => 'All', 'body' => 'Everyone', 'audience' => 'all'])->assertCreated()->json('id');
        $this->actingAs($inside)->getJson("/api/m/acme/broadcasts/{$everyone}")->assertOk()
            ->assertJsonCount(1, 'recipients')->assertJsonPath('recipients.0.dealer', 'Prairie Equipment')->assertJsonPath('other_recipients', 1);
    }

    public function test_machine_history_doesnt_name_dealers_outside_the_reps_territories(): void
    {
        $machine = $this->machine($this->acme, 'MOVED-1', $this->northernLink);
        Tenant::run($this->acme, fn () => $machine->transferTo($this->prairieLink, $this->owner));

        $history = collect($this->actingAs($this->rep)->getJson("/api/m/acme/machines/{$machine->id}")->assertOk()->json('history'))->pluck('summary')->implode(' | ');
        $this->assertStringNotContainsString('Northern', $history);
        $this->assertStringContainsString('a dealer outside your territories', $history);
    }

    // ---- Who isn't limited ----

    public function test_a_rep_in_no_territory_and_everyone_else_sees_every_dealer(): void
    {
        $this->actingAs($this->staff($this->acme, 'sales_rep'))->getJson('/api/m/acme/dealers')->assertJsonCount(2);
        $this->actingAs($this->owner)->getJson('/api/m/acme/dealers')->assertJsonCount(2);
        $this->actingAs($this->owner)->getJson('/api/me')->assertJsonPath('manufacturers.0.territories', null);
    }

    public function test_a_rep_promoted_to_admin_isnt_limited_any_more(): void
    {
        Membership::whereKey($this->membershipOf($this->rep))->update(['role' => 'admin']);

        $this->actingAs($this->rep)->getJson('/api/m/acme/dealers')->assertJsonCount(2);
    }

    public function test_without_territories_on_the_plan_nobody_is_limited_and_assignments_are_kept(): void
    {
        $this->acme->update(['plan' => Plan::STARTER]);

        $this->actingAs($this->rep)->getJson('/api/m/acme/dealers')->assertJsonCount(2);
        $this->actingAs($this->rep)->getJson('/api/me')->assertJsonPath('manufacturers.0.territories', null);
        $this->assertSame($this->canada->id, $this->prairieLink->fresh()->territory_id);

        $this->acme->update(['plan' => Plan::PRO]);
        $this->actingAs($this->rep)->getJson('/api/m/acme/dealers')->assertJsonCount(1);
    }

    public function test_background_work_sees_every_dealer(): void
    {
        // Tenant::run sets no membership: jobs and commands aren't any rep.
        $this->assertSame(2, Tenant::run($this->acme, fn () => DealerLink::count()));
    }

    private function membershipOf(User $user): int
    {
        return Membership::where('user_id', $user->id)->where('org_type', Membership::MANUFACTURER)->value('id');
    }
}
