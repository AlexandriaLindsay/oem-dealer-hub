<?php

namespace Tests\Feature;

use App\Models\DealerLink;
use App\Models\EndUser;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\Registration;
use App\Models\RegistrationDocument;
use App\Models\User;
use App\Notifications\RegistrationReviewed;
use App\Notifications\RegistrationSubmitted;
use App\Support\Plan;
use App\Support\Scanning\Scanner;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeScanner;
use Tests\TestCase;

/** Sale and rental registrations: submitting, uploads and scanning, review, and what approval changes. */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private FakeScanner $scanner;

    private Manufacturer $acme;

    private DealerLink $link;

    private User $dealerUser;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Notification::fake();
        $this->scanner = new FakeScanner;
        $this->app->instance(Scanner::class, $this->scanner);

        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme);
        $prairie = $this->dealer('Prairie Equipment', $this->acme);
        $this->link = $this->link($prairie, $this->acme);
        $this->dealerUser = $this->dealerUser($prairie);
    }

    public function test_a_dealer_registers_a_sale_and_the_reviewers_hear_about_it(): void
    {
        $machine = $this->machine($this->acme, 'SN-1', $this->link);
        $rep = $this->staff($this->acme, 'sales_rep');

        $response = $this->register($this->dealerUser, $machine)
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('dealer', 'Prairie Equipment')
            ->assertJsonPath('customer', 'Hill Farms (Dana Hill)')
            ->assertJsonPath('documents.0.kind', 'bill_of_sale')
            ->assertJsonPath('documents.0.name', 'bill.pdf')
            ->assertJsonPath('documents.0.scan_status', 'clean');

        // Stored under the manufacturer's folder, with a generated name.
        $path = RegistrationDocument::withoutTenant()->first()->path;
        $this->assertStringStartsWith($this->acme->id.'/registrations/'.$response->json('id').'/', $path);
        $this->assertStringNotContainsString('bill', $path);
        Storage::disk('documents')->assertExists($path);

        // The machine doesn't change until it's approved.
        $this->assertSame('inventory', Machine::withoutTenant()->find($machine->id)->status);

        Notification::assertSentTo($this->owner, RegistrationSubmitted::class);
        Notification::assertNotSentTo($rep, RegistrationSubmitted::class); // sales reps don't review
        Notification::assertNotSentTo($this->dealerUser, RegistrationSubmitted::class);
    }

    public function test_a_sale_needs_its_bill_of_sale(): void
    {
        $machine = $this->machine($this->acme, 'SN-1', $this->link);

        $this->register($this->dealerUser, $machine, ['documents' => [['kind' => 'pdi', 'file' => $this->pdf()]]])
            ->assertUnprocessable()->assertJsonValidationErrors('documents');

        // A rental doesn't.
        $this->register($this->dealerUser, $machine, ['type' => 'rental', 'documents' => []])->assertCreated();
    }

    public function test_only_pdfs_and_images_are_accepted(): void
    {
        $machine = $this->machine($this->acme, 'SN-1', $this->link);

        foreach ([['page.html', 'text/html'], ['logo.svg', 'image/svg+xml'], ['macro.docm', 'application/vnd.ms-word.document.macroEnabled.12']] as [$name, $mime]) {
            $this->register($this->dealerUser, $machine, ['documents' => [['kind' => 'bill_of_sale', 'file' => UploadedFile::fake()->create($name, 10, $mime)]]])
                ->assertUnprocessable()->assertJsonValidationErrors('documents.0.file');
        }

        $this->assertSame(0, Registration::withoutTenant()->count());
    }

    public function test_an_infected_file_is_blocked_and_nothing_is_saved(): void
    {
        $machine = $this->machine($this->acme, 'SN-1', $this->link);
        $infected = UploadedFile::fake()->createWithContent('bill.pdf', '%PDF-1.4 X5O!P%@AP EICAR-STANDARD-ANTIVIRUS-TEST-FILE');

        $this->register($this->dealerUser, $machine, ['documents' => [
            ['kind' => 'pdi', 'file' => $this->pdf('pdi.pdf')],
            ['kind' => 'bill_of_sale', 'file' => $infected],
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents.1.file');

        $this->assertSame(0, Registration::withoutTenant()->count());
        $this->assertSame([], Storage::disk('documents')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_when_the_scanner_is_down_the_upload_is_refused_not_stored_unscanned(): void
    {
        $this->scanner->down();
        $machine = $this->machine($this->acme, 'SN-1', $this->link);

        $this->register($this->dealerUser, $machine)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents.0.file' => 'couldn\'t check this file']);

        $this->assertSame([], Storage::disk('documents')->allFiles());
    }

    public function test_uploads_count_against_the_plans_storage(): void
    {
        $this->acme->update(['plan' => Plan::STARTER]);
        $machine = $this->machine($this->acme, 'SN-1', $this->link);
        $old = $this->machine($this->acme, 'SN-0', $this->link);
        // Already almost at Starter's 10 GB.
        Tenant::run($this->acme, function () use ($old) {
            $r = Registration::create(['machine_id' => $old->id, 'dealer_link_id' => $this->link->id, 'type' => 'rental', 'delivered_on' => now(), 'customer' => ['contact_name' => 'X']]);
            $r->documents()->create(['kind' => 'other', 'original_name' => 'big.pdf', 'path' => 'x', 'mime' => 'application/pdf', 'size' => 10 * 1024 ** 3 - 10, 'sha256' => str_repeat('0', 64), 'scan_status' => 'clean']);
        });

        $this->register($this->dealerUser, $machine)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents.0.file' => 'Pro includes 100 GB']);
    }

    public function test_the_customer_needs_a_name_and_a_way_to_reach_them(): void
    {
        $machine = $this->machine($this->acme, 'SN-1', $this->link);

        $this->register($this->dealerUser, $machine, ['customer' => ['company' => 'Hill Farms']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer.contact_name' => 'The contact name field is required.', 'customer.email', 'customer.phone']);

        $this->register($this->dealerUser, $machine, ['customer' => ['contact_name' => 'Dana', 'phone' => '555-0100']])->assertCreated();
    }

    public function test_a_machine_can_only_be_registered_when_it_makes_sense(): void
    {
        $atFactory = $this->machine($this->acme, 'SN-F');
        $sold = $this->machine($this->acme, 'SN-S', $this->link, 'sold');
        $waiting = $this->machine($this->acme, 'SN-W', $this->link);
        $this->register($this->dealerUser, $waiting)->assertCreated();

        $this->register($this->owner, $atFactory)->assertUnprocessable()->assertJsonValidationErrors(['machine_id' => 'still at the factory']);
        $this->register($this->dealerUser, $sold)->assertUnprocessable()->assertJsonValidationErrors(['machine_id' => 'already registered as sold']);
        $this->register($this->dealerUser, $waiting)->assertUnprocessable()->assertJsonValidationErrors(['machine_id' => 'waiting for review']);
        $this->register($this->dealerUser, $waiting, ['delivered_on' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('delivered_on');
    }

    public function test_approving_a_sale_marks_the_machine_sold_to_a_new_customer(): void
    {
        $machine = $this->machine($this->acme, 'SN-1', $this->link, 'demo');
        $id = $this->register($this->dealerUser, $machine)->json('id');

        $this->actingAs($this->owner)->postJson("/api/m/acme/registrations/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('reviewed_by', $this->owner->name);

        $machine = Machine::withoutTenant()->find($machine->id);
        $this->assertSame('sold', $machine->status);
        $endUser = EndUser::withoutTenant()->find($machine->end_user_id);
        $this->assertSame(['Hill Farms', 'dana@hillfarms.example', $this->acme->id], [$endUser->company, $endUser->email, $endUser->manufacturer_id]);

        $this->actingAs($this->owner)->getJson("/api/m/acme/machines/{$machine->id}")
            ->assertJsonPath('history.0.summary', 'Registered as sold');

        Notification::assertSentTo($this->dealerUser, RegistrationReviewed::class);
    }

    public function test_approving_a_rental_puts_the_machine_on_rental(): void
    {
        $machine = $this->machine($this->acme, 'SN-1', $this->link);
        $id = $this->register($this->dealerUser, $machine, ['type' => 'rental'])->json('id');

        $this->actingAs($this->owner)->postJson("/api/m/acme/registrations/{$id}/approve")->assertOk();

        $this->assertSame('rental', Machine::withoutTenant()->find($machine->id)->status);
    }

    public function test_the_same_customer_email_is_the_same_end_user(): void
    {
        $first = $this->machine($this->acme, 'SN-1', $this->link);
        $second = $this->machine($this->acme, 'SN-2', $this->link);
        foreach ([$first, $second] as $machine) {
            $id = $this->register($this->dealerUser, $machine, ['customer' => ['contact_name' => 'Dana Hill', 'email' => 'DANA@hillfarms.example']])->json('id');
            $this->actingAs($this->owner)->postJson("/api/m/acme/registrations/{$id}/approve")->assertOk();
        }

        $this->assertSame(1, EndUser::withoutTenant()->count());
    }

    public function test_rejecting_needs_a_reason_and_the_dealer_can_try_again(): void
    {
        $machine = $this->machine($this->acme, 'SN-1', $this->link);
        $id = $this->register($this->dealerUser, $machine)->json('id');

        $this->actingAs($this->owner)->postJson("/api/m/acme/registrations/{$id}/reject")
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->owner)->postJson("/api/m/acme/registrations/{$id}/reject", ['reason' => 'The bill of sale is unsigned.'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('rejection_reason', 'The bill of sale is unsigned.');

        $this->assertSame('inventory', Machine::withoutTenant()->find($machine->id)->status);
        $this->assertSame(0, EndUser::withoutTenant()->count());
        Notification::assertSentTo($this->dealerUser, RegistrationReviewed::class);

        $this->register($this->dealerUser, $machine)->assertCreated();
    }

    public function test_a_decision_is_final(): void
    {
        $machine = $this->machine($this->acme, 'SN-1', $this->link);
        $id = $this->register($this->dealerUser, $machine)->json('id');
        $this->actingAs($this->owner)->postJson("/api/m/acme/registrations/{$id}/approve")->assertOk();

        $this->actingAs($this->owner)->postJson("/api/m/acme/registrations/{$id}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors(['registration' => 'already been approved']);
        $this->actingAs($this->owner)->postJson("/api/m/acme/registrations/{$id}/reject", ['reason' => 'Oops'])
            ->assertUnprocessable();
    }

    public function test_a_machine_that_moved_to_another_dealer_cant_be_approved_for_the_old_one(): void
    {
        $machine = $this->machine($this->acme, 'SN-1', $this->link);
        $id = $this->register($this->dealerUser, $machine)->json('id');
        $other = $this->link($this->dealer('Northern Ag', $this->acme), $this->acme);
        Tenant::run($this->acme, fn () => $machine->transferTo($other, null));

        $this->actingAs($this->owner)->postJson("/api/m/acme/registrations/{$id}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors(['registration' => 'moved to another dealer']);
        $this->assertSame('inventory', Machine::withoutTenant()->find($machine->id)->status);
    }

    public function test_who_can_submit_view_and_review(): void
    {
        $machine = $this->machine($this->acme, 'SN-1', $this->link);
        $id = $this->register($this->dealerUser, $machine)->json('id');
        $rep = $this->staff($this->acme, 'sales_rep');

        $this->actingAs($this->dealerUser)->postJson("/api/m/acme/registrations/{$id}/approve")->assertForbidden();
        $this->actingAs($rep)->getJson("/api/m/acme/registrations/{$id}")->assertOk();
        $this->actingAs($rep)->postJson("/api/m/acme/registrations/{$id}/approve")->assertForbidden();
        $this->actingAs($this->staff($this->acme, 'warranty'))->postJson("/api/m/acme/registrations/{$id}/approve")->assertOk();
    }

    public function test_a_document_downloads_with_its_name_and_never_inline(): void
    {
        $machine = $this->machine($this->acme, 'SN-1', $this->link);
        $registration = $this->register($this->dealerUser, $machine)->json();
        $doc = $registration['documents'][0]['id'];

        $response = $this->actingAs($this->owner)->get("/api/m/acme/registrations/{$registration['id']}/documents/{$doc}")->assertOk();

        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('bill.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    public function test_the_queue_lists_the_oldest_waiting_first(): void
    {
        $this->register($this->dealerUser, $this->machine($this->acme, 'SN-1', $this->link));
        $this->register($this->dealerUser, $this->machine($this->acme, 'SN-2', $this->link));

        $this->actingAs($this->owner)->getJson('/api/m/acme/registrations?status=pending')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.machine.serial_no', 'SN-1');
        $this->actingAs($this->owner)->getJson('/api/m/acme/dashboard')->assertJsonPath('registrations_pending', 2);
    }

    // ---- Helpers ----

    private function register(User $user, Machine $machine, array $overrides = []): TestResponse
    {
        $data = array_replace([
            'machine_id' => $machine->id,
            'type' => 'sale',
            'delivered_on' => now()->subDay()->toDateString(),
            'customer' => ['company' => 'Hill Farms', 'contact_name' => 'Dana Hill', 'email' => 'dana@hillfarms.example', 'city' => 'Brandon'],
            'documents' => [['kind' => 'bill_of_sale', 'file' => $this->pdf()]],
        ], $overrides);

        return $this->actingAs($user)->post('/api/m/acme/registrations', $data, ['Accept' => 'application/json']);
    }

    private function pdf(string $name = 'bill.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF");
    }
}
