<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Manufacturers\ManufacturerResource;
use App\Models\Manufacturer;
use App\Platform\Standings;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** How many accounts stand where, each linking to the filtered list. */
class AccountsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $stats = [];
        foreach (Standings::LABELS as $standing => $label) {
            $stats[] = Stat::make($label, Standings::where(Manufacturer::query(), $standing)->count())
                ->color(Standings::COLORS[$standing])
                ->url(ManufacturerResource::getUrl('index', ['filters' => ['standing' => ['value' => $standing]]]));
        }

        $stats[] = Stat::make('Signed up, last 30 days', Manufacturer::where('created_at', '>=', now()->subDays(30))->count());

        return $stats;
    }

    protected function getColumns(): int
    {
        return 5;
    }
}
