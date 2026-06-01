<?php

namespace App\Filament\Resources\SteamNews\Pages;

use App\Filament\Resources\SteamNews\SteamNewsResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSteamNews extends ListRecords
{
    protected static string $resource = SteamNewsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
