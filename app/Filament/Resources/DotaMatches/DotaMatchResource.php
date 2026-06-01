<?php

namespace App\Filament\Resources\DotaMatches;

use App\Filament\Resources\DotaMatches\Pages\ListDotaMatches;
use App\Filament\Resources\DotaMatches\Pages\ViewDotaMatch;
use App\Filament\Resources\DotaMatches\Schemas\DotaMatchInfolist;
use App\Filament\Resources\DotaMatches\Tables\DotaMatchesTable;
use App\Models\DotaMatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DotaMatchResource extends Resource
{
    protected static ?string $model = DotaMatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?string $navigationLabel = 'Matches';

    protected static ?string $recordTitleAttribute = 'match_id';

    public static function infolist(Schema $schema): Schema
    {
        return DotaMatchInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DotaMatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDotaMatches::route('/'),
            'view' => ViewDotaMatch::route('/{record}'),
        ];
    }
}
