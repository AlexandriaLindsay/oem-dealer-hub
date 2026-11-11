<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Manufacturer;
use App\Models\ProductModel;
use App\Models\User;
use App\Support\Scanning\Scanner;
use App\Support\Uploads;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeScanner;
use Tests\TestCase;

/** The document library: adding, covering models and serial ranges, finding by serial, opening. */
class DocumentLibraryTest extends TestCase
{
    use RefreshDatabase;

    private FakeScanner $scanner;

    private Manufacturer $acme;

    private User $owner;

    private ProductModel $s400;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->scanner = new FakeScanner;
        $this->app->instance(Scanner::class, $this->scanner);

        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme);
        $this->s400 = $this->productModel($this->acme, 'S-400');
    }

    public function test_staff_add_a_manual_for_a_model_and_serial_range(): void
    {
        $this->upload(['title' => 'S-400 operator manual', 'models' => [['id' => $this->s400->id, 'serial_from' => 'AS4-24001']]])
            ->assertCreated()
            ->assertJsonPath('kind', 'manual')
            ->assertJsonPath('audience', 'everyone')
            ->assertJsonPath('scan_status', 'clean')
            ->assertJsonPath('covers.0.model', 'S-400')
            ->assertJsonPath('covers.0.range', 'S/N AS4-24001 and up');

        $path = Document::withoutTenant()->first()->path;
        $this->assertStringStartsWith($this->acme->id.'/library/', $path);
        Storage::disk('documents')->assertExists($path);
        $this->assertSame(1, $this->scanner->scanned);
    }

    public function test_a_serial_finds_the_documents_that_cover_it(): void
    {
        $r200 = $this->productModel($this->acme, 'R-200');
        $this->upload(['title' => 'Old S-400 manual', 'models' => [['id' => $this->s400->id, 'serial_to' => 'AS4-24000']]]);
        $this->upload(['title' => 'New S-400 manual', 'models' => [['id' => $this->s400->id, 'serial_from' => 'AS4-24001']]]);
        $this->upload(['title' => 'R-200 manual', 'models' => [['id' => $r200->id]]]);
        $this->upload(['title' => 'Warranty policy', 'kind' => 'other']); // covers everything
        $this->machine($this->acme, 'AS4-24005', model: $this->s400);

        $titles = $this->actingAs($this->owner)->getJson('/api/m/acme/documents/for-serial?serial=AS4-24005')
            ->assertOk()
            ->assertJsonPath('machine.model', 'S-400')
            ->json('documents.*.title');

        $this->assertEqualsCanonicalizing(['New S-400 manual', 'Warranty policy'], $titles);

        $this->actingAs($this->owner)->getJson('/api/m/acme/documents/for-serial?serial=NOPE-1')
            ->assertNotFound()
            ->assertJsonPath('message', 'No Acme Manufacturing machine has serial number NOPE-1. Check it against the machine\'s serial plate.');
    }

    public function test_a_machines_page_lists_its_documents(): void
    {
        $this->upload(['title' => 'S-400 parts book', 'kind' => 'parts_book', 'models' => [['id' => $this->s400->id]]]);
        $machine = $this->machine($this->acme, 'AS4-1');

        $this->actingAs($this->owner)->getJson("/api/m/acme/machines/{$machine->id}/documents")
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'S-400 parts book');
    }

    public function test_search_and_filters(): void
    {
        $r200 = $this->productModel($this->acme, 'R-200');
        $this->upload(['title' => 'S-400 operator manual', 'models' => [['id' => $this->s400->id]]]);
        $this->upload(['title' => 'Hitch pin bulletin', 'kind' => 'bulletin', 'description' => 'Replace S-400 hitch pins', 'models' => [['id' => $r200->id]]]);

        $this->actingAs($this->owner)->getJson('/api/m/acme/documents?q=hitch')->assertJsonPath('total', 1);
        $this->actingAs($this->owner)->getJson('/api/m/acme/documents?q=S-400')->assertJsonPath('total', 2); // title or description
        $this->actingAs($this->owner)->getJson('/api/m/acme/documents?kind=bulletin')->assertJsonPath('data.0.title', 'Hitch pin bulletin');
        $this->actingAs($this->owner)->getJson("/api/m/acme/documents?model={$this->s400->id}")->assertJsonPath('total', 1);
    }

    public function test_a_backwards_serial_range_is_refused(): void
    {
        $this->upload(['models' => [['id' => $this->s400->id, 'serial_from' => 'SN-100', 'serial_to' => 'SN-99']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['models.0.serial_to' => 'comes before the first']);
    }

    public function test_an_infected_manual_is_blocked(): void
    {
        $this->upload(['file' => UploadedFile::fake()->createWithContent('manual.pdf', '%PDF-1.4 EICAR')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, Document::withoutTenant()->count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_replacing_the_file_removes_the_old_one_and_links_are_replaced(): void
    {
        $r200 = $this->productModel($this->acme, 'R-200');
        $id = $this->upload(['models' => [['id' => $this->s400->id]]])->json('id');
        $oldPath = Document::withoutTenant()->find($id)->path;

        $this->actingAs($this->owner)->post("/api/m/acme/documents/{$id}", [
            'title' => 'S-400 manual, 2027 edition', 'kind' => 'manual', 'audience' => 'everyone',
            'file' => $this->pdf('manual-2027.pdf'),
            'models' => [['id' => $r200->id]],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('title', 'S-400 manual, 2027 edition')
            ->assertJsonPath('name', 'manual-2027.pdf')
            ->assertJsonCount(1, 'covers')
            ->assertJsonPath('covers.0.model', 'R-200');

        Storage::disk('documents')->assertMissing($oldPath);
        Storage::disk('documents')->assertExists(Document::withoutTenant()->find($id)->path);
    }

    public function test_changing_details_without_a_file_keeps_the_file(): void
    {
        $id = $this->upload()->json('id');
        $path = Document::withoutTenant()->find($id)->path;

        $this->actingAs($this->owner)->postJson("/api/m/acme/documents/{$id}", ['title' => 'Renamed', 'kind' => 'manual', 'audience' => 'staff'])
            ->assertOk()->assertJsonPath('audience', 'staff');

        $this->assertSame($path, Document::withoutTenant()->find($id)->path);
        Storage::disk('documents')->assertExists($path);
    }

    public function test_deleting_removes_the_file(): void
    {
        $id = $this->upload()->json('id');
        $path = Document::withoutTenant()->find($id)->path;

        $this->actingAs($this->owner)->deleteJson("/api/m/acme/documents/{$id}")->assertNoContent();

        Storage::disk('documents')->assertMissing($path);
        $this->assertSame(0, Document::withoutTenant()->count());
    }

    public function test_a_pdf_opens_in_the_browser_sandboxed_or_downloads(): void
    {
        $id = $this->upload()->json('id');

        $inline = $this->actingAs($this->owner)->get("/api/m/acme/documents/{$id}/file?inline=1")->assertOk();
        $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));
        $this->assertSame('sandbox', $inline->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $inline->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('%PDF', $inline->streamedContent());

        $download = $this->actingAs($this->owner)->get("/api/m/acme/documents/{$id}/file")->assertOk();
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
    }

    public function test_who_can_manage_the_library(): void
    {
        $id = $this->upload()->json('id');
        $prairie = $this->dealer('Prairie Equipment', $this->acme);

        foreach ([$this->staff($this->acme, 'sales_rep'), $this->dealerUser($prairie)] as $user) {
            $this->actingAs($user)->getJson('/api/m/acme/documents')->assertOk();
            $this->actingAs($user)->post('/api/m/acme/documents', $this->fields(), ['Accept' => 'application/json'])->assertForbidden();
            $this->actingAs($user)->deleteJson("/api/m/acme/documents/{$id}")->assertForbidden();
        }

        $this->actingAs($this->staff($this->acme, 'parts'))->post('/api/m/acme/documents', $this->fields(), ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_library_files_count_against_the_plans_storage_staff_only_ones_too(): void
    {
        $size = $this->upload(['audience' => 'staff'])->json('size');

        $this->assertSame($size, Tenant::run($this->acme, fn () => Uploads::usedBytes()));
    }

    // ---- Helpers ----

    private function upload(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->owner)->post('/api/m/acme/documents', $this->fields($overrides), ['Accept' => 'application/json']);
    }

    private function fields(array $overrides = []): array
    {
        return array_replace([
            'title' => 'S-400 operator manual',
            'kind' => 'manual',
            'audience' => 'everyone',
            'file' => $this->pdf(),
        ], $overrides);
    }

    private function pdf(string $name = 'manual.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF");
    }
}
