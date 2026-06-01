<?php

namespace App\Filament\Resources\SteamNews\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SteamNewsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('News Details')
                    ->schema([
                        TextInput::make('gid')
                            ->label('GID')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('title')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),

                        TextInput::make('url')
                            ->label('URL')
                            ->url()
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),

                        TextInput::make('author')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('feedname')
                            ->label('Feed Name')
                            ->disabled()
                            ->dehydrated(false),

                        Placeholder::make('published_at')
                            ->label('Published At')
                            ->content(fn ($record) => $record ? $record->published_at->format('Y-m-d H:i:s') : '-'),

                        Placeholder::make('notified_at')
                            ->label('Notified At')
                            ->content(fn ($record) => $record && $record->notified_at ? $record->notified_at->format('Y-m-d H:i:s') : 'Not notified'),

                        TagsInput::make('tags')
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),

                        Textarea::make('contents')
                            ->label('Contents')
                            ->disabled()
                            ->dehydrated(false)
                            ->rows(10)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
