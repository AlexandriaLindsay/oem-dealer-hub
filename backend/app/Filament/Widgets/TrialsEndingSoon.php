<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Manufacturers\ManufacturerResource;
use App\Models\Manufacturer;
use App\Support\Plan;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Trials that run out within a week: the accounts worth a call. */
class TrialsEndingSoon extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Trials ending in the next 7 days')
            ->query(fn () => Manufacturer::query()
                ->where('plan', Plan::TRIAL)
                ->whereBetween('trial_ends_at', [now(), now()->addDays(7)]))
            ->defaultSort('trial_ends_at')
            ->paginated(false)
            ->emptyStateHeading('No trials end this week')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('owner')->state(fn (Manufacturer $record) => $record->stripeEmail())->placeholder('-'),
                TextColumn::make('trial_ends_at')->label('Ends')->dateTime('M j, Y g:i A')->sortable(),
                TextColumn::make('created_at')->label('Signed up')->date('M j, Y'),
            ])
            ->recordUrl(fn (Manufacturer $record) => ManufacturerResource::getUrl('view', ['record' => $record]));
    }
}
