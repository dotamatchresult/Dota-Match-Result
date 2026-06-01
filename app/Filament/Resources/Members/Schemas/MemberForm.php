<?php

namespace App\Filament\Resources\Members\Schemas;

use App\Enums\DestinationType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Member Information')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('The alias or nickname for this member'),

                        TextInput::make('steam_id')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText('The Steam 64-bit ID (e.g., 76561198XXXXXXXXX)')
                            ->regex('/^7656119[0-9]{10}$/')
                            ->validationMessages([
                                'regex' => 'The Steam ID must be a valid 64-bit Steam ID starting with 7656119.',
                            ]),

                        Select::make('destination')
                            ->options([
                                DestinationType::WhatsApp->value => 'WhatsApp',
                                DestinationType::Telegram->value => 'Telegram',
                            ])
                            ->default(DestinationType::WhatsApp->value)
                            ->helperText('Choose where to send match notifications for this member'),
                    ]),
            ]);
    }
}
