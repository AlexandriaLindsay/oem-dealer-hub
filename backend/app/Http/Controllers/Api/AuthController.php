<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\TrustedBrowser;
use App\Models\User;
use App\Support\EmailCodes;
use App\Support\Plan;
use App\Support\Turnstile;
use App\Support\TwoFactor;
use App\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Session sign-in for the SPA (Sanctum's cookie auth). Sign-in with two-factor
 * set up is two steps: password, then code - the user isn't signed in until
 * the code is right.
 */
class AuthController extends Controller
{
    /** A manufacturer signs up: their company, and them as its owner, on a 14-day Pro trial. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', PasswordRule::defaults()],
            // Agreeing to the terms and privacy policy, for the company and for themselves.
            'accept_terms' => ['accepted'],
        ], [
            'accept_terms.accepted' => 'Please read and agree to the terms and privacy policy.',
        ]);
        Turnstile::check($request);

        $user = DB::transaction(function () use ($data) {
            $manufacturer = Manufacturer::create([
                'name' => $data['company'],
                'slug' => self::uniqueSlug($data['company']),
                'plan' => Plan::TRIAL,
                'trial_ends_at' => now()->addDays(Plan::TRIAL_DAYS),
            ]);

            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->forceFill(['terms_version' => config('dealerdock.legal.version'), 'terms_accepted_at' => now()])->save();
            Membership::create([
                'user_id' => $user->id,
                'org_type' => Membership::MANUFACTURER,
                'org_id' => $manufacturer->id,
                'role' => 'owner',
            ]);

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->me($request);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);
        Turnstile::check($request);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Auth::guard('web')->getProvider()->validateCredentials($user, ['password' => $data['password']])) {
            throw ValidationException::withMessages(['email' => 'That email and password don\'t match.']);
        }

        // A browser they said to remember: the password is enough here.
        $trusted = $user->hasTwoFactor() ? TrustedBrowser::find($user, $request->cookie(TrustedBrowser::COOKIE)) : null;
        if ($trusted) {
            $trusted->forceFill(['last_used_at' => now()])->save();
        }

        if ($user->hasTwoFactor() && ! $trusted) {
            $request->session()->put('login.pending', [
                'id' => $user->id,
                'remember' => (bool) ($data['remember'] ?? false),
                // Long enough to fetch an emailed code.
                'expires' => now()->addMinutes(10)->timestamp,
            ]);

            $method = $user->twoFactorMethod();
            // Email-code people get their code straight away.
            if ($method === 'email') {
                app(EmailCodes::class)->send($user);
            }

            return response()->json(['two_factor' => true, 'method' => $method, 'email' => EmailCodes::mask($user->email)]);
        }

        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();

        return $this->me($request);
    }

    /** "Email me a code": for email-code people (again), or app users without their phone. */
    public function emailCode(Request $request, EmailCodes $codes): JsonResponse
    {
        $user = $this->pendingUser($request);
        if (! $codes->send($user)) {
            throw ValidationException::withMessages(['code' => 'A code was sent just now. Wait a minute, then ask again.']);
        }

        return response()->json(['sent' => true, 'email' => EmailCodes::mask($user->email)]);
    }

    /**
     * The second step: a code from the authenticator app, or from the email.
     * "Remember this browser" skips this step on this browser for 30 days.
     */
    public function twoFactorChallenge(Request $request, TwoFactor $twoFactor, EmailCodes $codes): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string'], 'remember_browser' => ['boolean']]);
        $user = $this->pendingUser($request);
        $pending = $request->session()->get('login.pending');

        $ok = ($user->hasAuthenticatorApp() && $twoFactor->verify($user, $user->two_factor_secret, $data['code']))
            || $codes->verify($user, $data['code']);
        if (! $ok) {
            throw ValidationException::withMessages(['code' => $user->twoFactorMethod() === 'email'
                ? 'That code isn\'t right. Check the latest email from OEM Dealer Hub, or ask for a new code.'
                : 'That code isn\'t right. Check your authenticator app and try again.']);
        }

        $request->session()->forget('login.pending');
        Auth::guard('web')->login($user, $pending['remember']);
        $request->session()->regenerate();

        $response = $this->me($request);
        if ($data['remember_browser'] ?? false) {
            $response->withCookie(TrustedBrowser::remember($user, $request->userAgent()));
        }

        return $response;
    }

    /** Whoever passed the password step in this session, within the last 10 minutes. */
    private function pendingUser(Request $request): User
    {
        $pending = $request->session()->get('login.pending');
        $user = $pending && $pending['expires'] > now()->timestamp ? User::find($pending['id']) : null;

        if (! $user) {
            $request->session()->forget('login.pending');
            throw ValidationException::withMessages(['code' => 'Sign-in timed out. Enter your email and password again.']);
        }

        return $user;
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    /** The signed-in user and every manufacturer they can work in (the brand switcher). */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user() ?? Auth::guard('web')->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'two_factor' => $user->hasTwoFactor(),
                'two_factor_method' => $user->twoFactorMethod(),
                'two_factor_required' => (bool) config('dealerdock.require_two_factor'),
                // Tour keys this person has finished or skipped (Tours).
                'tours_seen' => array_keys($user->tours_seen ?? []),
                // The version of the terms they agreed to; the app asks again when it isn't the current one.
                'terms_version' => $user->terms_version,
                // OEM Dealer Hub's own people get a link to the staff console.
                'console_url' => $user->is_platform_staff ? rtrim(config('app.url'), '/').'/admin' : null,
            ],
            'terms_version' => config('dealerdock.legal.version'),
            'manufacturers' => Access::for($user)->map(fn ($c) => Access::describe($c))->values(),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        // Otherwise a bot could flood anyone's inbox with reset emails.
        Turnstile::check($request);
        Password::sendResetLink($request->only('email'));

        // The same answer whether or not the address has an account.
        return response()->json(['message' => 'If that email has an account, a reset link is on its way.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                // A new password: every remembered browser has to pass a code again.
                $user->trustedBrowsers()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'That reset link has expired or was already used. Ask for a new one.']);
        }

        return response()->json(['message' => 'Password set. You can sign in now.']);
    }

    private static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'manufacturer';
        $base = Str::limit($base, 40, '');
        $slug = $base;

        for ($i = 2; Manufacturer::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
