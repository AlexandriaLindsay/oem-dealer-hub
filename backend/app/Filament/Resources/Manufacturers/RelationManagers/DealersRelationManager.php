<?php

namespace App\Filament\Resources\Manufacturers\RelationManagers;

use App\Filament\Resources\Dealers\DealerResource;
use App\Models\DealerLink;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The manufacturer's dealers, suspended ones included. */
class DealersRelationManager extends RelationManager
{
    protected static string $relationship = 'dealerLinks';

    protected static ?string $title = 'Dealers';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            // DealerLink is tenant-owned and the console has no tenant: lift
            // the manufacturer and territory scopes. The relationship still
            // limits it to this manufacturer's links.
            ->modifyQueryUsing(fn ($query) => $query->withoutGlobalScopes(['manufacturer', 'dealer'])->with('dealer'))
            ->columns([
                TextColumn::make('dealer.name')->label('Dealer')->searchable(),
                TextColumn::make('dealer_code')->label('Their code')->placeholder('-'),
                TextColumn::make('place')->label('Where')
                    ->state(fn (DealerLink $record) => collect([$record->dealer->city, $record->dealer->region, $record->dealer->country])->filter()->implode(', ') ?: null)
                    ->placeholder('-'),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => $state === DealerLink::ACTIVE ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('created_at')->label('Linked')->date('M j, Y'),
            ])
            ->recordUrl(fn (DealerLink $record) => DealerResource::getUrl('view', ['record' => $record->dealer_id]));
    }
}
