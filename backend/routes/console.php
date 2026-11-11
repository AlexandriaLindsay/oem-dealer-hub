<?php

use App\Models\UsageDay;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Usage counts are kept 13 months, then deleted - what the privacy policy
 * says. Across every manufacturer on purpose (a date-only cleanup, no one's
 * data read), so withoutTenant().
 */
Artisan::command('usage:prune', function () {
    $deleted = UsageDay::withoutTenant()->where('day', '<', now()->subMonths(13)->toDateString())->delete();
    $this->info("Deleted {$deleted} usage rows older than 13 months.");
})->purpose('Delete usage counts older than 13 months');

Schedule::command('usage:prune')->daily();
