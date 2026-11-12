<?php

namespace Tests\Feature;

use App\Models\TrustedBrowser;
use App\Models\User;
use App\Notifications\TwoFactorCode;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Email codes and "remember this browser" - two-factor that dealers will actually keep using. */
class EasierSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The SPA's requests come from a stateful domain, so they get a session.
        $this->withHeader('Referer', 'http://localhost:5173');
        // Send cookies with JSON requests, as the browser does (the remembered-browser cookie).
        $this->withCredentials();
        Notification::fake();
    }

    public function test_someone_can_choose_email_codes_instead_of_an_app(): void
    {
        $user = User::factory()->create(['password' => 'a-long-password']);

        $this->actingAs($user)->postJson('/api/two-factor/email')->assertOk()->assertJsonPath('email', substr($user->email, 0, 1).'•••@'.explode('@', $user->email)[1]);
        $this->actingAs($user)->postJson('/api/two-factor/email/confirm', ['code' => '000000'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/two-factor/email/confirm', ['code' => $this->lastCode($user)])->assertOk()->assertJsonPath('method', 'email');

        $user->refresh();
        $this->assertTrue($user->hasTwoFactor());
        $this->assertSame('email', $user->twoFactorMethod());
        $this->assertFalse($user->hasAuthenticatorApp());
    }

    public function test_an_email_code_person_gets_a_code_at_sign_in(): void
    {
        $user = $this->emailUser();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'a-long-password'])
            ->assertOk()->assertJsonPath('two_factor', true)->assertJsonPath('method', 'email');
        $code = $this->lastCode($user);

        $this->postJson('/api/login/two-factor', ['code' => '123123'])->assertUnprocessable()->assertJsonValidationErrors(['code' => 'latest email']);
        $this->postJson('/api/login/two-factor', ['code' => $code])->assertOk()->assertJsonPath('user.email', $user->email);
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_app_user_can_ask_for_an_email_code_instead(): void
    {
        $user = $this->appUser();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'a-long-password'])->assertJsonPath('method', 'totp');
        Notification::assertNothingSent();

        $this->postJson('/api/login/two-factor/email')->assertOk();
        // Not twice in the same minute.
        $this->postJson('/api/login/two-factor/email')->assertUnprocessable()->assertJsonValidationErrors(['code' => 'Wait a minute']);

        $this->postJson('/api/login/two-factor', ['code' => $this->lastCode($user)])->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_email_code_works_once_and_five_wrong_tries_spend_it(): void
    {
        $user = $this->emailUser();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'a-long-password']);
        $code = $this->lastCode($user);

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/login/two-factor', ['code' => '999999'])->assertUnprocessable();
        }
        $this->postJson('/api/login/two-factor', ['code' => $code])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_asking_for_a_code_needs_the_password_first(): void
    {
        $this->postJson('/api/login/two-factor/email')->assertUnprocessable()->assertJsonValidationErrors(['code' => 'timed out']);
        Notification::assertNothingSent();
    }

    public function test_a_remembered_browser_skips_the_code_for_30_days(): void
    {
        $user = $this->appUser();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'a-long-password']);
        $signedIn = $this->postJson('/api/login/two-factor', ['code' => $this->appCode(), 'remember_browser' => true])->assertOk();

        $cookie = collect($signedIn->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === TrustedBrowser::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(1, $user->trustedBrowsers()->count());
        // Only a hash is stored.
        $this->assertNotSame($cookie->getValue(), $user->trustedBrowsers()->first()->token_hash);

        $this->postJson('/api/logout');
        $this->withCookie(TrustedBrowser::COOKIE, CookieValuePrefix::remove(decrypt($cookie->getValue(), false)))
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'a-long-password'])
            ->assertOk()->assertJsonPath('user.email', $user->email);
        $this->assertAuthenticatedAs($user);

        // After 30 days it asks again.
        $this->postJson('/api/logout');
        $this->travel(TrustedBrowser::DAYS + 1)->days();
        $this->withCookie(TrustedBrowser::COOKIE, CookieValuePrefix::remove(decrypt($cookie->getValue(), false)))
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'a-long-password'])
            ->assertJsonPath('two_factor', true);
    }

    public function test_one_persons_remembered_browser_doesnt_help_another(): void
    {
        $alice = $this->appUser();
        $bob = $this->appUser();
        $cookie = TrustedBrowser::remember($alice, 'Chrome');

        $this->withCookie(TrustedBrowser::COOKIE, $cookie->getValue())
            ->postJson('/api/login', ['email' => $bob->email, 'password' => 'a-long-password'])
            ->assertJsonPath('two_factor', true);
        $this->assertGuest();
    }

    public function test_remembered_browsers_are_listed_and_forgotten(): void
    {
        $user = $this->appUser();
        TrustedBrowser::remember($user, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36');
        TrustedBrowser::remember($user, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1');

        $list = $this->actingAs($user)->getJson('/api/account/security')->assertOk()->assertJsonPath('two_factor_method', 'totp')->json('trusted_browsers');
        $this->assertEqualsCanonicalizing(['Chrome on Windows', 'Safari on iPhone or iPad'], array_column($list, 'label'));

        $this->actingAs($user)->deleteJson('/api/account/trusted-browsers/'.$list[0]['id'])->assertNoContent();
        $this->assertSame(1, $user->trustedBrowsers()->count());

        // Not someone else's.
        $other = $this->appUser();
        $theirs = TrustedBrowser::remember($other, 'Firefox');
        $this->actingAs($user)->deleteJson('/api/account/trusted-browsers/'.$other->trustedBrowsers()->first()->id)->assertNotFound();

        $this->actingAs($user)->deleteJson('/api/account/trusted-browsers')->assertNoContent();
        $this->assertSame(0, $user->trustedBrowsers()->count());
        $this->assertSame(1, $other->trustedBrowsers()->count());
        $this->assertNotNull($theirs);
    }

    public function test_a_new_password_forgets_every_remembered_browser(): void
    {
        $user = $this->appUser();
        TrustedBrowser::remember($user, 'Chrome');

        $token = app('auth.password.broker')->createToken($user);
        $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'another-long-password', 'password_confirmation' => 'another-long-password'])->assertOk();

        $this->assertSame(0, $user->trustedBrowsers()->count());
    }

    public function test_an_email_code_person_can_move_to_an_app(): void
    {
        $user = $this->emailUser();

        $secret = $this->actingAs($user)->postJson('/api/two-factor/setup')->assertOk()->json('secret');
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->actingAs($user)->postJson('/api/two-factor/confirm', ['code' => $code])->assertOk()->assertJsonPath('method', 'totp');

        $this->assertTrue($user->fresh()->hasAuthenticatorApp());
        // And with an app, it's not set up again.
        $this->actingAs($user->fresh())->postJson('/api/two-factor/setup')->assertUnprocessable();
    }

    public function test_people_set_up_before_email_codes_count_as_app_users(): void
    {
        $user = $this->userWithTwoFactor();

        $this->assertSame('totp', $user->twoFactorMethod());
        $this->assertTrue($user->hasAuthenticatorApp());
    }

    // ---- Helpers ----

    private function emailUser(): User
    {
        $user = User::factory()->create(['password' => 'a-long-password']);
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_method' => 'email', 'terms_version' => config('dealerdock.legal.version'), 'terms_accepted_at' => now()])->save();

        return $user;
    }

    private function appUser(): User
    {
        $user = User::factory()->create(['password' => 'a-long-password']);
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(), 'two_factor_method' => 'totp', 'terms_version' => config('dealerdock.legal.version'), 'terms_accepted_at' => now()])->save();

        return $user;
    }

    private function appCode(): string
    {
        return app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP');
    }

    /** The code in the last email to $user. */
    private function lastCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, TwoFactorCode::class, function (TwoFactorCode $n) use ($user, &$code) {
            preg_match('/\*\*(\d{6})\*\*/', implode("\n", array_map('strval', $n->toMail($user)->introLines)), $m);
            $code = $m[1] ?? null;

            return true;
        });

        return (string) $code;
    }
}
