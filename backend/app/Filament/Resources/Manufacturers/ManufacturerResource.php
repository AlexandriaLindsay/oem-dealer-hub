<?php

namespace App\Filament\Resources\Manufacturers;

use App\Filament\Resources\Manufacturers\Pages\ListManufacturers;
use App\Filament\Resources\Manufacturers\Pages\ViewManufacturer;
use App\Filament\Resources\Manufacturers\RelationManagers\DealersRelationManager;
use App\Filament\Resources\Manufacturers\RelationManagers\StaffRelationManager;
use App\Filament\Resources\Manufacturers\Schemas\ManufacturerInfolist;
use App\Filament\Resources\Manufacturers\Tables\ManufacturersTable;
use App\Models\Manufacturer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The tenants. Read-only apart from the account changes on the view page:
 * customers manage their own company, staff and dealers in the app.
 */
class ManufacturerResource extends Resource
{
    protected static ?string $model = Manufacturer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('staff')
            // dealer_links is tenant-owned: counted with the query builder,
            // which has no tenant scope, rather than through the model.
            ->addSelect(['active_dealers_count' => DB::table('dealer_links')
                ->selectRaw('count(*)')
                ->whereColumn('dealer_links.manufacturer_id', 'manufacturers.id')
                ->where('status', 'active')])
            ->with('subscriptions');
    }

    public static function canCreate(): bool
    {
        // Manufacturers sign up themselves, so they agree to the terms.
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ManufacturerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ManufacturersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StaffRelationManager::class,
            DealersRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'slug'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListManufacturers::route('/'),
            'view' => ViewManufacturer::route('/{record}'),
        ];
    }
}
