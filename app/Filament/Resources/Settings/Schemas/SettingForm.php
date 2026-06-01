<?php

namespace App\Filament\Resources\Settings\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Setting Details')
                    ->schema([
                        TextInput::make('label')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Display name for this setting'),

                        TextInput::make('key')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText('Unique identifier (e.g., minimum_match_date, steam_news_enabled)')
                            ->disabled(fn ($record) => $record !== null),

                        TextInput::make('type')
                            ->default('string')
                            ->maxLength(255)
                            ->helperText('Data type (string, number, boolean, datetime, etc.)')
                            ->reactive(),

                        DateTimePicker::make('value')
                            ->label('Value')
                            ->helperText('The setting value (datetime)')
                            ->seconds(false)
                            ->visible(fn ($get) => $get('type') === 'datetime'),

                        Textarea::make('value')
                            ->required()
                            ->rows(3)
                            ->helperText(function ($get) {
                                $key = $get('key');

                                return match ($key) {
                                    'telegram_bot_token' => 'Deprecated: managed via Destinations (telegram.main_bot_token).',
                                    'telegram_bot_ai_token' => 'Deprecated: managed via Destinations (telegram.ai_bot_token).',
                                    'telegram_group_id' => 'Deprecated: managed via Destinations (telegram.target).',
                                    'fonnte_phone_number' => 'Deprecated: managed via Destinations (whatsapp.target).',
                                    'fonnte_api_key' => 'Deprecated: managed via Destinations (whatsapp.main_bot_token).',
                                    'minimum_match_date' => 'Minimum date for match checking (Y-m-d H:i:s format)',
                                    'steam_news_fetch_count' => 'Number of news items to fetch per hour (5-10 recommended)',
                                    'steam_news_enabled' => 'Enable (1) or disable (0) Steam news fetching',
                                    'last_steam_news_check' => 'Last Steam news check timestamp (automatically set)',
                                    default => 'The setting value',
                                };
                            })
                            ->visible(fn ($get) => $get('type') !== 'datetime'),

                        Textarea::make('description')
                            ->rows(2)
                            ->helperText('Optional description of what this setting controls'),
                    ]),
            ]);
    }
}
