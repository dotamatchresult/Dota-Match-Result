<?php

namespace App\Filament\Resources\Destinations\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class DestinationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->badge(),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('target')
                    ->searchable()
                    ->copyable()
                    ->toggleable()
                    ->limit(40)
                    ->placeholder('-'),

                TextColumn::make('main_bot_token')
                    ->label('Main Token')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?string $state): string => self::maskToken($state)),

                TextColumn::make('ai_bot_token')
                    ->label('AI Token')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?string $state): string => self::maskToken($state)),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('code');
    }

    private static function maskToken(?string $value): string
    {
        if (! $value) {
            return '-';
        }

        $length = strlen($value);

        if ($length <= 10) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 6).str_repeat('*', $length - 10).substr($value, -4);
    }
}
