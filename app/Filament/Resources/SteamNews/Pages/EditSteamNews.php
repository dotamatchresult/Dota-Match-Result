<?php

namespace App\Filament\Resources\SteamNews\Pages;

use App\Filament\Resources\SteamNews\SteamNewsResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSteamNews extends EditRecord
{
    protected static string $resource = SteamNewsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
