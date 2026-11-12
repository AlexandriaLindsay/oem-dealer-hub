<?php

namespace App\Filament\Resources\Manufacturers\Pages;

use App\Filament\Resources\Manufacturers\ManufacturerResource;
use App\Filament\Support\Change;
use App\Models\Manufacturer;
use App\Platform\AccountTools;
use App\Support\Plan;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class ViewManufacturer extends ViewRecord
{
    protected static string $resource = ManufacturerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('extendTrial')
                ->label('Extend trial')
                ->icon(Heroicon::OutlinedClock)
                ->visible(fn (Manufacturer $record) => AccountTools::billedByStripe($record) === null
                    && in_array($record->plan, [Plan::TRIAL, Plan::EXPIRED], true))
                ->modalDescription('Gives the account Pro on trial until the end of the day you pick (Eastern). Staff and dealers get straight back in if it was paused.')
                ->schema([
                    DatePicker::make('until')
                        ->label('Trial ends on')
                        ->required()
                        ->minDate(today('America/New_York')->addDay())
                        ->maxDate(today('America/New_York')->addDays(AccountTools::MAX_TRIAL_DAYS))
                        ->default(today('America/New_York')->addDays(7)),
                    Change::reason(),
                ])
                ->action(fn (array $data, Manufacturer $record, Action $action) => Change::run(
                    $action,
                    fn () => AccountTools::extendTrial(
                        $record,
                        Carbon::parse($data['until'], 'America/New_York')->endOfDay(),
                        auth()->user(),
                        $data['note'],
                    ),
                    'Trial extended',
                )),

            Action::make('setPlan')
                ->label('Set plan')
                ->icon(Heroicon::OutlinedCreditCard)
                ->visible(fn (Manufacturer $record) => AccountTools::billedByStripe($record) === null)
                ->modalDescription('For accounts paid by invoice or given free. Starter or Pro set here is active with no card. Paused stops staff and dealers until a plan is chosen, and deletes nothing.')
                ->schema([
                    Select::make('plan')
                        ->options([
                            Plan::STARTER => 'Starter, billed outside Stripe',
                            Plan::PRO => 'Pro, billed outside Stripe',
                            Plan::EXPIRED => 'Paused (no plan)',
                        ])
                        ->required(),
                    Change::reason(),
                ])
                ->action(fn (array $data, Manufacturer $record, Action $action) => Change::run(
                    $action,
                    fn () => AccountTools::setPlan($record, $data['plan'], auth()->user(), $data['note']),
                    'Plan changed',
                )),

            Action::make('stripe')
                ->label('Open in Stripe')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (Manufacturer $record) => filled($record->stripe_id))
                ->url(fn (Manufacturer $record) => 'https://dashboard.stripe.com'
                    .(str_starts_with((string) config('cashier.secret'), 'sk_live_') ? '' : '/test')
                    .'/customers/'.$record->stripe_id, shouldOpenInNewTab: true),
        ];
    }
}
