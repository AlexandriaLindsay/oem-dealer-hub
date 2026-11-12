<?php

namespace App\Filament\Support;

use App\Platform\RefusedException;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/** What every console button that changes an account shares: a reason, and a plain answer. */
final class Change
{
    /** Every change asks why, and the reason goes into the activity log. */
    public static function reason(): Textarea
    {
        return Textarea::make('note')
            ->label('Reason')
            ->helperText('Saved in the activity log with your name. Mention the ticket or email it came from.')
            ->required()
            ->minLength(5)
            ->maxLength(500)
            ->rows(2);
    }

    /**
     * Runs $change. If AccountTools refuses, staff see why and the form stays
     * open; nothing was changed.
     */
    public static function run(Action $action, callable $change, string $done): void
    {
        try {
            $change();
        } catch (RefusedException $e) {
            Notification::make()->title('Nothing was changed')->body($e->getMessage())->danger()->persistent()->send();
            $action->halt();
        }

        Notification::make()->title($done)->success()->send();
    }
}
