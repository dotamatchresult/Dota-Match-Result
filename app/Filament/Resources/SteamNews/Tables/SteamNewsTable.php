<?php

namespace App\Filament\Resources\SteamNews\Tables;

use App\Jobs\ProcessNewsNotification;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TagsColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SteamNewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('gid')
                    ->label('GID')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->limit(20),

                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->wrap(),

                TextColumn::make('author')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('notified_at')
                    ->label('Notified')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state) => $state ? 'Sent' : 'Pending')
                    ->toggleable(),

                TagsColumn::make('tags')
                    ->toggleable()
                    ->limit(3),
            ])
            ->filters([
                TernaryFilter::make('notified_at')
                    ->label('Notification Status')
                    ->placeholder('All')
                    ->trueLabel('Notified')
                    ->falseLabel('Not Notified')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('notified_at'),
                        false: fn ($query) => $query->whereNull('notified_at'),
                    ),
            ])
            ->recordActions([
                Action::make('resend')
                    ->label('Resend Notification')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        ProcessNewsNotification::dispatch($record);
                    })
                    ->successNotificationTitle('Notification queued for sending'),

                Action::make('view_url')
                    ->label('View Online')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => $record->url)
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('published_at', 'desc');
    }
}
