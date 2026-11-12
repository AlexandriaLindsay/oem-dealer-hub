<?php

namespace App\Platform;

use App\Models\Manufacturer;
use App\Models\StaffAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * OEM Dealer Hub staff making changes on a customer's Integrations page, for
 * the "we set it up for you" service. Everywhere else in a customer's account
 * staff stay read-only (ResolveTenant).
 *
 * - Staff first give a reason (grant). That opens changes for 2 hours, for
 *   that staff member at that manufacturer, and goes into the staff activity log.
 * - Each change after that is logged too (record), with the reason, and the
 *   customer sees it on their Integrations page (recentFor), so nothing about
 *   how their systems connect changes without them knowing.
 */
class StaffWriteAccess
{
    public const HOURS = 2;

    /** Where staff may write, after the tenant prefix: the Integrations page's routes. */
    public static function covers(Request $request): bool
    {
        return (bool) preg_match('#^api/m/[^/]+/(integrations(/.*)?|staff-access/integrations)$#', $request->path());
    }

    public static function grant(User $staff, Manufacturer $manufacturer, string $reason): Carbon
    {
        $until = now()->addHours(self::HOURS);
        Cache::put(self::key($staff, $manufacturer), ['reason' => $reason, 'until' => $until->toIso8601String()], $until);
        StaffAction::record($staff, 'integrations_access', $manufacturer, $manufacturer->name, $reason, ['until' => $until->toIso8601String()]);

        return $until;
    }

    /** @return array{reason: string, until: string}|null */
    public static function current(User $staff, Manufacturer $manufacturer): ?array
    {
        return Cache::get(self::key($staff, $manufacturer));
    }

    /**
     * After a change went through: one line in the staff log, with the reason
     * they gave. "Try it" and "Test the connection" change nothing, so they
     * aren't logged.
     */
    public static function record(Request $request, User $staff, Manufacturer $manufacturer): void
    {
        $what = self::describe($request);
        $grant = self::current($staff, $manufacturer);
        if ($what === null || ! $grant) {
            return;
        }

        StaffAction::record($staff, 'changed_integrations', $manufacturer, $manufacturer->name, $what, ['reason' => $grant['reason']]);
    }

    /**
     * What staff changed on this manufacturer's Integrations page lately, for
     * the customer to see. The reason stays in our log.
     *
     * @return list<array{what: string, by: string, at: string|null}>
     */
    public static function recentFor(Manufacturer $manufacturer, int $limit = 10): array
    {
        return StaffAction::where('action', 'changed_integrations')
            ->where('subject_type', 'manufacturer')->where('subject_id', $manufacturer->id)
            ->where('created_at', '>=', now()->subDays(90))
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (StaffAction $a) => ['what' => $a->note, 'by' => $a->staff_name, 'at' => $a->created_at?->toIso8601String()])
            ->all();
    }

    /** The change in words, from the route; null for calls that change nothing. */
    private static function describe(Request $request): ?string
    {
        $path = preg_replace('#^api/m/[^/]+/#', '', $request->path());
        $delete = $request->isMethod('DELETE');

        return match (true) {
            str_starts_with($path, 'staff-access/'),
            $path === 'integrations/erp/test',
            str_starts_with($path, 'integrations/erp/preview/') => null,
            $path === 'integrations/erp' => $delete ? 'Disconnected the ERP connection' : 'Changed the ERP connection settings',
            str_starts_with($path, 'integrations/erp/sync/') => 'Started a sync from your ERP ('.str_replace('_', ' ', basename($path)).')',
            str_starts_with($path, 'integrations/erp/runs/') => 'Sent a sales order to your ERP again',
            $path === 'integrations/api-keys' => 'Made an API key ("'.mb_substr((string) $request->input('name'), 0, 100).'")',
            str_starts_with($path, 'integrations/api-keys/') => 'Revoked an API key',
            $path === 'integrations/webhooks' => 'Added a webhook ('.mb_substr((string) $request->input('url'), 0, 200).')',
            str_ends_with($path, '/rotate') => 'Made a new webhook signing secret',
            str_ends_with($path, '/test') => 'Sent a test webhook',
            str_starts_with($path, 'integrations/webhooks/') => $delete ? 'Removed a webhook' : 'Changed a webhook',
            str_starts_with($path, 'integrations/deliveries/') => 'Sent a webhook call again',
            $path === 'integrations/mapping' => 'Changed the ERP numbers for dealers, models or options',
            $path === 'integrations/settings' => 'Changed who\'s in charge of order status and prices',
            default => 'Changed something on the Integrations page',
        };
    }

    private static function key(User $staff, Manufacturer $manufacturer): string
    {
        return "staff-write:{$staff->id}:{$manufacturer->id}";
    }
}
