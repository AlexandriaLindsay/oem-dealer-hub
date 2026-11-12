<?php

namespace App\Filament\Resources\Manufacturers\RelationManagers;

use App\Filament\Resources\Users\UserResource;
use App\Models\Membership;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** The manufacturer's own people. Owners invite and remove them in the app. */
class StaffRelationManager extends RelationManager
{
    protected static string $relationship = 'staff';

    protected static ?string $title = 'Staff';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Name')->searchable(),
                TextColumn::make('user.email')->label('Email')->searchable(),
                TextColumn::make('role')->formatStateUsing(fn (string $state) => Str::headline($state)),
                IconColumn::make('two_factor')->label('Two-factor')->boolean()
                    ->state(fn (Membership $record) => $record->user->hasTwoFactor()),
                TextColumn::make('created_at')->label('Joined')->date('M j, Y'),
            ])
            ->recordUrl(fn (Membership $record) => UserResource::getUrl('view', ['record' => $record->user_id]));
    }
}
