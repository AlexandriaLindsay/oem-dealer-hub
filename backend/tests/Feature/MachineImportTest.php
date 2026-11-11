<?php

namespace Tests\Feature;

use App\Imports\MachineImporter;
use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Machine;
use App\Models\MachineImport;
use App\Models\Manufacturer;
use App\Models\ProductModel;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;
use ZipArchive;

/** Importing machines from CSV and Excel: preview, rules, updates, and the audit report. */
class MachineImportTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    private ProductModel $s400;

    private DealerLink $prairie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme);
        $this->s400 = $this->productModel($this->acme, 'S-400');
        $this->prairie = $this->link($this->dealer('Prairie Equipment', $this->acme), $this->acme);
        Tenant::run($this->acme, fn () => $this->prairie->update(['dealer_code' => 'PRA001']));
    }

    public function test_the_preview_reads_common_headers_and_writes_nothing(): void
    {
        $csv = "S/N,Model Name,Dealer Code,Status,Colour\nAS4-1,S-400,PRA001,demo,red\nAS4-2,S-999,,,\n";

        $this->send('preview', $csv)
            ->assertOk()
            ->assertJsonPath('summary', ['rows' => 2, 'create' => 1, 'update' => 0, 'unchanged' => 0, 'skip' => 0, 'error' => 1])
            ->assertJsonPath('columns.ignored', ['Colour'])
            ->assertJsonPath('rows.0.action', 'create')
            ->assertJsonPath('rows.1.line', 3)
            ->assertJsonPath('rows.1.messages.0', 'There\'s no model called "S-999". Add it under Products first, or check the spelling.')
            ->assertJsonMissingPath('rows.0.plan');

        $this->assertSame(0, Machine::withoutTenant()->count());
        $this->assertSame(0, MachineImport::withoutTenant()->count());
    }

    public function test_importing_adds_machines_with_their_dealer_status_and_dates(): void
    {
        $csv = "Serial number,Model,Production number,Dealer,Status,Built on,Shipped on\n"
            ."AS4-1,s-400,PO-1,PRA001,sold,2025-03-15,4/2/2025\n"
            ."AS4-2,S-400,,Prairie Equipment,,,\n"
            ."AS4-3,S-400,,,,,\n"
            ."AS4-4,S-400,,Nobody,,,\n";

        $this->send('import', $csv)
            ->assertCreated()
            ->assertJsonPath('summary.create', 3)
            ->assertJsonPath('summary.error', 1)
            ->assertJsonPath('by', $this->owner->name);

        $m = fn (string $serial) => Machine::withoutTenant()->where('serial_no', $serial)->first();
        $this->assertSame(['sold', $this->prairie->id, 'PO-1', '2025-03-15', '2025-04-02'], [$m('AS4-1')->status, $m('AS4-1')->dealer_link_id, $m('AS4-1')->production_no, $m('AS4-1')->built_at->toDateString(), $m('AS4-1')->shipped_at->toDateString()]);
        // Matched by name; no ship date in the file stays unknown - not "today".
        $this->assertSame([$this->prairie->id, 'inventory', null], [$m('AS4-2')->dealer_link_id, $m('AS4-2')->status, $m('AS4-2')->shipped_at]);
        $this->assertNull($m('AS4-3')->dealer_link_id);
        $this->assertNull($m('AS4-4'));

        $this->actingAs($this->owner)->getJson("/api/m/acme/machines/{$m('AS4-1')->id}")
            ->assertJsonPath('history.0.note', 'Imported from fleet.csv (import #1)');
    }

    public function test_every_kind_of_row_problem_is_explained(): void
    {
        $paused = $this->link($this->dealer('Paused Dealer', $this->acme), $this->acme);
        Tenant::run($this->acme, fn () => $paused->update(['status' => DealerLink::SUSPENDED]));
        // Two dealers with the same name: only their codes can tell them apart.
        foreach (['TW1', 'TW2'] as $code) {
            $link = $this->link($this->dealer('Twin Implements', $this->acme), $this->acme);
            Tenant::run($this->acme, fn () => $link->update(['dealer_code' => $code]));
        }

        $csv = "Serial,Model,Dealer,Status,Built\n"
            .",S-400,,,\n"
            ."A-2,,,,\n"
            ."A-3,S-400,Paused Dealer,,\n"
            ."A-4,S-400,Twin Implements,,\n"
            ."A-5,S-400,,scrapped,\n"
            ."A-6,S-400,,,15/03/2025\n"
            ."A-7,S-400,,,\n"
            ."a-7,S-400,,,\n"
            ."A-8,S-400,TW2,,\n";

        $rows = collect($this->send('preview', $csv)->assertOk()->json('rows'))->keyBy('line');

        $this->assertSame('No serial number.', $rows[2]['messages'][0]);
        $this->assertSame('No model.', $rows[3]['messages'][0]);
        $this->assertStringContainsString('suspended', $rows[4]['messages'][0]);
        $this->assertStringContainsString('Use their dealer code', $rows[5]['messages'][0]);
        $this->assertStringContainsString('"scrapped" isn\'t a status', $rows[6]['messages'][0]);
        $this->assertStringContainsString('"15/03/2025" isn\'t a date', $rows[7]['messages'][0]);
        $this->assertSame('create', $rows[8]['action']);
        $this->assertStringContainsString('also on row 8', $rows[9]['messages'][0]);
        $this->assertSame('create', $rows[10]['action']); // by code
    }

    public function test_a_file_without_the_required_columns_is_refused(): void
    {
        $this->send('preview', "Serial,Colour\nA-1,red\n")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'needs a column for: Model']);

        $this->send('preview', "Serial,S/N,Model\nA-1,A-1,S-400\n")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Two columns both look like "Serial number"']);
    }

    public function test_add_only_mode_leaves_existing_machines_alone(): void
    {
        $this->machine($this->acme, 'AS4-1');

        $this->send('import', "Serial,Model,Status\nAS4-1,S-400,sold\nAS4-2,S-400,\n")
            ->assertJsonPath('summary.skip', 1)
            ->assertJsonPath('summary.create', 1);

        $this->assertSame('inventory', Machine::withoutTenant()->where('serial_no', 'AS4-1')->value('status'));
    }

    public function test_update_mode_changes_what_the_file_says_and_records_it(): void
    {
        $northern = $this->link($this->dealer('Northern Ag', $this->acme), $this->acme);
        $moving = $this->machine($this->acme, 'AS4-1', $this->prairie);
        $this->machine($this->acme, 'AS4-2', $this->prairie, 'demo');
        $home = $this->machine($this->acme, 'AS4-3', $this->prairie);
        Tenant::run($this->acme, fn () => $moving->update(['production_no' => 'PO-OLD']));

        $csv = "Serial,Model,Dealer,Status,Production number\n"
            ."AS4-1,S-400,Northern Ag,sold,PO-NEW\n"
            ."AS4-2,S-400,,,\n"       // blanks change nothing
            ."AS4-3,S-400,factory,,\n"; // back to the factory, said explicitly

        $preview = $this->send('preview', $csv, MachineImporter::ADD_UPDATE)->json();
        $this->assertSame(['rows' => 3, 'create' => 0, 'update' => 2, 'unchanged' => 1, 'skip' => 0, 'error' => 0], $preview['summary']);
        $this->assertSame([
            ['field' => 'Dealer', 'from' => 'Prairie Equipment', 'to' => 'Northern Ag'],
            ['field' => 'Status', 'from' => 'inventory', 'to' => 'sold'],
            ['field' => 'Production number', 'from' => 'PO-OLD', 'to' => 'PO-NEW'],
        ], $preview['rows'][0]['changes']);

        $this->send('import', $csv, MachineImporter::ADD_UPDATE)->assertCreated();

        $moving = Machine::withoutTenant()->find($moving->id);
        $this->assertSame([$northern->id, 'sold', 'PO-NEW'], [$moving->dealer_link_id, $moving->status, $moving->production_no]);
        $this->assertSame('demo', Machine::withoutTenant()->where('serial_no', 'AS4-2')->value('status'));
        $this->assertNull(Machine::withoutTenant()->find($home->id)->dealer_link_id);

        $history = $this->actingAs($this->owner)->getJson("/api/m/acme/machines/{$moving->id}")->json('history');
        $this->assertSame('Status changed from inventory to sold', $history[0]['summary']);
        $this->assertSame('Moved from Prairie Equipment to Northern Ag', $history[1]['summary']);
        $this->assertSame('Imported from fleet.csv (import #1)', $history[0]['note']);
    }

    public function test_an_import_never_changes_a_machines_model(): void
    {
        $this->productModel($this->acme, 'R-200');
        $this->machine($this->acme, 'AS4-1', model: $this->s400);

        $this->send('preview', "Serial,Model\nAS4-1,R-200\n", MachineImporter::ADD_UPDATE)
            ->assertJsonPath('rows.0.action', 'error')
            ->assertJsonPath('rows.0.messages.0', 'In DealerDock this machine\'s model is S-400, but the file says R-200. An import doesn\'t change a machine\'s model.');
    }

    public function test_excels_own_csv_windows_1252_and_semicolons(): void
    {
        $dealer = Dealer::create(['name' => 'Équipement Nord']);
        Tenant::run($this->acme, fn () => DealerLink::create(['dealer_id' => $dealer->id, 'status' => DealerLink::ACTIVE]));
        $csv = mb_convert_encoding("Serial;Model;Dealer\nAS4-1;S-400;Équipement Nord\n", 'Windows-1252', 'UTF-8');

        $this->send('import', $csv)->assertCreated()->assertJsonPath('summary.create', 1);
    }

    public function test_a_utf8_csv_with_a_byte_order_mark(): void
    {
        $this->send('preview', "\xEF\xBB\xBFSerial,Model\nAS4-1,S-400\n")->assertOk()->assertJsonPath('summary.create', 1);
    }

    public function test_an_excel_file_with_date_cells_and_numeric_serials(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Serial number', 'Model', 'Built on']));
        // A date cell formatted as a date, the way Excel saves one.
        $writer->addRow(new Row([
            new NumericCell(24001, null),
            new StringCell('S-400', null),
            new DateTimeCell(new \DateTimeImmutable('2025-06-30'), (new Style)->setFormat('yyyy-mm-dd')),
        ]));
        $writer->close();

        $this->actingAs($this->owner)->post('/api/m/acme/machines/import', [
            'file' => new UploadedFile($path, 'fleet.xlsx', null, null, true),
            'mode' => MachineImporter::ADD,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('summary.create', 1);

        $machine = Machine::withoutTenant()->first();
        $this->assertSame(['24001', '2025-06-30'], [$machine->serial_no, $machine->built_at->toDateString()]);
    }

    public function test_a_file_over_the_row_limit_is_refused(): void
    {
        $csv = "Serial,Model\n".implode('', array_map(fn ($i) => "SN-$i,S-400\n", range(1, MachineImporter::MAX_ROWS + 1)));

        $this->send('preview', $csv)->assertUnprocessable()->assertJsonValidationErrors(['file' => 'more than 5,000 machines']);
    }

    public function test_an_unreadable_or_wrong_type_of_file_is_refused(): void
    {
        $this->actingAs($this->owner)->post('/api/m/acme/machines/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('fleet.pdf', '%PDF-1.4'),
            'mode' => MachineImporter::ADD,
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['file' => 'CSV or an Excel']);

        $this->actingAs($this->owner)->post('/api/m/acme/machines/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('fleet.xlsx', 'not really a spreadsheet'),
            'mode' => MachineImporter::ADD,
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['file' => 'couldn\'t read this file']);
    }

    public function test_the_audit_report_lists_every_row_and_keeps_text_as_text(): void
    {
        $id = $this->send('import', "Serial,Model\nAS4-1,S-400\n=HYPERLINK(\"https://evil.example\"),S-999\n")->json('id');

        $this->actingAs($this->owner)->getJson('/api/m/acme/machine-imports')
            ->assertJsonPath('0.id', $id)
            ->assertJsonPath('0.file_name', 'fleet.csv')
            ->assertJsonPath('0.summary.error', 1);

        $response = $this->actingAs($this->owner)->get("/api/m/acme/machine-imports/{$id}/report")->assertOk();
        $this->assertStringContainsString("acme-import-{$id}-report.xlsx", $response->headers->get('Content-Disposition'));
        $path = $response->baseResponse->getFile()->getPathname();

        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertStringNotContainsString('<f>', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();

        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertStringContainsString('fleet.csv', $rows[0][0]);
        // The reader skips the blank spacer row, so find the header by its text.
        $header = array_search(['Row', 'Serial number', 'Result', 'Details'], $rows, true);
        $this->assertNotFalse($header);
        $this->assertSame([2, 'AS4-1', 'Added'], array_slice($rows[$header + 1], 0, 3));
        $this->assertSame('=HYPERLINK("https://evil.example")', $rows[$header + 2][1]);
        $this->assertSame('Not imported', $rows[$header + 2][2]);
        $this->assertStringContainsString('S-999', $rows[$header + 2][3]);
    }

    public function test_only_owners_and_admins_import(): void
    {
        $prairieUser = $this->dealerUser(Dealer::where('name', 'Prairie Equipment')->first());

        foreach ([$this->staff($this->acme, 'sales_rep'), $this->staff($this->acme, 'inside_sales'), $prairieUser] as $user) {
            $this->actingAs($user)->get('/api/m/acme/machines/import/template')->assertForbidden();
            $this->send('preview', "Serial,Model\nA,S-400\n", as: $user)->assertForbidden();
        }

        $template = $this->actingAs($this->staff($this->acme, 'admin'))->get('/api/m/acme/machines/import/template')->assertOk();
        $this->assertStringStartsWith("\"Serial number\",Model,\"Production number\",Dealer,Status,\"Built on\",\"Shipped on\"\nEXAMPLE-0001,S-400,PO-1234,PRA001", $template->streamedContent());
    }

    public function test_dates(): void
    {
        $this->assertSame('2026-03-15', MachineImporter::parseDate('2026-03-15'));
        $this->assertSame('2026-03-05', MachineImporter::parseDate('2026/3/5'));
        $this->assertSame('2026-03-15', MachineImporter::parseDate('3/15/2026'));
        $this->assertNull(MachineImporter::parseDate('15/3/2026')); // day first: not read as a guess
        $this->assertNull(MachineImporter::parseDate('2026-02-30'));
        $this->assertNull(MachineImporter::parseDate('March 15'));
        $this->assertNull(MachineImporter::parseDate('3/15/26'));
        // Excel's day numbers, from a sheet that lost its date formatting.
        $this->assertSame('2025-06-30', MachineImporter::parseDate('45838'));
        $this->assertNull(MachineImporter::parseDate('99999'));
    }

    private function send(string $step, string $csv, string $mode = MachineImporter::ADD, ?User $as = null): TestResponse
    {
        $url = $step === 'preview' ? '/api/m/acme/machines/import/preview' : '/api/m/acme/machines/import';

        return $this->actingAs($as ?? $this->owner)->post($url, [
            'file' => UploadedFile::fake()->createWithContent('fleet.csv', $csv),
            'mode' => $mode,
        ], ['Accept' => 'application/json']);
    }
}
