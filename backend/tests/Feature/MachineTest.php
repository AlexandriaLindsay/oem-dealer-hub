<?php

namespace Tests\Feature;

use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\MachineEvent;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Products, machines, statuses, transfers and the history they leave. */
class MachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_add_models_and_options(): void
    {
        $acme = $this->manufacturer('acme');
        $user = $this->staff($acme);
        $line = $this->actingAs($user)->postJson('/api/m/acme/product-lines', ['name' => 'Scrapers'])->json('id');

        $model = $this->actingAs($user)
            ->postJson('/api/m/acme/product-models', ['product_line_id' => $line, 'name' => 'S-400', 'category' => 'Scraper'])
            ->assertCreated()
            ->assertJsonPath('product_line.name', 'Scrapers')
            ->json('id');

        $this->actingAs($user)->postJson("/api/m/acme/product-models/{$model}/options", ['name' => 'Hydraulic tilt'])->assertCreated();
        $this->actingAs($user)->postJson("/api/m/acme/product-models/{$model}/options", ['name' => 'Hydraulic tilt'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/m/acme/product-models', ['product_line_id' => $line, 'name' => 'S-400'])->assertUnprocessable();

        $this->actingAs($user)->getJson('/api/m/acme/product-models')
            ->assertJsonPath('0.options.0.name', 'Hydraulic tilt');
    }

    public function test_adding_a_machine_starts_its_history(): void
    {
        $acme = $this->manufacturer('acme');
        $link = $this->link($this->dealer('Prairie Equipment', $acme), $acme);
        $model = $this->productModel($acme);
        $user = $this->staff($acme);

        $id = $this->actingAs($user)
            ->postJson('/api/m/acme/machines', ['product_model_id' => $model->id, 'serial_no' => 'SN-1', 'dealer_link_id' => $link->id, 'note' => 'First one'])
            ->assertCreated()
            ->assertJsonPath('dealer.name', 'Prairie Equipment')
            ->assertJsonPath('status', 'inventory')
            ->json('id');

        $this->actingAs($user)->getJson("/api/m/acme/machines/{$id}")
            ->assertJsonPath('shipped_at', now()->toDateString())
            ->assertJsonPath('history.0.summary', 'Added, at Prairie Equipment')
            ->assertJsonPath('history.0.note', 'First one')
            ->assertJsonPath('history.0.by', $user->name);

        // Serial numbers are unique per manufacturer.
        $this->actingAs($user)->postJson('/api/m/acme/machines', ['product_model_id' => $model->id, 'serial_no' => 'SN-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('serial_no');
    }

    public function test_a_status_change_is_kept_in_the_history(): void
    {
        $acme = $this->manufacturer('acme');
        $machine = $this->machine($acme, 'SN-1');
        $user = $this->staff($acme);

        $this->actingAs($user)->postJson("/api/m/acme/machines/{$machine->id}/status", ['status' => 'demo', 'note' => 'Farm show'])
            ->assertOk()
            ->assertJsonPath('status', 'demo')
            ->assertJsonPath('history.0.summary', 'Status changed from inventory to demo')
            ->assertJsonPath('history.0.note', 'Farm show');

        $this->actingAs($user)->postJson("/api/m/acme/machines/{$machine->id}/status", ['status' => 'scrapped'])
            ->assertUnprocessable();
    }

    public function test_setting_the_same_status_again_adds_nothing(): void
    {
        $acme = $this->manufacturer('acme');
        $machine = $this->machine($acme, 'SN-1');

        $this->actingAs($this->staff($acme))->postJson("/api/m/acme/machines/{$machine->id}/status", ['status' => 'inventory'])->assertOk();

        $this->assertSame(1, MachineEvent::withoutTenant()->count());
    }

    public function test_a_dealer_changes_the_status_of_their_own_machine_but_cant_add_or_move_machines(): void
    {
        $acme = $this->manufacturer('acme');
        $prairie = $this->dealer('Prairie Equipment', $acme);
        $machine = $this->machine($acme, 'SN-1', $this->link($prairie, $acme));
        $model = Tenant::run($acme, fn () => $machine->productModel);
        $user = $this->dealerUser($prairie, 'dealer_user');

        $this->actingAs($user)->postJson("/api/m/acme/machines/{$machine->id}/status", ['status' => 'sold'])
            ->assertOk()->assertJsonPath('history.0.by', $user->name);

        $this->actingAs($user)->postJson('/api/m/acme/machines', ['product_model_id' => $model->id, 'serial_no' => 'SN-2'])
            ->assertForbidden();
        $this->actingAs($user)->postJson("/api/m/acme/machines/{$machine->id}/transfer", ['dealer_link_id' => null])
            ->assertForbidden();
    }

    public function test_a_sales_rep_sees_machines_but_cant_add_them(): void
    {
        $acme = $this->manufacturer('acme');
        $model = $this->productModel($acme);
        $user = $this->staff($acme, 'sales_rep');

        $this->actingAs($user)->getJson('/api/m/acme/machines')->assertOk();
        $this->actingAs($user)->postJson('/api/m/acme/machines', ['product_model_id' => $model->id, 'serial_no' => 'SN-1'])
            ->assertForbidden()->assertJsonPath('code', 'feature_not_allowed');
    }

    public function test_moving_a_machine_between_dealers_and_back_to_the_factory(): void
    {
        $acme = $this->manufacturer('acme');
        $prairie = $this->link($this->dealer('Prairie Equipment', $acme), $acme);
        $northern = $this->link($this->dealer('Northern Ag', $acme), $acme);
        $machine = $this->machine($acme, 'SN-1', $prairie);
        $user = $this->staff($acme);

        $this->actingAs($user)->postJson("/api/m/acme/machines/{$machine->id}/transfer", ['dealer_link_id' => $northern->id])
            ->assertOk()
            ->assertJsonPath('dealer.name', 'Northern Ag')
            ->assertJsonPath('history.0.summary', 'Moved from Prairie Equipment to Northern Ag');

        $this->actingAs($user)->postJson("/api/m/acme/machines/{$machine->id}/transfer", ['dealer_link_id' => null])
            ->assertOk()
            ->assertJsonPath('dealer', null)
            ->assertJsonPath('history.0.summary', 'Moved from Northern Ag to Acme Manufacturing');
    }

    public function test_a_suspended_dealer_cant_be_sent_machines(): void
    {
        $acme = $this->manufacturer('acme');
        $link = $this->link($this->dealer('Paused Dealer', $acme), $acme);
        Tenant::run($acme, fn () => $link->update(['status' => DealerLink::SUSPENDED]));
        $machine = $this->machine($acme, 'SN-1');

        $this->actingAs($this->staff($acme))->postJson("/api/m/acme/machines/{$machine->id}/transfer", ['dealer_link_id' => $link->id])
            ->assertUnprocessable()->assertJsonValidationErrors('dealer_link_id');

        $this->assertNull(Machine::withoutTenant()->find($machine->id)->dealer_link_id);
    }

    public function test_the_machine_list_filters_by_status_dealer_and_serial(): void
    {
        $acme = $this->manufacturer('acme');
        $prairie = $this->link($this->dealer('Prairie Equipment', $acme), $acme);
        $this->machine($acme, 'AS4-001', $prairie, 'sold');
        $this->machine($acme, 'AS4-002', $prairie);
        $this->machine($acme, 'AR2-001');
        $user = $this->staff($acme);

        $this->actingAs($user)->getJson('/api/m/acme/machines?status=sold')->assertJsonPath('total', 1)->assertJsonPath('data.0.serial_no', 'AS4-001');
        $this->actingAs($user)->getJson('/api/m/acme/machines?dealer=none')->assertJsonPath('total', 1)->assertJsonPath('data.0.serial_no', 'AR2-001');
        $this->actingAs($user)->getJson("/api/m/acme/machines?dealer={$prairie->id}")->assertJsonPath('total', 2);
        $this->actingAs($user)->getJson('/api/m/acme/machines?q=AS4')->assertJsonPath('total', 2);
        // "%" is a literal character in a search, not a wildcard.
        $this->actingAs($user)->getJson('/api/m/acme/machines?q=%25')->assertJsonPath('total', 0);
    }

    public function test_the_dashboard_counts_machines_by_status(): void
    {
        $acme = $this->manufacturer('acme');
        $this->machine($acme, 'SN-1');
        $this->machine($acme, 'SN-2', null, 'rental');

        $this->actingAs($this->staff($acme))->getJson('/api/m/acme/dashboard')
            ->assertJsonPath('machines', ['inventory' => 1, 'demo' => 0, 'rental' => 1, 'sold' => 0, 'used' => 0]);
    }
}
