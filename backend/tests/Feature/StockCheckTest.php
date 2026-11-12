<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\StockCheck;
use App\Models\User;
use App\Notifications\StockCheckRequested;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Dealers confirming what's really on their lot - once a month, one click. */
class StockCheckTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    private Dealer $prairie;

    private DealerLink $link;

    private User $dealerUser;

    /** @var array<string, Machine> */
    private array $machines;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme);
        $this->prairie = $this->dealer('Prairie Equipment', $this->acme);
        $this->link = $this->link($this->prairie, $this->acme);
        $this->dealerUser = $this->dealerUser($this->prairie);
        $model = $this->productModel($this->acme, 'S-400');

        $this->machines = Tenant::run($this->acme, fn () => collect(['LOT-1' => 'inventory', 'LOT-2' => 'demo', 'LOT-3' => 'rental', 'SOLD-1' => 'sold'])
            ->mapWithKeys(fn ($status, $serial) => [$serial => Machine::add(['product_model_id' => $model->id, 'serial_no' => $serial, 'status' => $status], $this->link, null)])->all());
    }

    public function test_a_check_asks_about_every_unsold_machine_and_emails_the_dealer(): void
    {
        $this->actingAs($this->owner)->postJson('/api/m/acme/stock-checks/start')->assertOk()->assertJsonPath('started', 1);

        $open = $this->actingAs($this->dealerUser)->getJson('/api/m/acme/stock-checks')->assertOk()->json('open');
        $this->assertSame(['LOT-1', 'LOT-2', 'LOT-3'], array_column($open['items'], 'serial_no'));
        Notification::assertSentTo($this->dealerUser, StockCheckRequested::class);

        // Not a second one while it's open.
        $this->actingAs($this->owner)->postJson('/api/m/acme/stock-checks/start')->assertJsonPath('started', 0);
    }

    public function test_everythings_here_confirms_every_machine_in_one_click(): void
    {
        $check = $this->start();

        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/stock-checks/{$check->id}/confirm-all")->assertOk()->assertJsonPath('status', 'done');

        $this->assertNotNull(Machine::withoutTenant()->where('serial_no', 'LOT-1')->value('confirmed_at'));
        $this->assertNull(Machine::withoutTenant()->where('serial_no', 'SOLD-1')->value('confirmed_at'));
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/stock-checks/{$check->id}/confirm-all")->assertUnprocessable()->assertJsonValidationErrors(['answers' => 'already finished']);
    }

    public function test_a_machine_not_there_is_flagged_to_the_manufacturer(): void
    {
        $check = $this->start();
        $lot2 = $this->machines['LOT-2']->id;

        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/stock-checks/{$check->id}/answers", ['answers' => [
            ['machine_id' => $lot2, 'answer' => 'not_here', 'note' => 'Sold last week, registration coming'],
        ]])->assertOk()->assertJsonPath('status', 'open');
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/stock-checks/{$check->id}/confirm-all")->assertJsonPath('status', 'done');

        $report = $this->actingAs($this->owner)->getJson('/api/m/acme/stock-checks')->assertOk();
        $report->assertJsonPath('dealers.0.dealer', 'Prairie Equipment')
            ->assertJsonPath('dealers.0.here', 2)
            ->assertJsonPath('dealers.0.not_here', 1)
            ->assertJsonPath('dealers.0.unanswered', 0)
            ->assertJsonPath('not_here.0.serial_no', 'LOT-2')
            ->assertJsonPath('not_here.0.note', 'Sold last week, registration coming');
        $this->assertNull(Machine::withoutTenant()->find($lot2)->confirmed_at);
    }

    public function test_the_email_link_works_without_signing_in_and_only_for_that_check(): void
    {
        $token = null;
        $this->start();
        Notification::assertSentTo($this->dealerUser, StockCheckRequested::class, function (StockCheckRequested $n) use (&$token) {
            preg_match('#/stock-check/([A-Za-z0-9]+)#', implode(' ', [$n->toMail($this->dealerUser)->actionUrl]), $m);
            $token = $m[1] ?? null;

            return true;
        });

        // Opening it changes nothing (mail scanners open links).
        $this->getJson("/api/stock-check/{$token}")->assertOk()->assertJsonPath('manufacturer', 'Acme Manufacturing')->assertJsonPath('dealer', 'Prairie Equipment')->assertJsonCount(3, 'items');
        $this->assertNull(Machine::withoutTenant()->where('serial_no', 'LOT-1')->value('confirmed_at'));

        $this->postJson("/api/stock-check/{$token}/confirm-all")->assertOk()->assertJsonPath('status', 'done');
        $this->assertNotNull(Machine::withoutTenant()->where('serial_no', 'LOT-1')->value('confirmed_at'));

        $this->getJson('/api/stock-check/not-a-real-token')->assertNotFound();
    }

    public function test_an_overdue_check_closes_and_staff_see_it_unanswered(): void
    {
        $check = $this->start();
        $this->travel(StockCheck::DAYS_TO_ANSWER + 1)->days();

        $this->artisan('stock-checks:run')->assertSuccessful();
        $this->assertSame(StockCheck::EXPIRED, StockCheck::withoutTenant()->find($check->id)->status);
        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/stock-checks/{$check->id}/confirm-all")->assertUnprocessable()->assertJsonValidationErrors(['answers' => 'closed']);
        $this->actingAs($this->owner)->getJson('/api/m/acme/stock-checks')->assertJsonPath('dealers.0.status', 'expired')->assertJsonPath('dealers.0.unanswered', 3);
    }

    public function test_an_overdue_check_doesnt_hold_up_the_next_one(): void
    {
        $old = $this->start();
        // Past due, but not closed yet (stock-checks:run hasn't come round).
        $this->travel(StockCheck::DAYS_TO_ANSWER + 1)->days();

        $this->actingAs($this->owner)->postJson('/api/m/acme/stock-checks/start')->assertJsonPath('started', 1);
        $this->assertSame(StockCheck::EXPIRED, StockCheck::withoutTenant()->find($old->id)->status);
        $this->assertSame(1, StockCheck::withoutTenant()->where('status', StockCheck::OPEN)->count());
    }

    public function test_monthly_rounds_start_once_a_month_when_turned_on(): void
    {
        $this->artisan('stock-checks:run')->assertSuccessful();
        $this->assertSame(0, StockCheck::withoutTenant()->count());

        $this->actingAs($this->owner)->postJson('/api/m/acme/stock-checks/settings', ['monthly' => true])->assertOk();
        $this->artisan('stock-checks:run')->assertSuccessful();
        $this->artisan('stock-checks:run')->assertSuccessful();
        $this->assertSame(1, StockCheck::withoutTenant()->count());

        // Next month, after this one's done.
        $this->actingAs($this->dealerUser)->postJson('/api/m/acme/stock-checks/'.StockCheck::withoutTenant()->value('id').'/confirm-all');
        $this->travel(1)->months();
        $this->artisan('stock-checks:run')->assertSuccessful();
        $this->assertSame(2, StockCheck::withoutTenant()->count());
    }

    public function test_who_does_what(): void
    {
        $check = $this->start();

        // Staff can't answer for the dealer; a sales rep reads but can't start checks.
        $this->actingAs($this->owner)->postJson("/api/m/acme/stock-checks/{$check->id}/confirm-all")->assertForbidden();
        $rep = $this->staff($this->acme, 'sales_rep');
        $this->actingAs($rep)->getJson('/api/m/acme/stock-checks')->assertOk();
        $this->actingAs($rep)->postJson('/api/m/acme/stock-checks/start')->assertForbidden();
        $this->actingAs($this->staff($this->acme, 'parts'))->getJson('/api/m/acme/stock-checks')->assertForbidden();

        // Another dealer never sees Prairie's check.
        $other = $this->dealerUser($this->dealer('Rival Equipment', $this->acme));
        $this->actingAs($other)->getJson('/api/m/acme/stock-checks')->assertJsonPath('open', null);
        $this->actingAs($other)->getJson("/api/m/acme/stock-checks/{$check->id}")->assertNotFound();
        $this->actingAs($other)->postJson("/api/m/acme/stock-checks/{$check->id}/confirm-all")->assertNotFound();
    }

    public function test_stock_checks_stay_inside_their_manufacturer(): void
    {
        $check = $this->start();
        $bolt = $this->manufacturer('bolt');

        $this->actingAs($this->staff($bolt))->getJson('/api/m/bolt/stock-checks')->assertOk()->assertJsonPath('dealers', []);
        $this->actingAs($this->staff($bolt))->getJson("/api/m/bolt/stock-checks/{$check->id}")->assertNotFound();
        $this->actingAs($this->staff($bolt))->postJson('/api/m/bolt/stock-checks/start', ['dealer_link_id' => $this->link->id])->assertNotFound();
    }

    private function start(): StockCheck
    {
        $this->actingAs($this->owner)->postJson('/api/m/acme/stock-checks/start')->assertOk()->assertJsonPath('started', 1);

        return StockCheck::withoutTenant()->latest('id')->first();
    }
}
