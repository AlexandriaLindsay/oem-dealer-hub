<?php

namespace Tests\Feature;

use App\Models\Manufacturer;
use App\Models\User;
use App\Support\Plan;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** The SPA's requests come from a stateful domain, so they get a session. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', 'http://localhost:5173');
    }

    public function test_signing_up_creates_the_manufacturer_on_a_pro_trial_with_them_as_owner(): void
    {
        $this->postJson('/api/register', [
            'company' => 'Acme Scrapers',
            'name' => 'Sam Owner',
            'email' => 'sam@acme.test',
            'password' => 'a-long-enough-pass-9',
        ])->assertOk()
            ->assertJsonPath('user.email', 'sam@acme.test')
            ->assertJsonPath('user.two_factor', false)
            ->assertJsonPath('manufacturers.0.slug', 'acme-scrapers')
            ->assertJsonPath('manufacturers.0.role', 'owner')
            ->assertJsonPath('manufacturers.0.plan', Plan::TRIAL);

        $m = Manufacturer::sole();
        $this->assertTrue($m->trial_ends_at->between(now()->addDays(13), now()->addDays(15)));
        $this->assertAuthenticated();
    }

    public function test_slugs_stay_unique(): void
    {
        $this->manufacturer('acme-scrapers');

        $this->postJson('/api/register', [
            'company' => 'Acme Scrapers', 'name' => 'Sam', 'email' => 'sam@acme.test', 'password' => 'a-long-enough-pass-9',
        ])->assertOk()->assertJsonPath('manufacturers.0.slug', 'acme-scrapers-2');
    }

    public function test_tenant_data_waits_for_two_factor_setup(): void
    {
        $acme = $this->manufacturer('acme');
        $user = $this->staff($acme);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

        $this->actingAs($user)->getJson('/api/m/acme/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_setup_required');

        // /me still works, so the app knows to send them to setup.
        $this->actingAs($user)->getJson('/api/me')->assertOk()->assertJsonPath('user.two_factor', false);
    }

    public function test_setting_up_two_factor(): void
    {
        $user = User::factory()->create();

        $secret = $this->actingAs($user)->postJson('/api/two-factor/setup')
            ->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_url'])
            ->json('secret');

        $this->actingAs($user)->postJson('/api/two-factor/confirm', ['code' => '000000'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/two-factor/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();

        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertNotSame($secret, $user->fresh()->getRawOriginal('two_factor_secret'), 'stored encrypted');
    }

    public function test_sign_in_with_two_factor_is_password_then_code(): void
    {
        $user = User::factory()->create(['password' => 'a-long-enough-pass-9']);
        $secret = (new Google2FA)->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'a-long-enough-pass-9'])
            ->assertOk()
            ->assertExactJson(['two_factor' => true]);
        $this->assertGuest();

        $this->postJson('/api/login/two-factor', ['code' => '123456'])->assertUnprocessable();
        $this->assertGuest();

        $code = (new Google2FA)->getCurrentOtp($secret);
        $this->postJson('/api/login/two-factor', ['code' => $code])->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_two_factor_code_cant_be_used_twice(): void
    {
        $user = User::factory()->create(['password' => 'a-long-enough-pass-9']);
        $secret = (new Google2FA)->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'a-long-enough-pass-9']);
        $this->postJson('/api/login/two-factor', ['code' => $code])->assertOk();
        $this->postJson('/api/logout')->assertOk();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'a-long-enough-pass-9']);
        $this->postJson('/api/login/two-factor', ['code' => $code])->assertUnprocessable();
    }

    public function test_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'a-long-enough-pass-9']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'nope'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'nobody@x.test', 'password' => 'nope'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_invited_staff_get_a_set_your_password_link_to_the_app(): void
    {
        Notification::fake();
        $acme = $this->manufacturer('acme');

        $this->actingAs($this->staff($acme))
            ->postJson('/api/m/acme/staff', ['name' => 'Rae', 'email' => 'rae@acme.test', 'role' => 'warranty'])
            ->assertCreated();

        $rae = User::where('email', 'rae@acme.test')->sole();
        Notification::assertSentTo($rae, ResetPassword::class, function (ResetPassword $n) use ($rae) {
            return str_starts_with($n->toMail($rae)->actionUrl, config('app.frontend_url').'/reset-password?token=');
        });
    }

    public function test_the_owner_role_cant_be_handed_out(): void
    {
        $acme = $this->manufacturer('acme');

        $this->actingAs($this->staff($acme))
            ->postJson('/api/m/acme/staff', ['name' => 'Rae', 'email' => 'rae@acme.test', 'role' => 'owner'])
            ->assertUnprocessable();
    }
}
