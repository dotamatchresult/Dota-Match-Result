<?php

namespace App\Filament\Resources\Destinations;

use App\Filament\Resources\Destinations\Pages\CreateDestination;
use App\Filament\Resources\Destinations\Pages\EditDestination;
use App\Filament\Resources\Destinations\Pages\ListDestinations;
use App\Filament\Resources\Destinations\Schemas\DestinationForm;
use App\Filament\Resources\Destinations\Tables\DestinationsTable;
use App\Models\Destination;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DestinationResource extends Resource
{
    protected static ?string $model = Destination::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $navigationLabel = 'Destinations';

    protected static ?string $modelLabel = 'Destination';

    protected static ?string $pluralModelLabel = 'Destinations';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return DestinationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DestinationsTable::configure($table);
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
            'index' => ListDestinations::route('/'),
            'create' => CreateDestination::route('/create'),
            'edit' => EditDestination::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
