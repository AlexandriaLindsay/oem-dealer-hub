<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Dealers\DealerResource;
use App\Filament\Resources\Manufacturers\ManufacturerResource;
use App\Models\Membership;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Where this person works: each manufacturer or dealer, and their role there. */
class MembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Works with';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['manufacturer', 'dealer']))
            ->columns([
                TextColumn::make('org_type')->label('As')->badge()
                    ->formatStateUsing(fn (string $state) => $state === Membership::DEALER ? 'Dealer' : 'Manufacturer staff')
                    ->color(fn (string $state) => $state === Membership::DEALER ? 'warning' : 'info'),
                TextColumn::make('org')->label('Organisation')
                    ->state(fn (Membership $record) => $record->isDealer() ? $record->dealer?->name : $record->manufacturer?->name),
                TextColumn::make('role')->formatStateUsing(fn (string $state) => Str::headline($state)),
                TextColumn::make('created_at')->label('Since')->date('M j, Y'),
            ])
            ->recordUrl(fn (Membership $record) => $record->isDealer()
                ? DealerResource::getUrl('view', ['record' => $record->org_id])
                : ManufacturerResource::getUrl('view', ['record' => $record->manufacturer?->slug ?? $record->org_id]));
    }
}
