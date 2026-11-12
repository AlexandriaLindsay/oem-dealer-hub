<?php

namespace App\Filament\Resources\Dealers;

use App\Filament\Resources\Dealers\Pages\ListDealers;
use App\Filament\Resources\Dealers\Pages\ViewDealer;
use App\Filament\Resources\Dealers\RelationManagers\LinksRelationManager;
use App\Filament\Resources\Dealers\RelationManagers\PeopleRelationManager;
use App\Models\Dealer;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dealer businesses across the network. One dealer can carry several
 * manufacturers, so this is the only place that shows all its links at once.
 * Read-only: manufacturers add and suspend their own dealers.
 */
class DealerResource extends Resource
{
    protected static ?string $model = Dealer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('members')
            // Counted with the query builder: dealer_links is tenant-owned.
            ->addSelect(['links_count' => DB::table('dealer_links')
                ->selectRaw('count(*)')
                ->whereColumn('dealer_links.dealer_id', 'dealers.id')]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'city'];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->components([
                    TextEntry::make('name'),
                    TextEntry::make('place')->label('Where')
                        ->state(fn (Dealer $record) => collect([$record->city, $record->region, $record->country])->filter()->implode(', ') ?: null)
                        ->placeholder('-'),
                    TextEntry::make('phone')->placeholder('-'),
                    TextEntry::make('website')->placeholder('-')->url(fn (?string $state) => $state, shouldOpenInNewTab: true),
                    TextEntry::make('created_at')->label('Added')->dateTime('M j, Y g:i A'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('place')->label('Where')
                    ->state(fn (Dealer $record) => collect([$record->city, $record->region, $record->country])->filter()->implode(', ') ?: null)
                    ->placeholder('-'),
                TextColumn::make('links_count')->label('Manufacturers')->numeric()->sortable(),
                TextColumn::make('members_count')->label('People')->numeric()->sortable(),
                TextColumn::make('created_at')->label('Added')->date('M j, Y')->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LinksRelationManager::class,
            PeopleRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDealers::route('/'),
            'view' => ViewDealer::route('/{record}'),
        ];
    }
}
