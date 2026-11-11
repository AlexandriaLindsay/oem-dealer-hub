<?php

namespace Tests\Feature;

use App\Models\Machine;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use ZipArchive;

/** The fleet report's counts and its Excel export. */
class FleetReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_by_status_dealer_and_model(): void
    {
        $acme = $this->manufacturer('acme');
        $prairie = $this->link($this->dealer('Prairie Equipment', $acme), $acme);
        $northern = $this->link($this->dealer('Northern Ag', $acme), $acme);
        $this->machine($acme, 'P-1', $prairie);
        $this->machine($acme, 'P-2', $prairie, 'sold');
        $this->machine($acme, 'P-3', $prairie, 'demo');
        $this->machine($acme, 'N-1', $northern, 'rental');
        $this->machine($acme, 'F-1');

        $this->actingAs($this->staff($acme))->getJson('/api/m/acme/fleet')
            ->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonPath('by_status', ['inventory' => 2, 'demo' => 1, 'rental' => 1, 'sold' => 1, 'used' => 0])
            // Largest dealer first; the factory is its own row.
            ->assertJsonPath('by_dealer.0.name', 'Prairie Equipment')
            ->assertJsonPath('by_dealer.0.total', 3)
            ->assertJsonPath('by_dealer.0.statuses.sold', 1)
            ->assertJsonPath('by_dealer.1.name', 'At the factory (Acme Manufacturing)')
            ->assertJsonPath('by_dealer.1.id', null)
            ->assertJsonPath('by_model.0.name', 'S-400')
            ->assertJsonPath('by_model.0.total', 5);
    }

    public function test_the_report_takes_the_machine_lists_filters(): void
    {
        $acme = $this->manufacturer('acme');
        $prairie = $this->link($this->dealer('Prairie Equipment', $acme), $acme);
        $this->machine($acme, 'S4-1', $prairie);
        $r200 = $this->productModel($acme, 'R-200');
        Tenant::run($acme, fn () => Machine::add(['product_model_id' => $r200->id, 'serial_no' => 'R2-1'], null, null));
        $user = $this->staff($acme);

        $this->actingAs($user)->getJson("/api/m/acme/fleet?model={$r200->id}")->assertJsonPath('total', 1);
        $this->actingAs($user)->getJson("/api/m/acme/fleet?line={$r200->product_line_id}")->assertJsonPath('total', 1);
        $this->actingAs($user)->getJson("/api/m/acme/fleet?dealer={$prairie->id}")->assertJsonPath('total', 1);
        $this->actingAs($user)->getJson('/api/m/acme/fleet?dealer=none')->assertJsonPath('by_model.0.name', 'R-200');
        $this->actingAs($user)->getJson('/api/m/acme/fleet?status=sold')->assertJsonPath('total', 0)->assertJsonPath('by_dealer', []);
    }

    public function test_an_empty_fleet_is_zeros_not_an_error(): void
    {
        $acme = $this->manufacturer('acme');

        $this->actingAs($this->staff($acme))->getJson('/api/m/acme/fleet')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('by_status.inventory', 0);
    }

    public function test_dealers_cant_see_the_fleet_report(): void
    {
        $acme = $this->manufacturer('acme');
        $prairie = $this->dealer('Prairie Equipment', $acme);
        $user = $this->dealerUser($prairie);

        $this->actingAs($user)->getJson('/api/m/acme/fleet')->assertForbidden()->assertJsonPath('code', 'feature_not_allowed');
        $this->actingAs($user)->get('/api/m/acme/fleet/export')->assertForbidden();
    }

    public function test_the_export_is_an_excel_file_with_one_row_per_machine(): void
    {
        $acme = $this->manufacturer('acme');
        $prairie = $this->link($this->dealer('Prairie Equipment', $acme), $acme);
        $this->machine($acme, 'SN-2', $prairie, 'sold');
        $this->machine($acme, 'SN-1');

        $response = $this->actingAs($this->staff($acme))->get('/api/m/acme/fleet/export')->assertOk();

        $this->assertStringContainsString('acme-fleet-'.now()->toDateString().'.xlsx', $response->headers->get('Content-Disposition'));
        $rows = $this->rows($response);

        $this->assertSame('Serial number', $rows[0][0]);
        $this->assertCount(3, $rows);
        // Sorted by serial number.
        $this->assertSame(['SN-1', 'At the factory', 'Inventory'], [$rows[1][0], $rows[1][5], $rows[1][4]]);
        $this->assertSame(['SN-2', 'Prairie Equipment', 'Sold'], [$rows[2][0], $rows[2][5], $rows[2][4]]);
    }

    public function test_the_export_takes_the_same_filters(): void
    {
        $acme = $this->manufacturer('acme');
        $this->machine($acme, 'SN-1', null, 'sold');
        $this->machine($acme, 'SN-2');

        $rows = $this->rows($this->actingAs($this->staff($acme))->get('/api/m/acme/fleet/export?status=sold')->assertOk());

        $this->assertCount(2, $rows);
        $this->assertSame('SN-1', $rows[1][0]);
    }

    public function test_text_that_looks_like_a_formula_is_exported_as_plain_text(): void
    {
        $acme = $this->manufacturer('acme');
        $machine = $this->machine($acme, '=HYPERLINK("https://evil.example","Click")');
        Tenant::run($acme, fn () => $machine->forceFill(['production_no' => '=1+1'])->save());

        $response = $this->actingAs($this->staff($acme))->get('/api/m/acme/fleet/export')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();

        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringNotContainsString('<f>', $sheet, 'The export must not contain any formula cells.');
        $this->assertSame('=1+1', $this->rows($response)[1][1]);
    }

    /** @return list<list<mixed>> every row of the exported workbook's first sheet */
    private function rows(TestResponse $response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        return $rows;
    }
}
