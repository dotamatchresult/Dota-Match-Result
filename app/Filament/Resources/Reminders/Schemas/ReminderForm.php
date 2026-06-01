<?php

namespace App\Filament\Resources\Reminders\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReminderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Reminder Configuration')
                    ->schema([
                        TextInput::make('steam_id')
                            ->label('Steam ID')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Tracked Steam 64-bit ID. This is not members.id.'),

                        TextInput::make('max_matches')
                            ->label('Max Matches')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Daily threshold used to trigger reminders.'),
                    ])
                    ->columns(2),
            ]);
    }
}
