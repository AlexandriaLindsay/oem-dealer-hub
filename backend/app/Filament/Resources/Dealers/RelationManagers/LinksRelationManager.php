<?php

namespace App\Filament\Resources\Dealers\RelationManagers;

use App\Filament\Resources\Manufacturers\ManufacturerResource;
use App\Models\DealerLink;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The manufacturers this dealer carries. */
class LinksRelationManager extends RelationManager
{
    protected static string $relationship = 'links';

    protected static ?string $title = 'Manufacturers';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            // Every manufacturer's link to this dealer: lift the tenant and territory scopes.
            ->modifyQueryUsing(fn ($query) => $query->withoutGlobalScopes(['manufacturer', 'dealer'])->with('manufacturer'))
            ->columns([
                TextColumn::make('manufacturer.name')->label('Manufacturer'),
                TextColumn::make('dealer_code')->label('Their code for the dealer')->placeholder('-'),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => $state === DealerLink::ACTIVE ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('created_at')->label('Linked')->date('M j, Y'),
            ])
            ->recordUrl(fn (DealerLink $record) => ManufacturerResource::getUrl('view', ['record' => $record->manufacturer]));
    }
}
