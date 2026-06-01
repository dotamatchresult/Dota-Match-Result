<?php

namespace App\Filament\Resources\Destinations\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DestinationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Destination Configuration')
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(50)
                            ->alphaDash()
                            ->lowercase()
                            ->unique(ignoreRecord: true)
                            ->helperText('Unique destination code (e.g., whatsapp, telegram).'),

                        TextInput::make('name')
                            ->maxLength(255)
                            ->helperText('Display name for this destination.'),

                        TextInput::make('target')
                            ->maxLength(255)
                            ->helperText('Recipient target. Example: WhatsApp number/group or Telegram group ID.'),

                        TextInput::make('main_bot_token')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText('Primary provider token (Fonnte API key or Telegram bot token).'),

                        TextInput::make('ai_bot_token')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText('Optional AI-specific bot token (used by Telegram AI notifications).'),
                    ])
                    ->columns(2),
            ]);
    }
}
