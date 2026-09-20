<?php

namespace App\Console\Commands;

use App\DataObjects\FantasyWeights;
use App\Enums\DestinationType;
use App\Models\Destination;
use App\Models\DotaMatch;
use App\Models\Hero;
use App\Models\Member;
use App\Models\Setting;
use App\Services\FonnteService;
use App\Services\HeroService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WeeklySummaryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'matches:weekly-summary';

    /** @var Collection<int, Hero> All heroes keyed by hero_id, loaded once per run. */
    private Collection $heroes;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send weekly DotA match summary to WhatsApp and Telegram';

    /**
     * Execute the console command.
     */
    public function handle(FonnteService $fonnte, TelegramService $telegram, HeroService $heroService): int
    {
        $this->writeLog();

        // Preload all heroes once to avoid N+1 queries in loops
        $this->heroes = Hero::all()->keyBy('hero_id');

        // Check if weekly summary is enabled
        if (! Setting::get('weekly_summary_enabled', true)) {
            $this->info('Weekly summary is disabled. Skipping...');

            return self::SUCCESS;
        }

        $this->info('Generating weekly summary...');

        // Get date range for previous week
        [$startDate, $endDate] = $this->getWeekDateRange();

        $this->info("Date range: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");

        // Get all matches from the week
        $matches = DotaMatch::whereBetween('match_timestamp', [$startDate, $endDate])
            ->whereNotNull('notified_at')
            ->orderBy('match_timestamp', 'desc')
            ->get();

        if ($matches->isEmpty()) {
            $this->info('No matches found for the week. Skipping...');

            return self::SUCCESS;
        }

        $this->info("Found {$matches->count()} matches");

        // Get all members grouped by destination
        $allMembers = Member::all();
        $groupedMembers = $allMembers->groupBy('destination');

        $whatsappMembers = $groupedMembers->get(DestinationType::WhatsApp->value) ?? collect();
        $telegramMembers = $groupedMembers->get(DestinationType::Telegram->value) ?? collect();

        // Send to WhatsApp if there are WhatsApp members
        if ($whatsappMembers->isNotEmpty()) {
            $this->info("Generating WhatsApp summary for {$whatsappMembers->count()} members...");

            $whatsappMatches = $this->filterMatchesForMembers($matches, $whatsappMembers);

            if ($whatsappMatches->isNotEmpty()) {
                $messages = [
                    $this->formatOverallSummary($whatsappMatches, $whatsappMembers, $heroService, $startDate, $endDate),
                    $this->formatWeeklyAwards($whatsappMatches, $whatsappMembers, $heroService),
                    $this->formatMemberSummaries($whatsappMatches, $whatsappMembers, $heroService),
                ];

                $this->sendToWhatsApp($fonnte, $messages);
            } else {
                $this->info('No WhatsApp member matches found. Skipping WhatsApp notification.');
            }
        }

        // Send to Telegram if there are Telegram members
        if ($telegramMembers->isNotEmpty()) {
            $this->info("Generating Telegram summary for {$telegramMembers->count()} members...");

            $telegramMatches = $this->filterMatchesForMembers($matches, $telegramMembers);

            if ($telegramMatches->isNotEmpty()) {
                $messages = [
                    $this->formatOverallSummary($telegramMatches, $telegramMembers, $heroService, $startDate, $endDate),
                    $this->formatWeeklyAwards($telegramMatches, $telegramMembers, $heroService),
                    $this->formatMemberSummaries($telegramMatches, $telegramMembers, $heroService),
                ];

                $this->sendToTelegram($telegram, $messages);
            } else {
                $this->info('No Telegram member matches found. Skipping Telegram notification.');
            }
        }

        $this->info('Weekly summary completed successfully!');

        return self::SUCCESS;
    }

    /**
     * Get date range for previous week (Monday to Sunday)
     */
    private function getWeekDateRange(): array
    {
        $endDate = now()->previous(Carbon::SUNDAY)->endOfDay();
        $startDate = $endDate->copy()->startOfWeek(Carbon::MONDAY);

        return [$startDate, $endDate];
    }

    /**
     * Filter matches to only include those where at least one member from the collection participated
     */
    private function filterMatchesForMembers(Collection $matches, Collection $members): Collection
    {
        $memberIds = $members->pluck('id')->toArray();

        return $matches->filter(function ($match) use ($memberIds) {
            $matchMembers = $match->members ?? [];

            return ! empty(array_intersect($matchMembers, $memberIds));
        });
    }

    /**
     * Format the overall weekly summary message
     */
    private function formatOverallSummary(Collection $matches, Collection $members, HeroService $heroService, Carbon $startDate, Carbon $endDate): string
    {
        $memberIds = $members->pluck('id')->toArray();

        // Calculate basic stats
        $totalMatches = $matches->count();
        $wins = $matches->filter(fn ($m) => $m->outcome === 'Won')->count();
        $losses = $totalMatches - $wins;
        $winRate = $totalMatches > 0 ? round(($wins / $totalMatches) * 100, 1) : 0;

        // Calculate average duration
        $totalDuration = $matches->sum(fn ($m) => $m->match_data['duration'] ?? 0);
        $avgDuration = $totalMatches > 0 ? round($totalDuration / $totalMatches) : 0;

        // Calculate aggregate K/D/A and damage stats for members only
        $totalHeroDamage = 0;
        $totalTowerDamage = 0;
        $matchesKillsAvg = [];
        $matchesDeathsAvg = [];
        $matchesAssistsAvg = [];

        foreach ($matches as $match) {
            $totalKills = 0;
            $totalDeaths = 0;
            $totalAssists = 0;
            $memberCount = 0;

            foreach ($match->match_data['players'] ?? [] as $player) {
                $accountId = $player['account_id'] ?? null;

                if (! $accountId) {
                    continue;
                }

                $steamId = Member::convertAccountIdToSteamId($accountId);
                $member = $members->firstWhere('steam_id', $steamId);

                if ($member) {
                    $totalKills += $player['kills'] ?? 0;
                    $totalDeaths += $player['deaths'] ?? 0;
                    $totalAssists += $player['assists'] ?? 0;
                    $totalHeroDamage += $player['hero_damage'] ?? 0;
                    $totalTowerDamage += $player['tower_damage'] ?? 0;
                    $memberCount++;
                }
            }

            if ($memberCount > 0) {
                $matchesKillsAvg[] = $totalKills / $memberCount;
                $matchesDeathsAvg[] = $totalDeaths / $memberCount;
                $matchesAssistsAvg[] = $totalAssists / $memberCount;
            }
        }

        // Get ranked hero statistics
        $rankedHeroes = $this->getRankedHeroStats($matches, $members);
        $heroStats = $rankedHeroes->sortByDesc('picks')->first();
        $highestWinRateHero = $rankedHeroes->where('picks', '>=', 3)->sortByDesc('win_rate')->first();

        // Exclude highest win rate hero when finding lowest to prevent duplicates
        $lowestWinRateHero = $rankedHeroes
            ->where('picks', '>=', 3)
            ->when($highestWinRateHero, fn ($collection) => $collection
                ->where('hero_id', '!=', $highestWinRateHero['hero_id'])
                ->where('win_rate', '<', $highestWinRateHero['win_rate'])
            )
            ->sortBy('win_rate')
            ->first();

        // Format date range
        $dateRange = $this->formatDateRange($startDate, $endDate);
        $avgKills = ! $totalMatches ? 0 : round(collect($matchesKillsAvg)->avg(), 1);
        $avgDeaths = ! $totalMatches ? 0 : round(collect($matchesDeathsAvg)->avg(), 1);
        $avgAssists = ! $totalMatches ? 0 : round(collect($matchesAssistsAvg)->avg(), 1);

        // Build message
        $message = "📊 **RINGKASAN MINGGUAN**\n";
        $message .= "__({$dateRange})__\n\n";

        $message .= "📈 Statistik Tim\n";
        $message .= "- Total Pertandingan: {$totalMatches}\n";
        $message .= "- Win / Lose: {$wins} / {$losses} ({$winRate}% WR)\n";
        $message .= "- Rata-rata Durasi: {$this->formatDuration($avgDuration)}\n\n";

        $message .= "⚔️ Statistik Pertempuran\n";
        $message .= "- Avg. K/D/A: {$avgKills} / {$avgDeaths} / {$avgAssists}\n";
        $message .= "- Total Hero Damage: {$this->formatNumber($totalHeroDamage)}\n";
        $message .= "- Total Tower Damage: {$this->formatNumber($totalTowerDamage)}\n\n";

        $message .= "🦸 Statistik Hero\n";
        if ($heroStats) {
            $message .= "- Hero Paling Sering: {$heroStats['hero_name']} ({$heroStats['picks']} pick, {$heroStats['win_rate']}% WR)\n";
        }

        if ($highestWinRateHero) {
            $message .= "- Hero Menangan: {$highestWinRateHero['hero_name']} ({$highestWinRateHero['picks']} pick, {$highestWinRateHero['win_rate']}% WR)\n";
        }

        if ($lowestWinRateHero) {
            $message .= "- Hero Kolah Kalah: {$lowestWinRateHero['hero_name']} ({$lowestWinRateHero['picks']} pick, {$lowestWinRateHero['win_rate']}% WR)";
        }

        return trim($message);
    }

    /**
     * Format the weekly awards message
     */
    private function formatWeeklyAwards(Collection $matches, Collection $members, HeroService $heroService): string
    {
        $memberStats = [];

        // Calculate stats for each member
        foreach ($members as $member) {
            $stats = $this->calculateMemberStats($matches, $member);

            if ($stats['matches'] >= 5) {
                $memberStats[$member->id] = [
                    'member' => $member,
                    'stats' => $stats,
                    'fantasy_score' => $this->calculateFantasyScore($matches, $member),
                ];
            }
        }

        if (empty($memberStats)) {
            return "🏆 **PENGHARGAAN MINGGUAN**\n__(Minimum 5 pertandingan)__\n\nTidak ada member yang memenuhi kriteria minimum.";
        }

        // Find award winners
        $mvp = collect($memberStats)->sortByDesc('fantasy_score')->first();
        $winner = collect($memberStats)->sortByDesc('stats.avg_wins')->first();
        $loser = collect($memberStats)->sortBy('stats.avg_wins')->first();
        $economyLeader = collect($memberStats)->sortByDesc('stats.avg_gpm')->first();
        $towerDestroyer = collect($memberStats)->sortByDesc('stats.avg_tower_damage')->first();
        $fighter = collect($memberStats)->sortByDesc('stats.avg_hero_damage')->first();
        $mostConsistent = collect($memberStats)->sortByDesc('stats.kda')->first();
        $mostDeaths = collect($memberStats)->sortByDesc('stats.avg_deaths')->first();
        $bestHealer = collect($memberStats)->sortByDesc('stats.avg_hero_healing')->first();
        $bestFarmer = collect($memberStats)->sortByDesc('stats.avg_last_hits')->first();
        $mostDenies = collect($memberStats)->sortByDesc('stats.avg_denies')->first();
        $richest = collect($memberStats)->sortByDesc('stats.avg_net_worth')->first();

        // Build message
        $message = "🏆 **PENGHARGAAN MINGGUAN**\n";
        $message .= "__(Minimum 5 pertandingan)__\n\n";

        // MVP
        if ($mvp) {
            $message .= "👑 **MVP Minggu Ini** — {$mvp['member']->name}\n";
            $kda = number_format($mvp['stats']['kda'], 1);
            $gpm = round($mvp['stats']['avg_gpm']);
            $damage = $this->formatNumber($mvp['stats']['avg_hero_damage']);
            $fantasyScore = number_format($mvp['fantasy_score'], 1);
            $message .= "Fantasy Score: {$fantasyScore} (KDA {$kda}, GPM {$gpm}, Hero Damage {$damage})\n\n";
        }

        // Winner (most wins)
        if ($winner) {
            $message .= "🏅 **Hoki Menang Terus** — {$winner['member']->name}\n";
            $avgWins = number_format($winner['stats']['avg_wins'] * 100, 1);
            $message .= "Win Rate: {$avgWins}%\n\n";
        }

        // Loser (most losses)
        if ($loser) {
            $message .= "🤕 **Hari-hari Kolah Kalah** — {$loser['member']->name}\n";
            $avgWins = number_format($loser['stats']['avg_wins'] * 100, 1);
            $message .= "Win Rate: {$avgWins}%\n\n";
        }

        // Economy Leader
        if ($economyLeader) {
            $message .= "💰 **Pinter Golek Duit** — {$economyLeader['member']->name}\n";
            $gpm = round($economyLeader['stats']['avg_gpm']);
            $message .= "Avg. GPM: {$gpm}\n\n";
        }

        // Richest player
        if ($richest) {
            $message .= "💎 **Paling Sugih** — {$richest['member']->name}\n";
            $netWorth = $this->formatNumber((int) round($richest['stats']['avg_net_worth']));
            $message .= "Avg. Net Worth: {$netWorth} per game\n\n";
        }

        // Tower Destroyer
        if ($towerDestroyer) {
            $message .= "🏗️ **Rajin Nyicil Tower** — {$towerDestroyer['member']->name}\n";
            $damage = $this->formatNumber($towerDestroyer['stats']['avg_tower_damage']);
            $message .= "Avg. Tower Damage: {$damage}\n\n";
        }

        // Fighter
        if ($fighter) {
            $message .= "⚔️ **Pejuang Barbar** — {$fighter['member']->name}\n";
            $damage = $this->formatNumber($fighter['stats']['avg_hero_damage']);
            $message .= "Avg. Hero Damage: {$damage}\n\n";
        }

        // Most Consistent
        if ($mostConsistent) {
            $message .= "🛡️ **Paling Konsisten** — {$mostConsistent['member']->name}\n";
            $kda = number_format($mostConsistent['stats']['kda'], 1);
            $message .= "Avg. KDA: {$kda}\n\n";
        }

        // Most Deaths (anti-award)
        if ($mostDeaths) {
            $message .= "☠️ **Tumbal Favorit** — {$mostDeaths['member']->name}\n";
            $deaths = number_format($mostDeaths['stats']['avg_deaths'], 1);
            $message .= "Avg. Death: {$deaths} per game\n\n";
        }

        // Best Healer
        if ($bestHealer && $bestHealer['stats']['avg_hero_healing'] > 0) {
            $message .= "💉 **Mantri Dusun** — {$bestHealer['member']->name}\n";
            $healing = $this->formatNumber((int) round($bestHealer['stats']['avg_hero_healing']));
            $message .= "Avg. Healing: {$healing} per game\n\n";
        }

        // Best Farmer
        if ($bestFarmer) {
            $message .= "🌾 **Dota Kok Mung Farming?** — {$bestFarmer['member']->name}\n";
            $lastHits = number_format($bestFarmer['stats']['avg_last_hits'], 1);
            $message .= "Avg. Last Hits: {$lastHits} per game\n\n";
        }

        // Most Denies
        if ($mostDenies && $mostDenies['stats']['avg_denies'] > 0) {
            $message .= "🚫 **Sregep Deny Creep** — {$mostDenies['member']->name}\n";
            $denies = number_format($mostDenies['stats']['avg_denies'], 1);
            $message .= "Avg. Denies: {$denies} per game\n\n";
        }

        return trim($message);
    }

    /**
     * Format the individual member summaries message
     */
    private function formatMemberSummaries(Collection $matches, Collection $members, HeroService $heroService): string
    {
        $message = "👥 **RINGKASAN INDIVIDU**\n\n";

        $memberSummaries = [];

        foreach ($members as $member) {
            $stats = $this->calculateMemberStats($matches, $member);

            if ($stats['matches'] > 0) {
                $memberSummaries[] = [
                    'member' => $member,
                    'stats' => $stats,
                    'fantasy_score' => $this->calculateFantasyScore($matches, $member),
                ];
            }
        }

        if (empty($memberSummaries)) {
            return $message.'Tidak ada data member.';
        }

        // Sort members by fantasy score
        $memberSummaries = collect($memberSummaries)
            ->sortByDesc(fn ($s) => $s['fantasy_score'])
            ->values();

        foreach ($memberSummaries as $summary) {
            $member = $summary['member'];
            $stats = $summary['stats'];
            $fantasyScore = round($summary['fantasy_score'], 2);

            $winRate = $stats['matches'] > 0 ? round(($stats['wins'] / $stats['matches']) * 100, 1) : 0;
            $avgKills = number_format($stats['avg_kills'], 1);
            $avgDeaths = number_format($stats['avg_deaths'], 1);
            $avgAssists = number_format($stats['avg_assists'], 1);

            $message .= "**{$member->name}**\n";
            $message .= "- Fantasy Score: {$fantasyScore} pts\n";
            $message .= "- Total {$stats['matches']} pertandingan ({$winRate}% WR)\n";
            $message .= "- Avg. K/D/A: {$avgKills} / {$avgDeaths} / {$avgAssists}\n";

            if ($stats['most_played_hero']) {
                $message .= "- Hero favorit: {$stats['most_played_hero']}\n";
            }

            $message .= "\n";
        }

        return trim($message);
    }

    /**
     * Calculate aggregate stats for a member
     */
    private function calculateMemberStats(Collection $matches, Member $member): array
    {
        $stats = [
            'matches' => 0,
            'wins' => 0,
            'total_kills' => 0,
            'total_deaths' => 0,
            'total_assists' => 0,
            'total_gpm' => 0,
            'total_hero_damage' => 0,
            'total_tower_damage' => 0,
            'total_hero_healing' => 0,
            'total_last_hits' => 0,
            'total_denies' => 0,
            'total_net_worth' => 0,
            'hero_picks' => [],
            'avg_wins' => 0,
            'avg_kills' => 0,
            'avg_deaths' => 0,
            'avg_assists' => 0,
            'avg_gpm' => 0,
            'avg_hero_damage' => 0,
            'avg_tower_damage' => 0,
            'avg_hero_healing' => 0,
            'avg_last_hits' => 0,
            'avg_denies' => 0,
            'avg_net_worth' => 0,
            'kda' => 0,
            'most_played_hero' => null,
        ];

        foreach ($matches as $match) {
            // Check if this member participated
            if (! in_array($member->id, $match->members ?? [])) {
                continue;
            }

            $stats['matches']++;

            if ($match->outcome === 'Won') {
                $stats['wins']++;
            }

            // Find member's player data in match
            foreach ($match->match_data['players'] ?? [] as $player) {
                $accountId = $player['account_id'] ?? null;

                if (! $accountId) {
                    continue;
                }

                $steamId = Member::convertAccountIdToSteamId($accountId);

                if ($steamId === $member->steam_id) {
                    $stats['total_kills'] += $player['kills'] ?? 0;
                    $stats['total_deaths'] += $player['deaths'] ?? 0;
                    $stats['total_assists'] += $player['assists'] ?? 0;
                    $stats['total_gpm'] += $player['gold_per_min'] ?? 0;
                    $stats['total_hero_damage'] += $player['hero_damage'] ?? 0;
                    $stats['total_tower_damage'] += $player['tower_damage'] ?? 0;
                    $stats['total_hero_healing'] += $player['hero_healing'] ?? 0;
                    $stats['total_last_hits'] += $player['last_hits'] ?? 0;
                    $stats['total_denies'] += $player['denies'] ?? 0;
                    $stats['total_net_worth'] += $player['net_worth'] ?? 0;

                    $heroId = $player['hero_id'] ?? 0;

                    if ($heroId) {
                        $stats['hero_picks'][$heroId] = ($stats['hero_picks'][$heroId] ?? 0) + 1;
                    }

                    break;
                }
            }
        }

        // Calculate averages
        if ($stats['matches'] > 0) {
            $stats['avg_wins'] = $stats['wins'] / $stats['matches'];
            $stats['avg_kills'] = $stats['total_kills'] / $stats['matches'];
            $stats['avg_deaths'] = $stats['total_deaths'] / $stats['matches'];
            $stats['avg_assists'] = $stats['total_assists'] / $stats['matches'];
            $stats['avg_gpm'] = $stats['total_gpm'] / $stats['matches'];

            $stats['kda'] = ($stats['avg_kills'] + $stats['avg_assists']) / max(1, $stats['avg_deaths']);
            $stats['avg_hero_damage'] = $stats['total_hero_damage'] / $stats['matches'];
            $stats['avg_tower_damage'] = $stats['total_tower_damage'] / $stats['matches'];
            $stats['avg_hero_healing'] = $stats['total_hero_healing'] / $stats['matches'];
            $stats['avg_last_hits'] = $stats['total_last_hits'] / $stats['matches'];
            $stats['avg_denies'] = $stats['total_denies'] / $stats['matches'];
            $stats['avg_net_worth'] = $stats['total_net_worth'] / $stats['matches'];
        }

        // Get most played hero
        if (! empty($stats['hero_picks'])) {
            arsort($stats['hero_picks']);
            $mostPlayedHeroId = array_key_first($stats['hero_picks']);
            $hero = $this->heroes->get($mostPlayedHeroId);
            $stats['most_played_hero'] = $hero ? $hero->localized_name : "Hero #{$mostPlayedHeroId}";
        }

        return $stats;
    }

    /**
     * Calculate Fantasy Score for a member across all their matches.
     *
     * Uses the same 7-component team-relative algorithm as generateFantasyMVP so that
     * support players (high healing / assists, low GPM) have equal footing with carries.
     *
     * Components (mirrors getFantasyWeights):
     *  - Kill Participation  20% — (K+A) / team_kills
     *  - Hero Damage Share   20% — player_damage / team_damage
     *  - Healing Impact      15% — hybrid team share + benchmark percentile
     *  - KDA Normalised      15% — min((K+A)/(D+1), 10) / 10
     *  - Tower Damage Share  10% — player_tower / team_tower
     *  - Efficiency Score    10% — (damage + healing×2) / net_worth (normalised per match)
     *  - Economy Percentile  10% — average of GPM and XPM benchmark percentiles
     *
     * Returns 0.0 if the member has no qualifying matches.
     */
    private function calculateFantasyScore(Collection $matches, Member $member): float
    {
        $weights = FantasyWeights::weights();
        $totalScore = 0.0;
        $scoredMatches = 0;

        foreach ($matches as $match) {
            if (! in_array($member->id, $match->members ?? [])) {
                continue;
            }

            $allPlayers = $match->match_data['players'] ?? [];
            $teamStats = $this->calculateTeamStatsForMatch($allPlayers);

            // Find this member's player entry
            $playerData = null;
            foreach ($allPlayers as $player) {
                $accountId = $player['account_id'] ?? null;

                if (! $accountId) {
                    continue;
                }

                $steamId = Member::convertAccountIdToSteamId($accountId);

                if ($steamId === $member->steam_id) {
                    $playerData = $player;
                    break;
                }
            }

            if (! $playerData) {
                continue;
            }

            // Determine team
            $isRadiant = ($playerData['player_slot'] ?? 0) < 128;
            $teamKey = $isRadiant ? 'radiant' : 'dire';
            $benchmarks = $playerData['benchmarks'] ?? [];

            $score = 0.0;

            // Component 1: Kill Participation (20%)
            $teamKills = $isRadiant
                ? ($match->match_data['radiant_score'] ?? 0)
                : ($match->match_data['dire_score'] ?? 0);
            $killParticipation = $teamKills > 0
                ? min(($playerData['kills'] + $playerData['assists']) / $teamKills, 1.0)
                : 0;
            $score += $killParticipation * $weights['kill_participation'];

            // Component 2: KDA Normalised (15%)
            $kdaNormalized = min(
                ($playerData['kills'] + $playerData['assists']) / (($playerData['deaths'] ?? 0) + 1),
                10
            ) / 10;
            $score += $kdaNormalized * $weights['kda_normalized'];

            // Component 3: Hero Damage Share (20%)
            $teamHeroDamage = $teamStats[$teamKey]['hero_damage'];
            $heroDamageShare = $teamHeroDamage > 0
                ? min(($playerData['hero_damage'] ?? 0) / $teamHeroDamage, 1.0)
                : 0;
            $score += $heroDamageShare * $weights['hero_damage_share'];

            // Component 4: Tower Damage Share (10%)
            $teamTowerDamage = $teamStats[$teamKey]['tower_damage'];
            $towerDamageShare = $teamTowerDamage > 0
                ? min(($playerData['tower_damage'] ?? 0) / $teamTowerDamage, 1.0)
                : 0;
            $score += $towerDamageShare * $weights['tower_damage_share'];

            // Component 5: Healing Impact
            $teamHealing = $teamStats[$teamKey]['hero_healing'];
            $playerHealing = $playerData['hero_healing'] ?? 0;
            $enemyKey = $teamKey === 'radiant' ? 'dire' : 'radiant';
            $enemyDamageToTeam = $teamStats[$enemyKey]['hero_damage'];

            if ($teamHealing > 0) {
                $teamHealRatio = $teamHealing / max($enemyDamageToTeam, 1);
                $playerShare = $playerHealing / $teamHealing;
                $healingImpact = $playerShare * $teamHealRatio;
                $healingImpact = min($healingImpact, 1.0);
            } else {
                $healingImpact = 0;
            }
            $score += $healingImpact * $weights['healing_impact'];

            // Component 6: Economy Percentile (10%)
            $gpmPct = $benchmarks['gold_per_min']['pct'] ?? null;
            $xpmPct = $benchmarks['xp_per_min']['pct'] ?? null;

            if ($gpmPct !== null && $xpmPct !== null) {
                $economyPercentile = ($gpmPct + $xpmPct) / 2;
            } elseif ($gpmPct !== null) {
                $economyPercentile = $gpmPct;
            } elseif ($xpmPct !== null) {
                $economyPercentile = $xpmPct;
            } else {
                $economyPercentile = 0;
            }
            $score += $economyPercentile * $weights['economy_percentile'];

            // Component 7: Efficiency Score (10%) — normalised against same-team players only
            $teamMaxEfficiency = 0.0;
            foreach ($allPlayers as $p) {
                if ((($p['player_slot'] ?? 0) < 128) === $isRadiant) {
                    $teamMaxEfficiency = max($teamMaxEfficiency, $this->calculatePlayerEfficiency($p));
                }
            }
            $teamMaxEfficiency = $teamMaxEfficiency ?: 1;
            $efficiency = $this->calculatePlayerEfficiency($playerData);
            $score += ($efficiency / $teamMaxEfficiency) * $weights['efficiency_score'];

            $totalScore += $score * 100;
            $scoredMatches++;
        }

        return $scoredMatches > 0 ? $totalScore / $scoredMatches : 0.0;
    }

    /**
     * Calculate per-team hero_damage, tower_damage, and hero_healing totals for a single match.
     *
     * @param  array  $allPlayers  All player entries from match_data['players']
     * @return array{radiant: array, dire: array}
     */
    private function calculateTeamStatsForMatch(array $allPlayers): array
    {
        $stats = [
            'radiant' => ['hero_damage' => 0, 'tower_damage' => 0, 'hero_healing' => 0],
            'dire' => ['hero_damage' => 0, 'tower_damage' => 0, 'hero_healing' => 0],
        ];

        foreach ($allPlayers as $player) {
            $isRadiant = ($player['player_slot'] ?? 0) < 128;
            $teamKey = $isRadiant ? 'radiant' : 'dire';

            $stats[$teamKey]['hero_damage'] += $player['hero_damage'] ?? 0;
            $stats[$teamKey]['tower_damage'] += $player['tower_damage'] ?? 0;
            $stats[$teamKey]['hero_healing'] += $player['hero_healing'] ?? 0;
        }

        return $stats;
    }

    /**
     * Calculate player efficiency: (hero_damage + hero_healing × 2) / net_worth.
     * Healing is weighted 2× to account for support players having less gold.
     */
    private function calculatePlayerEfficiency(array $player): float
    {
        $netWorth = max($player['net_worth'] ?? 1, 1);
        $heroDamage = $player['hero_damage'] ?? 0;
        $heroHealing = $player['hero_healing'] ?? 0;

        return ($heroDamage + ($heroHealing * 1)) / $netWorth;
    }

    /**
     * Get ranked hero statistics for all heroes played by members.
     * Returns a collection that can be sorted/filtered for various rankings.
     *
     * @return Collection Collection of hero stats with keys: hero_id, hero_name, picks, wins, win_rate
     */
    private function getRankedHeroStats(Collection $matches, Collection $members): Collection
    {
        $heroPicks = [];

        // Collect raw statistics
        foreach ($matches as $match) {
            $won = $match->outcome === 'Won';

            foreach ($match->match_data['players'] ?? [] as $player) {
                $accountId = $player['account_id'] ?? null;

                if (! $accountId) {
                    continue;
                }

                $steamId = Member::convertAccountIdToSteamId($accountId);
                $member = $members->firstWhere('steam_id', $steamId);

                if ($member) {
                    $heroId = $player['hero_id'] ?? 0;

                    if ($heroId) {
                        if (! isset($heroPicks[$heroId])) {
                            $heroPicks[$heroId] = ['picks' => 0, 'wins' => 0];
                        }

                        $heroPicks[$heroId]['picks']++;

                        if ($won) {
                            $heroPicks[$heroId]['wins']++;
                        }
                    }
                }
            }
        }

        // Transform into collection with calculated win rates and hero names
        return collect($heroPicks)->map(function ($stats, $heroId) {
            $hero = $this->heroes->get($heroId);
            $winRate = $stats['picks'] > 0 ? round(($stats['wins'] / $stats['picks']) * 100, 1) : 0;

            return [
                'hero_id' => $heroId,
                'hero_name' => $hero ? $hero->localized_name : "Hero #{$heroId}",
                'picks' => $stats['picks'],
                'wins' => $stats['wins'],
                'win_rate' => $winRate,
            ];
        })->values();
    }

    /**
     * Send messages to WhatsApp
     */
    private function sendToWhatsApp(FonnteService $fonnte, array $messages): void
    {
        $phoneNumber = Destination::targetForCode(Destination::CODE_WHATSAPP);

        if (! $phoneNumber) {
            $this->warn('WhatsApp destination target not configured. Skipping WhatsApp notification.');
            Log::warning('WhatsApp destination target not configured for weekly summary');

            return;
        }

        $this->info('Mengirim ringkasan mingguan WhatsApp...');

        foreach ($messages as $index => $message) {
            try {
                $success = $fonnte->sendMessage($phoneNumber, $message);

                if ($success) {
                    $this->info('WhatsApp message '.($index + 1).' sent successfully');
                    Log::info('Weekly summary WhatsApp message '.($index + 1).' sent', ['message_number' => $index + 1]);
                } else {
                    $this->error('Failed to send WhatsApp message '.($index + 1));
                    Log::error('Failed to send weekly summary WhatsApp message '.($index + 1), ['message_number' => $index + 1]);
                }

                if ($index < count($messages) - 1) {
                    sleep(2); // Delay between messages
                }
            } catch (\Exception $e) {
                $this->error('Exception sending WhatsApp message '.($index + 1).": {$e->getMessage()}");
                Log::error('Exception sending weekly summary WhatsApp message '.($index + 1), ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Send messages to Telegram
     */
    private function sendToTelegram(TelegramService $telegram, array $messages): void
    {
        $groupId = Destination::targetForCode(Destination::CODE_TELEGRAM);

        if (! $groupId) {
            $this->warn('Telegram destination target not configured. Skipping Telegram notification.');
            Log::warning('Telegram destination target not configured for weekly summary');

            return;
        }

        $this->info('Mengirim ringkasan mingguan Telegram...');

        foreach ($messages as $index => $message) {
            try {
                $message = TelegramService::escapeMarkdownPreserveFormatting($message);
                $success = $telegram->sendMessage($message, 'ai');

                if ($success) {
                    $this->info('Telegram message '.($index + 1).' sent successfully');
                    Log::info('Weekly summary Telegram message '.($index + 1).' sent', ['message_number' => $index + 1]);
                } else {
                    $this->error('Failed to send Telegram message '.($index + 1));
                    Log::error('Failed to send weekly summary Telegram message '.($index + 1), ['message_number' => $index + 1]);
                }

                if ($index < count($messages) - 1) {
                    sleep(1); // Delay between messages
                }
            } catch (\Exception $e) {
                $this->error('Exception sending Telegram message '.($index + 1).": {$e->getMessage()}");
                Log::error('Exception sending weekly summary Telegram message '.($index + 1), ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Format large numbers with k notation
     */
    private function formatNumber(int $number): string
    {
        if ($number >= 1000) {
            $formatted = round($number / 1000, 1);

            // Remove .0 if it's a whole number
            if ($formatted == floor($formatted)) {
                return floor($formatted).'k';
            }

            return $formatted.'k';
        }

        return (string) $number;
    }

    /**
     * Format duration in minutes (Indonesian)
     */
    private function formatDuration(int $seconds): string
    {
        $minutes = round($seconds / 60);

        return "{$minutes} menit";
    }

    /**
     * Format date range in Indonesian
     */
    private function formatDateRange(Carbon $start, Carbon $end): string
    {
        $months = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $startDay = $start->day;
        $endDay = $end->day;
        $month = $months[$end->month];
        $year = $end->year;

        return "{$startDay}-{$endDay} {$month} {$year}";
    }

    /**
     * Write execution log
     */
    private function writeLog(): void
    {
        $lastRun = Storage::exists('weekly_summary.log')
            ? Storage::get('weekly_summary.log')
            : 'Never';

        $this->info("Last execution: {$lastRun}");

        Storage::put('weekly_summary.log', now()->toDateTimeString());
    }
}
