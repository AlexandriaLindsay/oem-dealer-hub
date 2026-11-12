<?php

namespace App\Filament\Resources\Manufacturers\Tables;

use App\Models\Manufacturer;
use App\Platform\Standings;
use App\Support\Plan;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManufacturersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Manufacturer $record) => $record->slug)
                    ->searchable(['name', 'slug'])
                    ->sortable(),
                TextColumn::make('plan')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Standings::PLAN_LABELS[$state] ?? $state)
                    ->color('gray'),
                TextColumn::make('standing')
                    ->state(fn (Manufacturer $record) => Plan::standing($record))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Standings::LABELS[$state])
                    ->color(fn (string $state) => Standings::COLORS[$state]),
                TextColumn::make('trial_ends_at')
                    ->label('Trial ends')
                    ->date('M j, Y')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('active_dealers_count')
                    ->label('Dealers')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('staff_count')
                    ->label('Staff')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Signed up')
                    ->date('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('standing')
                    ->options(Standings::LABELS)
                    ->query(fn (Builder $query, array $data) => filled($data['value']) ? Standings::where($query, $data['value']) : $query),
                SelectFilter::make('plan')
                    ->options(Standings::PLAN_LABELS),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
