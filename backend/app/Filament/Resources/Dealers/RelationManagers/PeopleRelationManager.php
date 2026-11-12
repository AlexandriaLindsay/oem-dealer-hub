<?php

namespace App\Filament\Resources\Dealers\RelationManagers;

use App\Filament\Resources\Users\UserResource;
use App\Models\Membership;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** The dealer's own people. */
class PeopleRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'People';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Name'),
                TextColumn::make('user.email')->label('Email'),
                TextColumn::make('role')->formatStateUsing(fn (string $state) => Str::headline($state)),
                IconColumn::make('two_factor')->label('Two-factor')->boolean()
                    ->state(fn (Membership $record) => $record->user->hasTwoFactor()),
            ])
            ->recordUrl(fn (Membership $record) => UserResource::getUrl('view', ['record' => $record->user_id]));
    }
}
