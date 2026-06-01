<?php

namespace App\Filament\Resources\DotaMatches\Tables;

use App\Jobs\ProcessMatchNotification;
use App\Models\Member;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

class DotaMatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('match_id')
                    ->label('Match ID')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Match ID copied!'),

                TextColumn::make('match_timestamp')
                    ->label('Match Date')
                    ->formatStateUsing(function ($state) {
                        if (empty($state)) {
                            return '-';
                        }

                        return Date::parse($state)->translatedFormat('D, j M Y - H:i');
                    })
                    ->sortable(),

                TextColumn::make('members')
                    ->label('Members')
                    ->formatStateUsing(function ($state) {
                        if (empty($state)) {
                            return '-';
                        } elseif (! is_array($state)) {
                            $state = [$state];
                        }

                        $members = Member::whereIn('id', $state)->pluck('name');

                        return $members->join(', ');
                    }),

                TextColumn::make('team')
                    ->label('Team')
                    ->badge()
                    ->formatStateUsing(function ($record) {
                        return self::getTeam($record);
                    })
                    ->color(fn ($record) => self::getTeam($record) === 'Radiant' ? 'success' : 'danger'),

                TextColumn::make('outcome')
                    ->label('Outcome')
                    ->badge()
                    ->formatStateUsing(function ($record) {
                        return self::getOutcome($record);
                    })
                    ->color(fn ($record) => self::getOutcome($record) === 'Won' ? 'success' : 'danger'),

                TextColumn::make('resend_count')
                    ->label('Resends')
                    ->sortable()
                    ->default(0)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('notified_at')
                    ->label('First Sent')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Not sent')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_resent_at')
                    ->label('Last Resent')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('notified')
                    ->label('Notification Status')
                    ->options([
                        'sent' => 'Sent',
                        'pending' => 'Pending',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if ($data['value'] === 'sent') {
                            $query->whereNotNull('notified_at');
                        } elseif ($data['value'] === 'pending') {
                            $query->whereNull('notified_at');
                        }
                    }),

                SelectFilter::make('outcome')
                    ->label('Match Outcome')
                    ->options([
                        'won' => 'Won',
                        'lost' => 'Lost',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if ($data['value'] === 'won') {
                            $query->where(function ($q) {
                                $q->whereJsonContains('match_data->radiant_win', true);
                            });
                        } elseif ($data['value'] === 'lost') {
                            $query->where(function ($q) {
                                $q->whereJsonContains('match_data->radiant_win', false);
                            });
                        }
                    }),

                Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')
                            ->label('Created From'),
                        \Filament\Forms\Components\DatePicker::make('created_until')
                            ->label('Created Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('resend')
                    ->label('Resend WA')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Resend WhatsApp Notification')
                    ->modalDescription('Are you sure you want to resend the WhatsApp notification for this match?')
                    ->modalSubmitActionLabel('Yes, resend')
                    ->action(function ($record) {
                        $record->increment('resend_count');
                        $record->update(['last_resent_at' => now()]);

                        ProcessMatchNotification::dispatch($record);

                        Notification::make()
                            ->title('Notification Queued')
                            ->body('The WhatsApp notification has been queued for resending.')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private static function getTeam($record): string
    {
        $matchData = $record->match_data;
        $memberIds = $record->members;

        if (empty($matchData['players']) || empty($memberIds)) {
            return 'Unknown';
        }

        $members = Member::whereIn('id', $memberIds)->get()->keyBy('steam_id');

        foreach ($matchData['players'] as $player) {
            $accountId = $player['account_id'] ?? null;

            if (! $accountId) {
                continue;
            }

            $steamId = Member::convertAccountIdToSteamId($accountId);

            if ($members->has($steamId)) {
                $playerSlot = $player['player_slot'] ?? 0;
                $isRadiant = $playerSlot < 128;

                return $isRadiant ? 'Radiant' : 'Dire';
            }
        }

        return 'Unknown';
    }

    private static function getOutcome($record): string
    {
        $matchData = $record->match_data;
        $memberIds = $record->members;

        if (empty($matchData['players']) || empty($memberIds)) {
            return 'Unknown';
        }

        $radiantWin = $matchData['radiant_win'] ?? false;
        $members = Member::whereIn('id', $memberIds)->get()->keyBy('steam_id');

        foreach ($matchData['players'] as $player) {
            $accountId = $player['account_id'] ?? null;

            if (! $accountId) {
                continue;
            }

            $steamId = Member::convertAccountIdToSteamId($accountId);

            if ($members->has($steamId)) {
                $playerSlot = $player['player_slot'] ?? 0;
                $isRadiant = $playerSlot < 128;

                return ($isRadiant === $radiantWin) ? 'Won' : 'Lost';
            }
        }

        return 'Unknown';
    }
}
