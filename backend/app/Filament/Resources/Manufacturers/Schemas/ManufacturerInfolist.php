<?php

namespace App\Filament\Resources\Manufacturers\Schemas;

use App\Billing\Usage;
use App\Models\Manufacturer;
use App\Platform\Standings;
use App\Support\Plan;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ManufacturerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Account')
                    ->components([
                        TextEntry::make('name'),
                        TextEntry::make('slug')->label('Address in the app')->formatStateUsing(fn (string $state) => "/m/$state"),
                        TextEntry::make('owner')->state(fn (Manufacturer $record) => $record->stripeEmail())->placeholder('No owner'),
                        TextEntry::make('created_at')->label('Signed up')->dateTime('M j, Y g:i A'),
                    ]),
                Section::make('Plan')
                    ->components([
                        TextEntry::make('plan')->badge()->color('gray')
                            ->formatStateUsing(fn (string $state) => Standings::PLAN_LABELS[$state] ?? $state),
                        TextEntry::make('standing')->badge()
                            ->state(fn (Manufacturer $record) => Plan::standing($record))
                            ->formatStateUsing(fn (string $state) => Standings::LABELS[$state])
                            ->color(fn (string $state) => Standings::COLORS[$state]),
                        TextEntry::make('trial_ends_at')->label('Trial ends')->dateTime('M j, Y g:i A')->placeholder('-'),
                        TextEntry::make('billing')
                            ->state(fn (Manufacturer $record) => self::billing($record)),
                    ]),
                Section::make('Usage')
                    ->description('Against the limits of the plan in effect (a trial runs on Pro).')
                    ->components([
                        TextEntry::make('usage_dealers')->label('Active dealers')
                            ->state(fn (Manufacturer $record) => self::against($record, 'dealers')),
                        TextEntry::make('usage_staff')->label('Staff')
                            ->state(fn (Manufacturer $record) => self::against($record, 'staff')),
                        TextEntry::make('usage_storage')->label('File storage')
                            ->state(fn (Manufacturer $record) => self::against($record, 'storage_gb', ' GB')),
                        TextEntry::make('usage_price_lists')->label('Price lists')
                            ->state(fn (Manufacturer $record) => self::against($record, 'price_lists')),
                    ]),
            ]);
    }

    private static function billing(Manufacturer $m): string
    {
        $subscription = $m->subscription('default');
        if ($subscription) {
            $status = str_replace('_', ' ', $subscription->stripe_status);

            return $subscription->ends_at
                ? "Stripe, $status, ends ".$subscription->ends_at->timezone('America/New_York')->format('M j, Y')
                : "Stripe, $status";
        }

        return in_array($m->plan, [Plan::STARTER, Plan::PRO], true) ? 'Outside Stripe (invoice or free)' : 'Not billed';
    }

    /** "3 of 10", or "3 (no limit)". */
    private static function against(Manufacturer $m, string $key, string $unit = ''): string
    {
        static $usage = [];
        $usage[$m->id] ??= Usage::of($m);
        $used = $usage[$m->id][$key].$unit;
        $limit = Plan::limit($m, $key);

        return $limit === null ? "$used (no limit)" : "$used of $limit$unit";
    }
}
