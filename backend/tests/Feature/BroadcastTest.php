<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\Dealer;
use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\User;
use App\Notifications\BroadcastSent;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Broadcasts: who gets them, reading them, withdrawing them, email, and that they're Pro. */
class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $owner;

    private DealerLink $prairie;

    private DealerLink $northern;

    private User $prairieUser;

    private User $northernUser;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->acme = $this->manufacturer('acme'); // Pro
        $this->owner = $this->staff($this->acme);
        $p = $this->dealer('Prairie Equipment', $this->acme);
        $n = $this->dealer('Northern Ag', $this->acme);
        $this->prairie = $this->link($p, $this->acme);
        $this->northern = $this->link($n, $this->acme);
        $this->prairieUser = $this->dealerUser($p);
        $this->northernUser = $this->dealerUser($n);
    }

    public function test_a_broadcast_to_all_dealers_reaches_every_active_dealer(): void
    {
        $suspended = $this->link($this->dealer('Paused Dealer', $this->acme), $this->acme);
        Tenant::run($this->acme, fn () => $suspended->update(['status' => DealerLink::SUSPENDED]));

        $this->send()->assertCreated()->assertJsonCount(2, 'recipients')->assertJsonPath('emailed', false);

        $this->actingAs($this->prairieUser)->getJson('/api/m/acme/announcements')->assertJsonPath('0.title', 'New S-400 cutting edge')->assertJsonPath('0.read', false);
        $this->actingAs($this->northernUser)->getJson('/api/m/acme/announcements')->assertJsonCount(1);
        Notification::assertNothingSent();
    }

    public function test_a_broadcast_to_chosen_dealers_reaches_only_them(): void
    {
        $id = $this->send(['audience' => 'selected', 'dealer_link_ids' => [$this->prairie->id]])->assertCreated()->json('id');

        $this->actingAs($this->prairieUser)->getJson('/api/m/acme/announcements')->assertJsonCount(1);
        $this->actingAs($this->northernUser)->getJson('/api/m/acme/announcements')->assertJsonCount(0);
        $this->actingAs($this->northernUser)->getJson("/api/m/acme/announcements/{$id}")->assertNotFound();

        $this->send(['audience' => 'selected', 'dealer_link_ids' => []])->assertUnprocessable()->assertJsonValidationErrors(['dealer_link_ids' => 'Choose at least one dealer']);
    }

    public function test_dealers_who_join_later_dont_get_old_announcements(): void
    {
        $this->send();
        $late = $this->dealer('Late Joiner', $this->acme);

        $this->actingAs($this->dealerUser($late))->getJson('/api/m/acme/announcements')->assertJsonCount(0);
    }

    public function test_opening_marks_it_read_for_that_person_and_the_sender_sees_who(): void
    {
        $id = $this->send()->json('id');
        $this->actingAs($this->prairieUser)->getJson('/api/m/acme/dashboard')->assertJsonPath('announcements_unread', 1);

        $this->actingAs($this->prairieUser)->getJson("/api/m/acme/announcements/{$id}")->assertOk()->assertJsonPath('body', "Starting March 1 the S-400 ships with the 8 ft edge.\n\nOrder the old one while stock lasts.");
        $this->actingAs($this->prairieUser)->getJson("/api/m/acme/announcements/{$id}"); // twice: still one read

        $this->actingAs($this->prairieUser)->getJson('/api/m/acme/announcements')->assertJsonPath('0.read', true);
        $this->actingAs($this->prairieUser)->getJson('/api/m/acme/dashboard')->assertJsonPath('announcements_unread', 0);
        // Another person at the same dealer hasn't read it.
        $this->actingAs($this->dealerUser(Dealer::find($this->prairie->dealer_id), 'dealer_user'))->getJson('/api/m/acme/dashboard')->assertJsonPath('announcements_unread', 1);

        $this->actingAs($this->owner)->getJson('/api/m/acme/broadcasts')->assertJsonPath('0.recipients_count', 2)->assertJsonPath('0.read_by_dealers', 1);
        $recipients = collect($this->actingAs($this->owner)->getJson("/api/m/acme/broadcasts/{$id}")->assertOk()->json('recipients'))->keyBy('dealer');
        $this->assertSame($this->prairieUser->name, $recipients['Prairie Equipment']['read_by'][0]['name']);
        $this->assertSame([], $recipients['Northern Ag']['read_by']);
    }

    public function test_withdrawing_hides_it_from_dealers_but_not_from_history(): void
    {
        $id = $this->send()->json('id');

        $this->actingAs($this->owner)->postJson("/api/m/acme/broadcasts/{$id}/withdraw")->assertOk()->assertJsonPath('withdrawn_at', fn ($v) => $v !== null);

        $this->actingAs($this->prairieUser)->getJson('/api/m/acme/announcements')->assertJsonCount(0);
        $this->actingAs($this->prairieUser)->getJson("/api/m/acme/announcements/{$id}")->assertNotFound();
        $this->actingAs($this->owner)->getJson('/api/m/acme/broadcasts')->assertJsonCount(1);
    }

    public function test_email_goes_to_everyone_at_the_chosen_dealers_only(): void
    {
        $this->send(['audience' => 'selected', 'dealer_link_ids' => [$this->prairie->id], 'email' => true])->assertCreated();

        Notification::assertSentTo($this->prairieUser, BroadcastSent::class);
        Notification::assertNotSentTo($this->northernUser, BroadcastSent::class);
        Notification::assertNotSentTo($this->owner, BroadcastSent::class);
    }

    public function test_broadcasts_are_pro_and_for_the_right_staff(): void
    {
        $this->actingAs($this->staff($this->acme, 'sales_rep'))->getJson('/api/m/acme/broadcasts')->assertForbidden();
        $this->actingAs($this->prairieUser)->postJson('/api/m/acme/broadcasts', ['title' => 'x', 'body' => 'y', 'audience' => 'all'])->assertForbidden();
        $this->actingAs($this->owner)->getJson('/api/m/acme/announcements')->assertForbidden();

        $this->acme->update(['plan' => Plan::STARTER]);
        $this->send()->assertForbidden()->assertJsonPath('code', 'upgrade_required');
    }

    public function test_dealers_can_still_read_old_announcements_after_a_move_to_starter(): void
    {
        $this->send();
        $this->acme->update(['plan' => Plan::STARTER]);

        $this->actingAs($this->prairieUser)->getJson('/api/m/acme/announcements')->assertOk()->assertJsonCount(1);
    }

    public function test_no_active_dealers_says_so(): void
    {
        Tenant::run($this->acme, fn () => DealerLink::query()->update(['status' => DealerLink::SUSPENDED]));

        $this->send()->assertUnprocessable()->assertJsonValidationErrors(['audience' => 'no active dealers']);
        $this->assertSame(0, Broadcast::withoutTenant()->count());
    }

    private function send(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/api/m/acme/broadcasts', array_replace([
            'title' => 'New S-400 cutting edge',
            'body' => "Starting March 1 the S-400 ships with the 8 ft edge.\n\nOrder the old one while stock lasts.",
            'audience' => 'all',
        ], $overrides));
    }
}
