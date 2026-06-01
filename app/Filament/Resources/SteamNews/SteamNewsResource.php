<?php

namespace App\Filament\Resources\SteamNews;

use App\Filament\Resources\SteamNews\Pages\CreateSteamNews;
use App\Filament\Resources\SteamNews\Pages\EditSteamNews;
use App\Filament\Resources\SteamNews\Pages\ListSteamNews;
use App\Filament\Resources\SteamNews\Schemas\SteamNewsForm;
use App\Filament\Resources\SteamNews\Tables\SteamNewsTable;
use App\Models\SteamNews;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SteamNewsResource extends Resource
{
    protected static ?string $model = SteamNews::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $navigationLabel = 'Steam News';

    protected static ?string $modelLabel = 'Steam News';

    protected static ?string $pluralModelLabel = 'Steam News';

    public static function form(Schema $schema): Schema
    {
        return SteamNewsForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SteamNewsTable::configure($table);
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
            'index' => ListSteamNews::route('/'),
            'create' => CreateSteamNews::route('/create'),
            'edit' => EditSteamNews::route('/{record}/edit'),
        ];
    }
}
