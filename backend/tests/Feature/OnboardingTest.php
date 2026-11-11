<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Manufacturer;
use App\Models\User;
use App\Support\Scanning\Scanner;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeScanner;
use Tests\TestCase;

/** The "Getting started" checklist, the logo, and remembering guided tours. */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        $this->app->instance(Scanner::class, new FakeScanner);
        $this->acme = $this->manufacturer('acme');
        $this->owner = $this->staff($this->acme);
    }

    public function test_the_checklist_follows_the_real_data(): void
    {
        $done = fn () => collect($this->actingAs($this->owner)->getJson('/api/m/acme/onboarding')->assertOk()->json('steps'))->pluck('done', 'key')->all();

        $this->assertSame(['logo' => false, 'products' => false, 'machines' => false, 'dealers' => false, 'documents' => false, 'staff' => false], $done());

        $link = $this->link($this->dealer('Prairie Equipment', $this->acme), $this->acme);
        $this->machine($this->acme, 'SN-1', $link); // also makes a product line and model
        $this->staff($this->acme, 'sales_rep');
        // A staff-only document still counts as "added a manual".
        Tenant::run($this->acme, fn () => Document::create([
            'title' => 'Internal', 'kind' => 'manual', 'audience' => Document::STAFF, 'original_name' => 'x.pdf', 'path' => 'x',
            'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('0', 64), 'scan_status' => 'clean',
        ]));

        $this->assertSame(['logo' => false, 'products' => true, 'machines' => true, 'dealers' => true, 'documents' => true, 'staff' => true], $done());

        $this->uploadLogo()->assertCreated();
        $this->actingAs($this->owner)->getJson('/api/m/acme/onboarding')->assertJsonPath('complete', true);
    }

    public function test_the_checklist_can_be_hidden_and_shown_again(): void
    {
        $this->actingAs($this->owner)->postJson('/api/m/acme/onboarding', ['dismissed' => true])->assertJsonPath('dismissed', true);
        $this->actingAs($this->owner)->getJson('/api/m/acme/onboarding')->assertJsonPath('dismissed', true);
        $this->actingAs($this->owner)->postJson('/api/m/acme/onboarding', ['dismissed' => false])->assertJsonPath('dismissed', false);
    }

    public function test_only_owners_and_admins_see_the_checklist_and_change_the_logo(): void
    {
        $prairie = $this->dealer('Prairie Equipment', $this->acme);
        foreach ([$this->staff($this->acme, 'sales_rep'), $this->dealerUser($prairie)] as $user) {
            $this->actingAs($user)->getJson('/api/m/acme/onboarding')->assertForbidden();
            $this->uploadLogo(as: $user)->assertForbidden();
            $this->actingAs($user)->deleteJson('/api/m/acme/logo')->assertForbidden();
        }
        $this->actingAs($this->staff($this->acme, 'admin'))->getJson('/api/m/acme/onboarding')->assertOk();
    }

    public function test_a_logo_is_stored_privately_and_shown_to_everyone_at_the_brand(): void
    {
        $url = $this->uploadLogo()->assertCreated()->json('logo_url');
        $this->assertMatchesRegularExpression('#^/api/m/acme/logo\?v=\d+$#', $url);

        $path = Manufacturer::find($this->acme->id)->logo_path;
        $this->assertStringStartsWith($this->acme->id.'/branding/', $path);
        Storage::disk('documents')->assertExists($path);

        // In /me for staff and dealers, and the image itself for both.
        $prairie = $this->dealer('Prairie Equipment', $this->acme);
        foreach ([$this->owner, $this->dealerUser($prairie)] as $user) {
            $this->actingAs($user)->getJson('/api/me')->assertJsonPath('manufacturers.0.logo_url', $url);
            $image = $this->actingAs($user)->get('/api/m/acme/logo')->assertOk();
            $this->assertSame('image/png', $image->headers->get('Content-Type'));
            $this->assertSame('sandbox', $image->headers->get('Content-Security-Policy'));
        }
    }

    public function test_a_new_logo_replaces_the_old_file_and_removing_it_deletes_it(): void
    {
        $this->uploadLogo();
        $first = Manufacturer::find($this->acme->id)->logo_path;
        $this->travel(2)->seconds();
        $url = $this->uploadLogo(UploadedFile::fake()->image('new.jpg', 300, 100))->json('logo_url');

        Storage::disk('documents')->assertMissing($first);
        $this->assertStringContainsString('v=', $url);

        $second = Manufacturer::find($this->acme->id)->logo_path;
        $this->actingAs($this->owner)->deleteJson('/api/m/acme/logo')->assertOk()->assertJsonPath('logo_url', null);
        Storage::disk('documents')->assertMissing($second);
        $this->actingAs($this->owner)->get('/api/m/acme/logo')->assertNotFound();
    }

    public function test_only_reasonable_png_and_jpg_logos_are_accepted(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->uploadLogo($svg)->assertUnprocessable()->assertJsonValidationErrors(['logo' => 'SVG isn\'t accepted']);

        $this->uploadLogo(UploadedFile::fake()->createWithContent('logo.png', '%PDF-1.4 not an image'))->assertUnprocessable();
        $this->uploadLogo(UploadedFile::fake()->image('tiny.png', 10, 10))->assertUnprocessable()->assertJsonValidationErrors(['logo' => 'between 32 and 4,000']);
        $this->assertNull(Manufacturer::find($this->acme->id)->logo_path);
    }

    public function test_tours_are_remembered_per_person(): void
    {
        $this->actingAs($this->owner)->getJson('/api/me')->assertJsonPath('user.tours_seen', []);

        $this->actingAs($this->owner)->postJson('/api/tours/manufacturer-v1/seen')->assertOk()->assertJsonPath('tours_seen', ['manufacturer-v1']);
        $this->actingAs($this->owner)->postJson('/api/tours/manufacturer-v1/seen')->assertOk(); // twice is fine
        $this->actingAs($this->owner)->getJson('/api/me')->assertJsonPath('user.tours_seen', ['manufacturer-v1']);

        // Someone else hasn't seen it.
        $this->actingAs($this->staff($this->acme, 'admin'))->getJson('/api/me')->assertJsonPath('user.tours_seen', []);
        // Only tours the app knows.
        $this->actingAs($this->owner)->postJson('/api/tours/'.str_repeat('x', 20).'/seen')->assertNotFound();
    }

    private function uploadLogo(?UploadedFile $file = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post('/api/m/acme/logo', [
            'logo' => $file ?? UploadedFile::fake()->image('logo.png', 400, 120),
        ], ['Accept' => 'application/json']);
    }
}
