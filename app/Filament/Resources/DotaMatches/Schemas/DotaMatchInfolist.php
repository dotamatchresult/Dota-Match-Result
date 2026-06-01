<?php

namespace App\Filament\Resources\DotaMatches\Schemas;

use App\Models\Member;
use App\Services\HeroService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;

class DotaMatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Match Information')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Grid::make(1)
                                    ->schema([
                                        TextEntry::make('match_id')
                                            ->label('Match ID')
                                            ->copyable()
                                            ->copyMessage('Match ID copied!'),

                                        TextEntry::make('match_timestamp')
                                            ->label('Match Time')
                                            ->dateTime()
                                            ->placeholder('-'),
                                    ]),

                                TextEntry::make('team')
                                    ->label('Team')
                                    ->badge()
                                    ->color(fn ($record) => $record->team === 'Radiant' ? 'success' : 'danger'),

                                TextEntry::make('outcome')
                                    ->label('Outcome')
                                    ->badge()
                                    ->color(fn ($record) => $record->outcome === 'Won' ? 'success' : 'danger'),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextEntry::make('members')
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

                                TextEntry::make('resend_count')
                                    ->label('Resend Count')
                                    ->default(0),
                            ]),

                        Grid::make(3)
                            ->schema([
                                TextEntry::make('notified_at')
                                    ->label('First Notification')
                                    ->dateTime()
                                    ->placeholder('Not sent'),

                                TextEntry::make('last_resent_at')
                                    ->label('Last Resent')
                                    ->dateTime()
                                    ->placeholder('-'),

                                TextEntry::make('created_at')
                                    ->label('Created At')
                                    ->dateTime(),
                            ]),
                    ]),

                Section::make('Player Statistics')
                    ->schema([
                        TextEntry::make('match_data')
                            ->label('Players')
                            ->formatStateUsing(function ($record) {
                                return self::formatPlayerStats($record);
                            })
                            ->html(),
                    ]),

                Section::make('AI Analysis')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('parse_status')
                                    ->label('Parse Status')
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        'pending' => Color::Gray,
                                        'parsing' => Color::Blue,
                                        'parsed' => Color::Green,
                                        'failed' => Color::Red,
                                        default => Color::Gray,
                                    })
                                    ->formatStateUsing(fn ($state) => $state ? ucfirst($state) : 'Not Started')
                                    ->placeholder('Not Started'),

                                TextEntry::make('ai_analyzed_at')
                                    ->label('Analyzed At')
                                    ->dateTime()
                                    ->placeholder('-'),
                            ]),

                        TextEntry::make('ai_analysis')
                            ->label('Analysis')
                            ->formatStateUsing(fn ($state) => nl2br(e($state)))
                            ->html()
                            ->placeholder('No analysis available yet')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record->outcome === 'Lost'),
            ]);
    }

    private static function formatPlayerStats($record): string
    {
        $heroService = app(HeroService::class);
        $matchData = $record->match_data;
        $memberIds = $record->members;

        if (empty($matchData['players']) || empty($memberIds)) {
            return '<p class="text-gray-500">No player data available</p>';
        }

        $members = Member::whereIn('id', $memberIds)->get()->keyBy('steam_id');
        $html = '<table class="w-full text-sm"><thead><tr class="border-b border-gray-200 dark:border-gray-700">';
        $html .= '<th class="text-left py-2 px-2 font-semibold">Member</th>';
        $html .= '<th class="text-left py-2 px-2 font-semibold">Hero</th>';
        $html .= '<th class="text-center py-2 px-2 font-semibold">K</th>';
        $html .= '<th class="text-center py-2 px-2 font-semibold">D</th>';
        $html .= '<th class="text-center py-2 px-2 font-semibold">A</th>';
        $html .= '<th class="text-center py-2 px-2 font-semibold">KDA</th>';
        $html .= '</tr></thead><tbody>';

        $memberPlayers = [];
        foreach ($matchData['players'] as $player) {
            $accountId = $player['account_id'] ?? null;

            if (! $accountId) {
                continue;
            }

            $steamId = Member::convertAccountIdToSteamId($accountId);

            if ($members->has($steamId)) {
                $kills = $player['kills'] ?? 0;
                $deaths = $player['deaths'] ?? 0;
                $assists = $player['assists'] ?? 0;
                $kda = $deaths > 0 ? round(($kills + $assists) / $deaths, 2) : ($kills + $assists);

                $memberPlayers[] = [
                    'name' => $members->get($steamId)->name,
                    'hero' => $heroService->getHeroName($player['hero_id'] ?? 0),
                    'kills' => $kills,
                    'deaths' => $deaths,
                    'assists' => $assists,
                    'kda' => $kda,
                ];
            }
        }

        if (empty($memberPlayers)) {
            return '<p class="text-gray-500">No tracked members found in match</p>';
        }

        foreach ($memberPlayers as $player) {
            $html .= '<tr class="border-b border-gray-100 dark:border-gray-800">';
            $html .= '<td class="py-2 px-2 font-medium">'.$player['name'].'</td>';
            $html .= '<td class="py-2 px-2">'.$player['hero'].'</td>';
            $html .= '<td class="py-2 px-2 text-center">'.$player['kills'].'</td>';
            $html .= '<td class="py-2 px-2 text-center">'.$player['deaths'].'</td>';
            $html .= '<td class="py-2 px-2 text-center">'.$player['assists'].'</td>';
            $html .= '<td class="py-2 px-2 text-center font-semibold">'.$player['kda'].'</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }
}
