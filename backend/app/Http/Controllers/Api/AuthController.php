<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Manufacturer;
use App\Models\Membership;
use App\Models\User;
use App\Support\Plan;
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
        ]);

        $user = DB::transaction(function () use ($data) {
            $manufacturer = Manufacturer::create([
                'name' => $data['company'],
                'slug' => self::uniqueSlug($data['company']),
                'plan' => Plan::TRIAL,
                'trial_ends_at' => now()->addDays(Plan::TRIAL_DAYS),
            ]);

            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
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

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Auth::guard('web')->getProvider()->validateCredentials($user, ['password' => $data['password']])) {
            throw ValidationException::withMessages(['email' => 'That email and password don\'t match.']);
        }

        if ($user->hasTwoFactor()) {
            $request->session()->put('login.pending', [
                'id' => $user->id,
                'remember' => (bool) ($data['remember'] ?? false),
                'expires' => now()->addMinutes(5)->timestamp,
            ]);

            return response()->json(['two_factor' => true]);
        }

        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();

        return $this->me($request);
    }

    /** The second step: the authenticator code. */
    public function twoFactorChallenge(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $pending = $request->session()->get('login.pending');
        $user = $pending && $pending['expires'] > now()->timestamp ? User::find($pending['id']) : null;

        if (! $user) {
            $request->session()->forget('login.pending');
            throw ValidationException::withMessages(['code' => 'Sign-in timed out. Enter your email and password again.']);
        }

        if (! $twoFactor->verify($user, $user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code isn\'t right. Check your authenticator app and try again.']);
        }

        $request->session()->forget('login.pending');
        Auth::guard('web')->login($user, $pending['remember']);
        $request->session()->regenerate();

        return $this->me($request);
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
                'two_factor_required' => (bool) config('dealerdock.require_two_factor'),
            ],
            'manufacturers' => Access::for($user)->map(fn ($c) => Access::describe($c))->values(),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
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
