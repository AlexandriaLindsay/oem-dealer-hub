<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Staff avatars drawn here as initials. Filament's default fetches them from
 * ui-avatars.com, which would send staff names to a third party.
 */
class InitialsAvatar implements AvatarProvider
{
    public function get(Model $record): string
    {
        $initials = Str::of((string) $record->getAttribute('name'))
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#3730a3"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="system-ui, sans-serif" font-size="26" font-weight="600" fill="#ffffff">'
            .e($initials)
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
