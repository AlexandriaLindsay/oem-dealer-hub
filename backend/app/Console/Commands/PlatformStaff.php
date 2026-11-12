<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Who is OEM Dealer Hub staff (can open the staff console). Only this command
 * changes it, on purpose: someone who got hold of a staff session in the
 * browser can't make more staff.
 */
class PlatformStaff extends Command
{
    protected $signature = 'platform:staff
        {email? : The person\'s email (they need an account already)}
        {--revoke : Take staff access away instead}';

    protected $description = 'Grant or revoke OEM Dealer Hub staff console access, or list who has it';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (! $email) {
            $staff = User::where('is_platform_staff', true)->orderBy('email')->get();
            $staff->isEmpty()
                ? $this->info('Nobody is platform staff yet.')
                : $this->table(['Email', 'Name', 'Two-factor'], $staff->map(fn (User $u) => [$u->email, $u->name, $u->hasTwoFactor() ? 'yes' : 'not set up']));

            return self::SUCCESS;
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("No account uses $email. They need to sign up or be invited first.");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_platform_staff' => $grant])->save();

        if (! $grant) {
            $this->info("$email is no longer platform staff.");
        } elseif ($user->hasTwoFactor()) {
            $this->info("$email is platform staff. They can open the console at ".rtrim(config('app.url'), '/').'/admin');
        } else {
            $this->info("$email is platform staff. They need to set up two-factor in the app before the console opens.");
        }

        return self::SUCCESS;
    }
}
