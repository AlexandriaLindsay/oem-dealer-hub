<?php

namespace App\Filament\Resources\StaffActions;

use App\Filament\Resources\Manufacturers\ManufacturerResource;
use App\Filament\Resources\StaffActions\Pages\ListStaffActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\Manufacturer;
use App\Models\StaffAction;
use App\Platform\Standings;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/** Every change made in the console, newest first. Nobody can edit or delete it here. */
class StaffActionResource extends Resource
{
    protected static ?string $model = StaffAction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Activity';

    protected static ?string $modelLabel = 'activity entry';

    protected static ?string $pluralModelLabel = 'activity';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('M j, Y g:i A')->sortable(),
                TextColumn::make('staff_name')->label('Staff')->searchable(),
                TextColumn::make('action')->badge()
                    ->formatStateUsing(fn (string $state) => StaffAction::LABELS[$state] ?? $state),
                TextColumn::make('subject_label')->label('Account')->searchable()
                    ->description(fn (StaffAction $record) => $record->subject_type === 'manufacturer' ? 'Manufacturer' : 'Person'),
                TextColumn::make('change')
                    ->state(fn (StaffAction $record) => self::describe($record))
                    ->placeholder('-'),
                TextColumn::make('note')->label('Reason')->wrap()->searchable(),
            ])
            ->filters([
                SelectFilter::make('action')->options(StaffAction::LABELS),
            ])
            ->recordUrl(fn (StaffAction $record) => self::subjectUrl($record));
    }

    /** "Trial → Pro", from the before and after kept in details. */
    private static function describe(StaffAction $record): ?string
    {
        $details = $record->details ?? [];

        if (isset($details['plan']) && $record->action === 'set_plan') {
            return collect($details['plan'])->map(fn ($p) => Standings::PLAN_LABELS[$p] ?? $p)->implode(' → ');
        }
        if (isset($details['trial_ends_at'][1])) {
            return 'Trial until '.Carbon::parse($details['trial_ends_at'][1])->timezone('America/New_York')->format('M j, Y');
        }

        return null;
    }

    private static function subjectUrl(StaffAction $record): ?string
    {
        if ($record->subject_type === 'manufacturer') {
            $manufacturer = Manufacturer::find($record->subject_id);

            return $manufacturer ? ManufacturerResource::getUrl('view', ['record' => $manufacturer]) : null;
        }

        return UserResource::getUrl('view', ['record' => $record->subject_id]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffActions::route('/'),
        ];
    }
}
