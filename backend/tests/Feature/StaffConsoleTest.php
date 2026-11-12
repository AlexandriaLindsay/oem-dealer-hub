<?php

namespace Tests\Feature;

use App\Filament\Resources\Dealers\Pages\ViewDealer;
use App\Filament\Resources\Dealers\RelationManagers\LinksRelationManager;
use App\Filament\Resources\Manufacturers\Pages\ListManufacturers;
use App\Filament\Resources\Manufacturers\Pages\ViewManufacturer;
use App\Filament\Resources\Manufacturers\RelationManagers\DealersRelationManager;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Widgets\AccountsOverview;
use App\Filament\Widgets\TrialsEndingSoon;
use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\StaffAction;
use App\Models\User;
use App\Platform\AccountTools;
use App\Platform\RefusedException;
use App\Platform\Standings;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** The staff console (/admin): who gets in, and the account changes staff can make. */
class StaffConsoleTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $acme;

    private User $staffer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->manufacturer('acme', Plan::TRIAL);
        $this->acme->forceFill(['trial_ends_at' => now()->addDays(2)])->save();
        $this->staffer = $this->platformStaff();
    }

    private function platformStaff(): User
    {
        $user = $this->userWithTwoFactor();
        $user->forceFill(['is_platform_staff' => true])->save();

        return $user;
    }

    public function test_signed_out_people_go_to_the_apps_sign_in(): void
    {
        $this->get('/admin')->assertRedirect(rtrim(config('app.frontend_url'), '/').'/login?next=console');
    }

    public function test_customers_get_a_404_even_owners(): void
    {
        $this->actingAs($this->staff($this->acme))->get('/admin')->assertNotFound();
        $this->actingAs($this->staff($this->acme))->get('/admin/manufacturers')->assertNotFound();
    }

    public function test_staff_without_two_factor_are_sent_to_set_it_up_even_when_it_isnt_required(): void
    {
        config(['dealerdock.require_two_factor' => false]);
        $user = User::factory()->create();
        $user->forceFill(['is_platform_staff' => true])->save();

        $this->actingAs($user)->get('/admin')->assertRedirect(rtrim(config('app.frontend_url'), '/').'/setup-2fa');
    }

    public function test_staff_see_every_page(): void
    {
        $dealer = $this->dealer('Prairie Equipment', $this->acme);
        $owner = $this->staff($this->acme);
        $this->actingAs($this->staffer);

        $this->get('/admin')->assertOk();
        // Acme's trial ends in two days. (The widgets load after the page.)
        Livewire::test(TrialsEndingSoon::class)->assertSee('Acme Manufacturing')->assertSee($owner->email);
        Livewire::test(AccountsOverview::class)->assertOk()->assertSee('Past due');

        AccountTools::setPlan($this->acme, Plan::PRO, $this->staffer, 'Invoiced annually');
        Livewire::test(TrialsEndingSoon::class)->assertDontSee('Acme Manufacturing');
        $this->get('/admin/manufacturers')->assertOk()->assertSee('Acme Manufacturing');
        $this->get('/admin/manufacturers/acme')->assertOk()->assertSee('Set plan')->assertDontSee('Extend trial');
        // Its dealer list (loaded after the page) reads tenant-owned dealer links: it must not need a tenant.
        Livewire::test(DealersRelationManager::class, ['ownerRecord' => $this->acme, 'pageClass' => ViewManufacturer::class])
            ->assertOk()->assertSee('Prairie Equipment');
        $this->get('/admin/users')->assertOk()->assertSee($owner->email);
        $this->get("/admin/users/{$owner->id}")->assertOk();
        $this->get('/admin/dealers')->assertOk()->assertSee('Prairie Equipment');
        $this->get("/admin/dealers/{$dealer->id}")->assertOk();
        $this->get('/admin/staff-actions')->assertOk()->assertSee('Invoiced annually');
    }

    public function test_revoking_staff_shuts_the_console_at_once(): void
    {
        $this->actingAs($this->staffer)->get('/admin')->assertOk();
        $this->artisan('platform:staff', ['email' => $this->staffer->email, '--revoke' => true])->assertSuccessful();

        $this->actingAs($this->staffer->fresh())->get('/admin')->assertNotFound();
    }

    public function test_the_command_grants_staff_and_me_links_to_the_console(): void
    {
        $user = $this->staff($this->acme);
        $this->actingAs($user)->getJson('/api/me')->assertJsonPath('user.console_url', null);

        $this->artisan('platform:staff', ['email' => $user->email])->assertSuccessful();
        $this->artisan('platform:staff', ['email' => 'nobody@example.test'])->assertFailed();

        $this->assertTrue($user->fresh()->is_platform_staff);
        $this->actingAs($user->fresh())->getJson('/api/me')->assertJsonPath('user.console_url', rtrim(config('app.url'), '/').'/admin');
    }

    public function test_staff_status_cant_be_set_through_mass_assignment(): void
    {
        $user = User::create(['name' => 'X', 'email' => 'x@example.test', 'password' => 'secret-password', 'is_platform_staff' => true]);

        $this->assertFalse((bool) $user->fresh()->is_platform_staff);
    }

    public function test_extending_a_trial_is_logged_with_the_reason(): void
    {
        $until = now()->addDays(20)->endOfDay();
        AccountTools::extendTrial($this->acme, $until, $this->staffer, 'Asked for more time to load machines');

        $this->acme->refresh();
        $this->assertSame(Plan::TRIAL, $this->acme->plan);
        $this->assertSame($until->timestamp, $this->acme->trial_ends_at->timestamp);
        $entry = StaffAction::sole();
        $this->assertSame('extend_trial', $entry->action);
        $this->assertSame($this->staffer->id, $entry->user_id);
        $this->assertSame('Acme Manufacturing', $entry->subject_label);
        $this->assertSame('Asked for more time to load machines', $entry->note);
    }

    public function test_an_ended_trial_can_be_reopened_but_limits_hold(): void
    {
        $this->acme->forceFill(['trial_ends_at' => now()->subDay()])->save();
        $this->assertSame(Plan::LAPSED, Plan::standing($this->acme));

        AccountTools::extendTrial($this->acme, now()->addDays(5), $this->staffer, 'Reopening after a call');
        $this->assertSame(Plan::TRIALING, Plan::standing($this->acme->refresh()));

        foreach ([now()->subDay(), now()->addDays(AccountTools::MAX_TRIAL_DAYS + 2)] as $bad) {
            try {
                AccountTools::extendTrial($this->acme, $bad, $this->staffer, 'Out of range');
                $this->fail('Expected a refusal');
            } catch (RefusedException) {
            }
        }
        $this->assertSame(1, StaffAction::count());
    }

    public function test_a_paid_plan_cant_be_given_trial_time(): void
    {
        $bolt = $this->manufacturer('bolt', Plan::STARTER);

        $this->expectException(RefusedException::class);
        AccountTools::extendTrial($bolt, now()->addDays(5), $this->staffer, 'Should not work');
    }

    public function test_accounts_paying_through_stripe_cant_be_changed_here(): void
    {
        $this->acme->forceFill(['plan' => Plan::PRO])->save();
        $this->acme->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => 'active', 'stripe_price' => 'price_pro_m']);

        $this->assertNotNull(AccountTools::billedByStripe($this->acme));
        $this->expectException(RefusedException::class);
        AccountTools::setPlan($this->acme, Plan::STARTER, $this->staffer, 'Should not work');
    }

    public function test_moving_to_starter_needs_the_account_under_its_limits(): void
    {
        foreach (range(1, 11) as $i) {
            $this->dealer("Dealer $i", $this->acme);
        }

        try {
            AccountTools::setPlan($this->acme, Plan::STARTER, $this->staffer, 'Downgrade');
            $this->fail('Expected a refusal');
        } catch (RefusedException $e) {
            $this->assertStringContainsString('11 dealers', $e->getMessage());
        }

        $this->assertSame(Plan::TRIAL, $this->acme->fresh()->plan);
        $this->assertSame(0, StaffAction::count());
    }

    public function test_pausing_and_invoicing_an_account(): void
    {
        AccountTools::setPlan($this->acme, Plan::EXPIRED, $this->staffer, 'Unpaid invoice');
        $this->assertSame(Plan::LAPSED, Plan::standing($this->acme->refresh()));

        AccountTools::setPlan($this->acme, Plan::PRO, $this->staffer, 'Invoice paid');
        $this->assertSame(Plan::ACTIVE, Plan::standing($this->acme->refresh()));
        $this->assertSame(['expired', 'pro'], StaffAction::latest('id')->first()->details['plan']);
    }

    public function test_resetting_two_factor_signs_them_out_everywhere(): void
    {
        $owner = $this->staff($this->acme);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);
        config(['session.driver' => 'database']);
        $remember = $owner->remember_token;

        AccountTools::resetTwoFactor($owner, $this->staffer, 'Lost their phone, confirmed by email');

        $owner->refresh();
        $this->assertFalse($owner->hasTwoFactor());
        $this->assertNull($owner->two_factor_secret);
        $this->assertNotSame($remember, $owner->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $owner->id)->count());
        $this->assertSame('reset_two_factor', StaffAction::sole()->action);

        // The app now holds them at two-factor setup.
        $this->actingAs($owner)->getJson('/api/m/acme/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_setup_required');
    }

    public function test_staff_cant_reset_their_own_two_factor(): void
    {
        $this->expectException(RefusedException::class);
        AccountTools::resetTwoFactor($this->staffer, $this->staffer, 'Myself');
    }

    public function test_sending_a_password_reset(): void
    {
        Notification::fake();
        $owner = $this->staff($this->acme);

        AccountTools::sendPasswordReset($owner, $this->staffer, 'Asked on the phone');

        Notification::assertSentTo($owner, ResetPassword::class);
        $this->assertSame('send_password_reset', StaffAction::sole()->action);
    }

    public function test_the_console_buttons_call_the_tools(): void
    {
        $this->actingAs($this->staffer);
        $until = now('America/New_York')->addDays(10)->toDateString();

        Livewire::test(ViewManufacturer::class, ['record' => $this->acme->getRouteKey()])
            ->callAction('extendTrial', data: ['until' => $until, 'note' => 'More time to import'])
            ->assertHasNoFormErrors()
            ->assertNotified('Trial extended');

        // The end of that day in Eastern time, stored as UTC.
        $this->assertSame("$until 23:59:59", $this->acme->fresh()->trial_ends_at->timezone('America/New_York')->format('Y-m-d H:i:s'));

        // A refusal shows the reason and changes nothing.
        foreach (range(1, 11) as $i) {
            $this->dealer("Dealer $i", $this->acme);
        }
        Livewire::test(ViewManufacturer::class, ['record' => $this->acme->getRouteKey()])
            ->callAction('setPlan', data: ['plan' => Plan::STARTER, 'note' => 'Downgrade please'])
            ->assertNotified('Nothing was changed');
        $this->assertSame(Plan::TRIAL, $this->acme->fresh()->plan);

        // No reason, no change.
        Livewire::test(ViewManufacturer::class, ['record' => $this->acme->getRouteKey()])
            ->callAction('setPlan', data: ['plan' => Plan::PRO, 'note' => ''])
            ->assertHasFormErrors(['note' => 'required']);

        $owner = $this->staff($this->acme);
        Livewire::test(ViewUser::class, ['record' => $owner->id])
            ->callAction('resetTwoFactor', data: ['note' => 'Lost phone'])
            ->assertNotified();
        $this->assertFalse($owner->fresh()->hasTwoFactor());

        // Not offered for yourself.
        Livewire::test(ViewUser::class, ['record' => $this->staffer->id])->assertActionHidden('resetTwoFactor');
    }

    public function test_dealer_lists_show_suspended_links_and_every_manufacturer(): void
    {
        $bolt = $this->manufacturer('bolt', Plan::STARTER);
        $dealer = $this->dealer('Prairie Equipment', $this->acme, $bolt);
        Tenant::run($bolt, fn () => DealerLink::where('dealer_id', $dealer->id)->update(['status' => DealerLink::SUSPENDED]));
        $this->actingAs($this->staffer);

        Livewire::test(DealersRelationManager::class, ['ownerRecord' => $bolt, 'pageClass' => ViewManufacturer::class])
            ->assertOk()
            ->assertCanSeeTableRecords(DealerLink::withoutTenant()->where('manufacturer_id', $bolt->id)->get());

        Livewire::test(ViewDealer::class, ['record' => $dealer->id])->assertOk();
        Livewire::test(LinksRelationManager::class, ['ownerRecord' => $dealer, 'pageClass' => ViewDealer::class])
            ->assertSee('Acme Manufacturing')->assertSee('Bolt Manufacturing')->assertSee('Suspended');

        // The console never leaves a tenant set behind.
        $this->assertFalse(Tenant::current()->check());
    }

    public function test_standing_filter_agrees_with_plan_standing(): void
    {
        $make = function (string $slug, string $plan, $trialEnds = null, ?string $subscription = null) {
            $m = $this->manufacturer($slug, $plan);
            $m->forceFill(['trial_ends_at' => $trialEnds])->save();
            if ($subscription) {
                $m->subscriptions()->create(['type' => 'default', 'stripe_id' => "sub_$slug", 'stripe_status' => $subscription, 'stripe_price' => 'price_x']);
            }

            return $m;
        };
        $make('trial-ok', Plan::TRIAL, now()->addDay());
        $make('trial-over', Plan::TRIAL, now()->subDay());
        $make('trial-blank', Plan::TRIAL);
        $make('ended', Plan::EXPIRED);
        $make('invoiced', Plan::STARTER);
        $make('stripe-ok', Plan::PRO, null, 'active');
        $make('stripe-late', Plan::PRO, null, 'past_due');

        foreach (Manufacturer::all() as $m) {
            $buckets = collect(array_keys(Standings::LABELS))
                ->filter(fn ($s) => Standings::where(Manufacturer::query(), $s)->whereKey($m->id)->exists())
                ->values()->all();
            $this->assertSame([Plan::standing($m)], $buckets, "Standing for {$m->slug}");
        }

        $this->actingAs($this->staffer);
        Livewire::test(ListManufacturers::class)
            ->filterTable('standing', Plan::PAST_DUE)
            ->assertCanSeeTableRecords(Manufacturer::where('slug', 'stripe-late')->get())
            ->assertCanNotSeeTableRecords(Manufacturer::where('slug', 'stripe-ok')->get());
    }
}
