<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The public site's facts, agreeing to the terms, and the Turnstile bot check. */
class LaunchTest extends TestCase
{
    use RefreshDatabase;

    /** The SPA's requests come from a stateful domain, so sign-up and sign-in get a session. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost:5173');
    }

    // ---- Public site ----

    public function test_the_public_site_gets_plans_and_prices_from_the_plan_class(): void
    {
        $this->getJson('/api/site')
            ->assertOk()
            ->assertJsonPath('trial_days', 14)
            ->assertJsonPath('plans.0.key', 'starter')
            ->assertJsonPath('plans.0.monthly_usd', 49)
            ->assertJsonPath('plans.0.limits.dealers', 10)
            ->assertJsonPath('plans.0.extras', [])
            ->assertJsonPath('plans.1.annual_usd', 990)
            // Pro extras that exist are listed plainly; ones not built yet say so,
            // rather than being sold as if they were.
            ->assertJsonPath('plans.1.extras.0', ['label' => 'Broadcasts to your dealers', 'coming' => false])
            ->assertJsonPath('plans.1.extras.1', ['label' => 'Usage analytics', 'coming' => false])
            ->assertJsonPath('plans.1.extras.2', ['label' => 'Territories', 'coming' => false])
            ->assertJsonPath('plans.1.extras.3', ['label' => 'Your own colours', 'coming' => false])
            ->assertJsonPath('plans.1.extras.4', ['label' => 'Your own subdomain', 'coming' => true])
            ->assertJsonPath('legal.updated_on', '2026-11-11')
            ->assertJsonPath('legal.version', config('dealerdock.legal.version'))
            ->assertJsonPath('legal.reviewed', false)
            ->assertJsonPath('turnstile_site_key', null);
    }

    // ---- Terms ----

    public function test_sign_up_needs_the_terms_agreed(): void
    {
        $this->postJson('/api/register', ['company' => 'Acme', 'name' => 'Sam', 'email' => 'sam@acme.test', 'password' => 'a-long-enough-pass-9'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accept_terms' => 'Please read and agree']);
        $this->assertSame(0, User::count());
    }

    public function test_invited_people_agree_before_reaching_any_data(): void
    {
        $acme = $this->manufacturer('acme');
        $user = $this->staff($acme);
        $user->forceFill(['terms_version' => null, 'terms_accepted_at' => null])->save();

        $this->actingAs($user)->getJson('/api/m/acme/dashboard')->assertForbidden()->assertJsonPath('code', 'terms_not_accepted');
        // /me still works, so the app can show the "please agree" page.
        $this->actingAs($user)->getJson('/api/me')->assertOk()->assertJsonPath('user.terms_version', null);

        $this->actingAs($user)->postJson('/api/terms/accept', ['version' => config('dealerdock.legal.version'), 'accept' => true])->assertOk();
        $this->assertNotNull($user->fresh()->terms_accepted_at);
        $this->actingAs($user)->getJson('/api/m/acme/dashboard')->assertOk();
    }

    public function test_a_new_version_of_the_terms_asks_everyone_again(): void
    {
        $acme = $this->manufacturer('acme');
        $prairie = $this->dealer('Prairie Equipment', $acme);
        $dealer = $this->dealerUser($prairie);
        $this->actingAs($dealer)->getJson('/api/m/acme/machines')->assertOk();

        config(['dealerdock.legal.version' => '2027-01-01']);

        $this->actingAs($dealer)->getJson('/api/m/acme/machines')->assertForbidden()->assertJsonPath('code', 'terms_not_accepted');
        // Agreeing to the old version - a page loaded before the change - doesn't count.
        $this->actingAs($dealer)->postJson('/api/terms/accept', ['version' => '2026-11-11', 'accept' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['version' => 'have changed']);
        $this->actingAs($dealer)->postJson('/api/terms/accept', ['version' => '2027-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('accept');

        $this->actingAs($dealer)->postJson('/api/terms/accept', ['version' => '2027-01-01', 'accept' => true])->assertOk();
        $this->actingAs($dealer)->getJson('/api/m/acme/machines')->assertOk();
    }

    // ---- Turnstile ----

    public function test_turnstile_is_skipped_when_not_set_up(): void
    {
        Http::fake();
        $this->signUp()->assertOk();
        Http::assertNothingSent();
    }

    public function test_with_turnstile_on_a_missing_or_failed_check_is_refused(): void
    {
        $this->turnstileOn(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $this->signUp(token: null)->assertUnprocessable()->assertJsonValidationErrors(['turnstile_token' => 'complete the "verify you are human" check']);
        $this->signUp(token: 'forged')->assertUnprocessable()->assertJsonValidationErrors(['turnstile_token' => 'couldn\'t confirm']);
        $this->assertSame(0, User::count());

        Http::assertSent(fn ($request) => $request['response'] === 'forged' && $request['secret'] === 'turnstile-secret');
    }

    public function test_with_turnstile_on_a_genuine_check_goes_through(): void
    {
        $this->turnstileOn(['success' => true]);

        $this->signUp(token: 'genuine')->assertOk();
        $this->getJson('/api/site')->assertJsonPath('turnstile_site_key', 'turnstile-site-key');
    }

    public function test_sign_in_checks_the_bot_check_before_the_password(): void
    {
        $this->turnstileOn(['success' => false]);
        User::factory()->create(['email' => 'sam@acme.test', 'password' => 'a-long-enough-pass-9']);

        // A failed check never reaches the password - a bot learns nothing about it.
        $this->postJson('/api/login', ['email' => 'sam@acme.test', 'password' => 'wrong', 'turnstile_token' => 'forged'])
            ->assertUnprocessable()->assertJsonValidationErrors('turnstile_token')->assertJsonMissingValidationErrors('email');

        $this->postJson('/api/forgot-password', ['email' => 'sam@acme.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
    }

    public function test_if_cloudflare_is_down_people_can_still_get_in(): void
    {
        $this->turnstileOn(null, 503);

        $this->signUp(token: 'whatever')->assertOk();
    }

    private function turnstileOn(?array $answer, int $status = 200): void
    {
        config(['services.turnstile.secret' => 'turnstile-secret', 'services.turnstile.site_key' => 'turnstile-site-key']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response($answer ?? '', $status)]);
    }

    private function signUp(?string $token = null)
    {
        return $this->postJson('/api/register', array_filter([
            'company' => 'Acme', 'name' => 'Sam', 'email' => 'sam@acme.test', 'password' => 'a-long-enough-pass-9',
            'accept_terms' => true, 'turnstile_token' => $token,
        ], fn ($v) => $v !== null));
    }
}
