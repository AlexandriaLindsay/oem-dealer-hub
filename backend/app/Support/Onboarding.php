<?php

namespace App\Support;

use App\Models\DealerLink;
use App\Models\Document;
use App\Models\Machine;
use App\Models\Manufacturer;
use App\Models\ProductLine;
use App\Models\ProductModel;

/**
 * The "Getting started" checklist for a new manufacturer (docs/PLAN.md
 * section 6). Every step is worked out from the manufacturer's real data -
 * there's nothing to tick by hand, so it can't say "done" when it isn't, or
 * nag about something already done another way (an import, say).
 *
 * Runs inside the current tenant.
 */
final class Onboarding
{
    /** @return list<array{key: string, title: string, help: string, link: string, done: bool}> */
    public static function steps(Manufacturer $manufacturer): array
    {
        $documents = Document::withoutGlobalScope('audience');

        return [
            [
                'key' => 'logo',
                'title' => 'Add your logo',
                'help' => 'Your staff and dealers see it at the top of every page.',
                'link' => 'settings',
                'done' => $manufacturer->logo_path !== null,
            ],
            [
                'key' => 'products',
                'title' => 'Add your product lines and models',
                'help' => 'Machines, manuals and registrations all hang off your models.',
                'link' => 'products',
                'done' => ProductLine::exists() && ProductModel::exists(),
            ],
            [
                'key' => 'machines',
                'title' => 'Bring in your machines',
                'help' => 'Import them from a spreadsheet, or add them one at a time.',
                'link' => 'machines/import',
                'done' => Machine::exists(),
            ],
            [
                'key' => 'dealers',
                'title' => 'Invite your first dealer',
                'help' => 'Dealers are always free. They see their own machines and your manuals.',
                'link' => 'dealers',
                'done' => DealerLink::exists(),
            ],
            [
                'key' => 'documents',
                'title' => 'Add a manual',
                'help' => 'Link it to a model, and dealers can find it by serial number.',
                'link' => 'documents',
                'done' => $documents->exists(),
            ],
            [
                'key' => 'staff',
                'title' => 'Invite your team',
                'help' => 'Sales reps, inside sales, warranty and parts - each sees what their role needs.',
                'link' => 'staff',
                'done' => $manufacturer->staff()->count() > 1,
            ],
        ];
    }

    public static function dismissed(Manufacturer $manufacturer): bool
    {
        return ! empty($manufacturer->settings['onboarding_dismissed_at']);
    }

    public static function setDismissed(Manufacturer $manufacturer, bool $dismissed): void
    {
        $settings = $manufacturer->settings ?? [];
        if ($dismissed) {
            $settings['onboarding_dismissed_at'] = now()->toIso8601String();
        } else {
            unset($settings['onboarding_dismissed_at']);
        }
        $manufacturer->update(['settings' => $settings]);
    }
}
