<?php

namespace Tests\Feature;

use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** How long unsold machines have been sitting - on the list, the fleet report and the dashboard. */
class AgingTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    private DealerLink $prairie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme);
        $this->prairie = $this->link($this->dealer('Prairie Equipment', $this->acme), $this->acme);
        $model = $this->productModel($this->acme, 'S-400');

        Tenant::run($this->acme, function () use ($model) {
            $add = fn (string $serial, ?DealerLink $at, string $status, array $dates) => Machine::add(['product_model_id' => $model->id, 'serial_no' => $serial, 'status' => $status] + $dates, $at, null);
            $add('NEW-30', $this->prairie, 'inventory', ['shipped_at' => now()->subDays(30)]);
            $add('OLD-200', $this->prairie, 'demo', ['shipped_at' => now()->subDays(200)]);
            $add('ANCIENT-400', $this->prairie, 'used', ['shipped_at' => now()->subDays(400)]);
            // Sold and rented machines don't age, however long ago they shipped.
            $add('SOLD-500', $this->prairie, 'sold', ['shipped_at' => now()->subDays(500)]);
            $add('RENTAL-500', $this->prairie, 'rental', ['shipped_at' => now()->subDays(500)]);
            // At the factory, counted from when it was built.
            $add('FACTORY-100', null, 'inventory', ['built_at' => now()->subDays(100)]);
        });
    }

    public function test_each_machine_says_how_long_its_been_sitting(): void
    {
        $rows = collect($this->actingAs($this->owner)->getJson('/api/m/acme/machines')->json('data'))->pluck('days_in_stock', 'serial_no');

        $this->assertSame(30, $rows['NEW-30']);
        $this->assertSame(200, $rows['OLD-200']);
        $this->assertSame(400, $rows['ANCIENT-400']);
        $this->assertSame(100, $rows['FACTORY-100']);
        $this->assertNull($rows['SOLD-500']);
        $this->assertNull($rows['RENTAL-500']);
    }

    public function test_the_list_filters_to_aged_stock(): void
    {
        $serials = fn (int $days) => collect($this->actingAs($this->owner)->getJson("/api/m/acme/machines?aged={$days}")->json('data'))->pluck('serial_no')->sort()->values()->all();

        $this->assertSame(['ANCIENT-400', 'OLD-200'], $serials(180));
        $this->assertSame(['ANCIENT-400', 'FACTORY-100', 'OLD-200'], $serials(90));
        $this->assertSame(['ANCIENT-400'], $serials(365));
    }

    public function test_the_fleet_report_bands_unsold_stock_by_dealer(): void
    {
        $aging = $this->actingAs($this->owner)->getJson('/api/m/acme/fleet')->assertOk()->json('aging');

        $this->assertSame(['0–90 days', '91–180 days', '181–365 days', 'Over a year'], $aging['bands']);
        $this->assertSame([1, 1, 1, 1], $aging['totals']);
        // The dealer with the oldest stock comes first.
        $this->assertSame('Prairie Equipment', $aging['by_dealer'][0]['name']);
        $this->assertSame([1, 0, 1, 1], $aging['by_dealer'][0]['counts']);
        $this->assertSame(400, $aging['by_dealer'][0]['oldest_days']);
        $this->assertNull($aging['by_dealer'][1]['id']);
        $this->assertSame([0, 1, 0, 0], $aging['by_dealer'][1]['counts']);
    }

    public function test_the_dashboard_counts_stock_over_180_days_for_staff_and_dealers(): void
    {
        $this->actingAs($this->owner)->getJson('/api/m/acme/dashboard')->assertJsonPath('aged_machines', 2);
        $dealer = $this->dealerUser($this->prairie->dealer);
        $this->actingAs($dealer)->getJson('/api/m/acme/dashboard')->assertJsonPath('aged_machines', 2);
    }
}
