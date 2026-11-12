<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Change;
use App\Models\User;
use App\Platform\AccountTools;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetTwoFactor')
                ->label('Reset two-factor')
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color('danger')
                ->visible(fn (User $record) => $record->hasTwoFactor() && ! $record->is(auth()->user()))
                ->modalHeading('Reset two-factor?')
                ->modalDescription('For someone who has lost their authenticator. It signs them out everywhere, and they set up a new authenticator at their next sign-in. Check it really is them first: ask from the email address on the account.')
                ->schema([Change::reason()])
                ->action(fn (array $data, User $record, Action $action) => Change::run(
                    $action,
                    fn () => AccountTools::resetTwoFactor($record, auth()->user(), $data['note']),
                    'Two-factor reset. They\'ll set it up again when they next sign in.',
                )),

            Action::make('sendPasswordReset')
                ->label('Send password reset')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->modalDescription(fn (User $record) => "Emails {$record->email} the same reset link as \"Forgot password\". You never see or set their password.")
                ->schema([Change::reason()])
                ->action(fn (array $data, User $record, Action $action) => Change::run(
                    $action,
                    fn () => AccountTools::sendPasswordReset($record, auth()->user(), $data['note']),
                    'Reset email sent',
                )),
        ];
    }
}
