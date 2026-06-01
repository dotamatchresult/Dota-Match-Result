<?php

namespace App\Filament\Resources\DotaMatches\Pages;

use App\Filament\Resources\DotaMatches\DotaMatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDotaMatches extends ListRecords
{
    protected static string $resource = DotaMatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction::make(),
        ];
    }
}
