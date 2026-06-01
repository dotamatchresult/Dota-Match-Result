<?php

namespace App\Filament\Resources\DotaMatches\Pages;

use App\Filament\Resources\DotaMatches\DotaMatchResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDotaMatch extends ViewRecord
{
    protected static string $resource = DotaMatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
