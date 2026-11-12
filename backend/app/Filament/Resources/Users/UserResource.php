<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\MembershipsRelationManager;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everyone with a sign-in: manufacturers' staff, dealers' people and platform
 * staff. Staff can't edit anyone's details, password or staff status here;
 * the only changes are the support actions on the view page.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'People';

    protected static ?string $modelLabel = 'person';

    protected static ?string $pluralModelLabel = 'people';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('memberships');
    }

    public static function canCreate(): bool
    {
        // People come in by signing up or by invitation, so they agree to the terms themselves.
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->components([
                    TextEntry::make('name'),
                    TextEntry::make('email')->copyable(),
                    TextEntry::make('created_at')->label('Joined')->dateTime('M j, Y g:i A'),
                    TextEntry::make('two_factor_confirmed_at')->label('Two-factor set up')
                        ->dateTime('M j, Y g:i A')->placeholder('Not set up'),
                    TextEntry::make('terms_accepted_at')->label('Agreed to the terms')
                        ->dateTime('M j, Y g:i A')
                        ->helperText(fn (User $record) => $record->terms_version ? 'Version '.$record->terms_version : null)
                        ->placeholder('Not yet'),
                    IconEntry::make('is_platform_staff')->label('OEM Dealer Hub staff')->boolean(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                IconColumn::make('two_factor_confirmed_at')->label('Two-factor')->boolean()
                    ->state(fn (User $record) => $record->hasTwoFactor()),
                IconColumn::make('is_platform_staff')->label('Staff')->boolean()
                    ->trueIcon(Heroicon::OutlinedShieldCheck)->falseIcon(null),
                TextColumn::make('memberships_count')->label('Works with')->numeric()
                    ->formatStateUsing(fn (int $state) => $state === 1 ? '1 org' : "$state orgs"),
                TextColumn::make('created_at')->label('Joined')->date('M j, Y')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('two_factor_confirmed_at')->label('Two-factor')->nullable()
                    ->trueLabel('Set up')->falseLabel('Not set up'),
                TernaryFilter::make('is_platform_staff')->label('OEM Dealer Hub staff'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembershipsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
