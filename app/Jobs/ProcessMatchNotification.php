<?php

namespace App\Jobs;

use App\DataObjects\FantasyWeights;
use App\Enums\DestinationType;
use App\Models\Destination;
use App\Models\DotaMatch;
use App\Models\Hero;
use App\Models\Member;
use App\Models\Reminder;
use App\Services\FonnteService;
use App\Services\HeroService;
use App\Services\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class ProcessMatchNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(public DotaMatch $dotaMatch)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(FonnteService $fonnte, TelegramService $telegram, HeroService $heroService): void
    {
        try {
            // Get members and group by platform
            $memberIds = $this->dotaMatch->members;
            $members = Member::whereIn('id', $memberIds)->get();
            $groupedMembers = $members->groupBy('platform');
            $whatsappMembers = $groupedMembers->get(DestinationType::WhatsApp->value) ?? collect([]);
            $telegramMembers = $groupedMembers->get(DestinationType::Telegram->value) ?? collect([]);

            $whatsappSuccess = false;
            $telegramSuccess = false;

            // Send to WhatsApp if any members use WhatsApp
            if ($whatsappMembers->isNotEmpty()) {
                try {
                    $phoneNumber = Destination::targetForCode(Destination::CODE_WHATSAPP);
                    $message = $this->formatMessage($whatsappMembers, 'whatsapp');
                    [$memberPlayers, $allMatchPlayers, $membersWon] = $this->buildMemberPlayers($whatsappMembers);
                    $matchData = $this->dotaMatch->match_data;
                    $ranking = $this->calculateFantasyRanking($matchData, $memberPlayers, $allMatchPlayers, 'whatsapp');
                    $imagePath = null;
                    if ($this->shouldSendChart($ranking, $membersWon)) {
                        $imagePath = $this->generateChartImage($ranking, $this->dotaMatch->match_id, 'whatsapp');
                    }

                    if ($imagePath) {
                        $whatsappSuccess = $fonnte->sendImage($phoneNumber, $imagePath, $message);

                        if (config('dota.message_enabled')) {
                            @unlink($imagePath);
                        }
                    } else {
                        $whatsappSuccess = $fonnte->sendMessage($phoneNumber, $message);
                    }

                    if ($whatsappSuccess) {
                        Log::info('WhatsApp notification sent successfully', [
                            'match_id' => $this->dotaMatch->match_id,
                            'with_chart' => $imagePath !== null,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('WhatsApp notification failed', [
                        'match_id' => $this->dotaMatch->match_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Send to Telegram if any members use Telegram
            if ($telegramMembers->isNotEmpty()) {
                try {
                    $message = $this->formatMessage($telegramMembers, 'telegram');
                    [$memberPlayers, $allMatchPlayers, $membersWon] = $this->buildMemberPlayers($telegramMembers);
                    $matchData = $this->dotaMatch->match_data;
                    $ranking = $this->calculateFantasyRanking($matchData, $memberPlayers, $allMatchPlayers, 'telegram');
                    $imagePath = null;
                    if ($this->shouldSendChart($ranking, $membersWon)) {
                        $imagePath = $this->generateChartImage($ranking, $this->dotaMatch->match_id, 'telegram');
                    }

                    if ($imagePath) {
                        $telegramSuccess = $telegram->sendPhoto($imagePath, $message);

                        if (config('dota.message_enabled')) {
                            @unlink($imagePath);
                        }
                    } else {
                        $telegramSuccess = $telegram->sendMessage($message);
                    }

                    if ($telegramSuccess) {
                        Log::info('Telegram notification sent successfully', [
                            'match_id' => $this->dotaMatch->match_id,
                            'with_chart' => $imagePath !== null,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Telegram notification failed', [
                        'match_id' => $this->dotaMatch->match_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Mark as notified if at least one platform succeeded
            if ($whatsappSuccess || $telegramSuccess) {
                $this->dotaMatch->update([
                    'notified_at' => now(),
                ]);

                Log::info('Match notification completed', [
                    'match_id' => $this->dotaMatch->match_id,
                    'whatsapp' => $whatsappSuccess,
                    'telegram' => $telegramSuccess,
                ]);

                $this->sendReminders($members, $fonnte, $telegram);
            } else {
                throw new \Exception('Failed to send notification to any platform');
            }
        } catch (\Exception $e) {
            Log::error('Match notification failed', [
                'match_id' => $this->dotaMatch->match_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Calculate retry delay with exponential backoff
     */
    public function backoff(): array
    {
        return [30, 120, 300]; // 30s, 2min, 5min
    }

    /**
     * Format the notification message
     */
    private function formatMessage(Collection $members, string $type = 'whatsapp'): string
    {
        $matchData = $this->dotaMatch->match_data;

        $members = $members->keyBy('steam_id');
        $heroes = Hero::all()->keyBy('hero_id');

        $radiantWin = $matchData['radiant_win'] ?? false;
        $gameMode = $this->getGameModeName($matchData['game_mode'] ?? null);
        $duration = $this->formatDuration($matchData['duration'] ?? 0);
        $radiantScore = $matchData['radiant_score'] ?? 0;
        $direScore = $matchData['dire_score'] ?? 0;
        $players = $matchData['players'] ?? [];

        // Find members in the match and determine if they won
        $memberPlayers = [];
        foreach ($players as $player) {
            $accountId = $player['account_id'] ?? null;

            if (! $accountId) {
                continue;
            }

            // Convert account ID to Steam 64-bit ID
            $steamId = Member::convertAccountIdToSteamId($accountId);

            if ($members->has($steamId)) {
                $playerSlot = $player['player_slot'] ?? 0;
                $isRadiant = $playerSlot < 128;
                $hero = $heroes->get($player['hero_id'] ?? 0);

                $memberPlayers[] = [
                    'member' => $members->get($steamId),
                    'hero_id' => $player['hero_id'] ?? 0,
                    'hero_name' => $hero?->localized_name ?? "Hero #{$player['hero_id']}",
                    'kills' => $player['kills'] ?? 0,
                    'deaths' => $player['deaths'] ?? 0,
                    'assists' => $player['assists'] ?? 0,
                    'is_radiant' => $isRadiant,
                    'hero_damage' => $player['hero_damage'] ?? 0,
                    'tower_damage' => $player['tower_damage'] ?? 0,
                    'hero_healing' => $player['hero_healing'] ?? 0,
                    'last_hits' => $player['last_hits'] ?? 0,
                    'net_worth' => $player['net_worth'] ?? 0,
                    'gold_per_min' => $player['gold_per_min'] ?? 0,
                    'xp_per_min' => $player['xp_per_min'] ?? 0,
                    'level' => $player['level'] ?? 0,
                    'benchmarks' => $player['benchmarks'] ?? [],
                ];
            }
        }

        if (empty($memberPlayers)) {
            throw new \Exception('No tracked members found in match');
        }

        // Determine if members won (all members should be on the same team for party)
        $firstMemberTeam = $memberPlayers[0]['is_radiant'];
        $membersWon = $firstMemberTeam === $radiantWin;
        $teamName = $firstMemberTeam ? 'Radiant' : 'Dire';

        // Build message with Telegram Markdown formatting (**bold**)
        $message = "🕹️ **{$gameMode}** __({$duration})__\n\n";

        $memberNames = array_map(fn ($p) => "**{$p['member']->name}**", $memberPlayers);
        $outcome = $membersWon ? 'won' : 'lost';
        $message .= implode(', ', $memberNames)." {$outcome} a game as {$teamName}\n\n";

        $message .= "**Radiant** {$radiantScore} ⚔️ {$direScore} **Dire**\n\n";

        foreach ($memberPlayers as $player) {
            $heroName = $player['hero_name'];

            if ($type === 'whatsapp') {
                $heroName = "{$player['hero_name']} | Lv. {$player['level']}";
            }

            $message .= "**{$player['member']->name}** __({$heroName})__ - {$player['kills']}/{$player['deaths']}/{$player['assists']}\n";
        }

        // Add highlights
        $highlights = $this->generateHighlights($matchData, $memberPlayers, $players);
        if (! empty($highlights)) {
            $message .= "\n🔥 Highlights\n";
            foreach ($highlights as $highlight) {
                $message .= "- {$highlight}\n";
            }
        }

        // Add Fantasy MVP (only if 2+ members of same destination type)
        $fantasyMVP = $this->generateFantasyMVP($matchData, $memberPlayers, $players, $type);
        if ($fantasyMVP) {
            $message .= "\n{$fantasyMVP}\n";
        }

        if ($type === 'telegram') {
            $message .= "\n**Match ID** — {$this->dotaMatch->match_id}";
        } else {
            $message .= "\n> Match Detail opendota.com/matches/{$this->dotaMatch->match_id}";
        }

        return trim($message);
    }

    /**
     * Generate weighted highlights from match data
     */
    private function generateHighlights(array $matchData, array $memberPlayers, array $allPlayers): array
    {
        // Define weights for different highlight types (higher = more impactful)
        $weights = [
            'kills' => 100,
            'kda' => 90,
            'hero_damage' => 70,
            'net_worth' => 60,
            'tower_damage' => 65,
            'gpm' => 50,
            'xpm' => 45,
            'assists' => 60,
            'support_assists' => 50,
            'deaths' => 80,
            'healing' => 40,
        ];

        // Define minimum thresholds to filter mediocre achievements
        $thresholds = [
            'kills' => 10,
            'deaths' => 3, // max deaths threshold
            'assists' => 15,
            'support_assists' => 15,
            'hero_damage' => 15000,
            'tower_damage' => 3000,
            'hero_healing' => 5000,
            'net_worth' => 15000,
            'gpm' => 500,
            'xpm' => 600,
            'kda' => 5.0,
            'first_blood' => 20, // seconds
        ];

        $highlights = [];
        $extraHighlights = [];

        // Extract all player stats for comparison
        $allKills = array_map(fn ($p) => $p['kills'] ?? 0, $allPlayers);
        $allDeaths = array_map(fn ($p) => $p['deaths'] ?? 0, $allPlayers);
        $allAssists = array_map(fn ($p) => $p['assists'] ?? 0, $allPlayers);
        $allHeroDamage = array_map(fn ($p) => $p['hero_damage'] ?? 0, $allPlayers);
        $allTowerDamage = array_map(fn ($p) => $p['tower_damage'] ?? 0, $allPlayers);
        $allHeroHealing = array_map(fn ($p) => $p['hero_healing'] ?? 0, $allPlayers);
        $allNetWorth = array_map(fn ($p) => $p['net_worth'] ?? 0, $allPlayers);
        $allGpm = array_map(fn ($p) => $p['gold_per_min'] ?? 0, $allPlayers);
        $allXpm = array_map(fn ($p) => $p['xp_per_min'] ?? 0, $allPlayers);
        $allLastHits = array_map(fn ($p) => $p['last_hits'] ?? 0, $allPlayers);

        $maxKills = max($allKills);
        $minDeaths = min($allDeaths);
        $maxAssists = max($allAssists);
        $maxHeroDamage = max($allHeroDamage);
        $maxTowerDamage = max($allTowerDamage);
        $maxHeroHealing = max($allHeroHealing);
        $maxNetWorth = max($allNetWorth);
        $maxGpm = max($allGpm);
        $maxXpm = max($allXpm);

        foreach ($memberPlayers as $player) {
            $heroName = $player['hero_name'];

            // Most kills
            if ($player['kills'] > 0 && $player['kills'] === $maxKills && $player['kills'] >= $thresholds['kills']) {
                $weight = $weights['kills'];
                // Bonus weight for exceptional kill counts
                if ($player['kills'] >= 20) {
                    $weight += 20;
                } elseif ($player['kills'] >= 15) {
                    $weight += 10;
                }
                $highlights[] = [
                    'text' => "{$heroName} secured {$player['kills']} kills",
                    'weight' => $weight,
                ];
            }

            // Fewest deaths (minimum 1 kill)
            if ($player['kills'] >= 1 && $player['deaths'] === $minDeaths && $player['deaths'] <= $thresholds['deaths']) {
                $deathText = "{$heroName} had only {$player['deaths']} deaths";

                if ($player['deaths'] === 0 && $player['kills'] >= 1) {
                    $randomTexts = [
                        "{$heroName} sakti blas ora mati",
                        "{$heroName} main aman dengan 0 death",
                        "{$heroName} hoki ora mati",
                        "{$heroName} untouchable",
                        "{$heroName} genah 100% KS",
                        "{$heroName} ternyata anak CEO",
                    ];

                    $deathText = collect($randomTexts)->random();
                } elseif ($player['deaths'] === 1) {
                    $deathText = "{$heroName} had only 1 death";
                }

                $highlights[] = [
                    'text' => $deathText,
                    'weight' => $weights['deaths'],
                ];
                $extraHighlights[] = $deathText;
            }

            // Highest KDA
            $kda = $player['deaths'] > 0
                ? round(($player['kills'] + $player['assists']) / $player['deaths'], 2)
                : ($player['kills'] + $player['assists']);

            $allKdas = array_map(function ($p) {
                $deaths = ($p['deaths'] ?? 0) > 0 ? $p['deaths'] : 1;

                return (($p['kills'] ?? 0) + ($p['assists'] ?? 0)) / $deaths;
            }, $allPlayers);
            $maxKda = max($allKdas);

            if ($kda > 0 && abs($kda - $maxKda) < 0.01 && $kda >= $thresholds['kda']) {
                $weight = $weights['kda'];
                // Bonus weight for exceptional KDA
                if ($kda >= 10) {
                    $weight += 15;
                } elseif ($kda >= 7) {
                    $weight += 10;
                }
                $highlights[] = [
                    'text' => "{$heroName} achieved {$kda} KDA",
                    'weight' => $weight,
                ];
            }

            // Highest GPM
            if ($player['gold_per_min'] > 0 && $player['gold_per_min'] === $maxGpm && $player['gold_per_min'] >= $thresholds['gpm']) {
                $highlights[] = [
                    'text' => "{$heroName} farmed at {$player['gold_per_min']} GPM",
                    'weight' => $weights['gpm'],
                ];
            }

            // Highest XPM
            if ($player['xp_per_min'] > 0 && $player['xp_per_min'] === $maxXpm && $player['xp_per_min'] >= $thresholds['xpm']) {
                $highlights[] = [
                    'text' => "{$heroName} gained {$player['xp_per_min']} XPM",
                    'weight' => $weights['xpm'],
                ];
            }

            // Highest net worth
            if ($player['net_worth'] > 0 && $player['net_worth'] === $maxNetWorth && $player['net_worth'] >= $thresholds['net_worth']) {
                $highlights[] = [
                    'text' => "{$heroName} reached {$this->formatNumber($player['net_worth'])} net worth",
                    'weight' => $weights['net_worth'],
                ];
            }

            // Highest hero damage
            if ($player['hero_damage'] > 0 && $player['hero_damage'] === $maxHeroDamage && $player['hero_damage'] >= $thresholds['hero_damage']) {
                $highlights[] = [
                    'text' => "{$heroName} dealt {$this->formatNumber($player['hero_damage'])} hero damage",
                    'weight' => $weights['hero_damage'],
                ];
            }

            // Highest tower damage
            if ($player['tower_damage'] > 0 && $player['tower_damage'] === $maxTowerDamage && $player['tower_damage'] >= $thresholds['tower_damage']) {
                $highlights[] = [
                    'text' => "{$heroName} wrecked {$this->formatNumber($player['tower_damage'])} tower damage",
                    'weight' => $weights['tower_damage'],
                ];
            }

            // Most assists
            if ($player['assists'] > 0 && $player['assists'] === $maxAssists && $player['assists'] >= $thresholds['assists']) {
                $highlights[] = [
                    'text' => "{$heroName} contributed {$player['assists']} assists",
                    'weight' => $weights['assists'],
                ];
            } elseif ($player['assists'] >= $thresholds['support_assists'] && $player['last_hits'] < 50) {
                $highlights[] = [
                    'text' => "{$heroName} supported with {$player['assists']} assists",
                    'weight' => $weights['support_assists'],
                ];
            }

            // Highest hero healing
            if ($player['hero_healing'] > 0 && $player['hero_healing'] === $maxHeroHealing && $player['hero_healing'] >= $thresholds['hero_healing']) {
                $highlights[] = [
                    'text' => "{$heroName} healed {$this->formatNumber($player['hero_healing'])} HP",
                    'weight' => $weights['healing'],
                ];
            }
        }

        // Select top 3 highest weighted highlights
        $selectedHighlights = collect($highlights)
            ->sortByDesc('weight')
            ->take(6)
            ->shuffle()
            ->take(3)
            ->pluck('text');

        if (count($extraHighlights)) {
            $selectedHighlights = $selectedHighlights->merge(collect($extraHighlights))
                ->unique()
                ->shuffle();
        }

        // First blood timing (very fast for Turbo: ≤ 20 seconds)
        $firstBloodTime = $matchData['first_blood_time'] ?? null;
        if ($firstBloodTime !== null && $firstBloodTime <= 20 && $firstBloodTime > 0) {
            $selectedHighlights->prepend("First blood secured at {$firstBloodTime} seconds 🩸");
        }

        // Match duration note
        $duration = $matchData['duration'] ?? 0;
        if ($duration > 0) {
            $paceHighlights = collect([]);
            if ($duration < (18 * 60)) { // Less than 18 minutes
                $paceHighlights->push('Quick game finished in '.$this->formatDuration($duration).' ⏱️');
            } elseif ($duration > 2400) { // More than 40 minutes (long for Turbo)
                $paceHighlights->push('Epic game lasted '.$this->formatDuration($duration).' 🕰️');
            }

            if (count($paceHighlights)) {
                $randomPaceHL = $paceHighlights->random();

                $selectedHighlights->prepend($randomPaceHL);
            }
        }

        // Add humorous highlight for members with 0 kills
        $matiSelusinCount = 0;
        foreach ($memberPlayers as $player) {
            if ($player['kills'] === 0) {
                $heroName = $player['hero_name'];
                $zeroKillMessages = [
                    // "{$heroName} tutorial dulu biar bisa kill 📖",
                    "{$heroName} bola-bali di KS bolone 🐒",
                    // "{$heroName} makasih udah bantu musuh 🤝",
                    "{$heroName} killnya disave buat next match 🤡",
                    "{$heroName} cuma jalan-jalan di map 🚶",
                    "{$heroName} killnya invisible 💨",
                    "{$heroName} jualan telur nih? 🥚",
                    "{$heroName} mesakne ra entuk MBG 🍽️",
                ];

                $pantuns = [
                    'Ubur-ubur ikan lele, kontribusi lee 🐍',
                ];
                if (! $selectedHighlights->some(fn ($h) => in_array($h, $pantuns))) {
                    $zeroKillMessages = array_merge($zeroKillMessages, $pantuns);
                }

                $randomZeroKillMsg = collect($zeroKillMessages)->random();

                $selectedHighlights->push($randomZeroKillMsg);
            }

            if ($player['deaths'] >= 12) {
                $heroName = $player['hero_name'];
                $highDeathMessages = [
                    "{$heroName} mati selusin 👻",
                ];

                $randomHighDeathMsg = collect($highDeathMessages)->random();

                $selectedHighlights->push($randomHighDeathMsg);
                $matiSelusinCount++;
            }
        }

        if ($matiSelusinCount >= 2) {
            switch ($matiSelusinCount) {
                case 3:
                    $selectedHighlights->push('Triple mati selusin! 🥴');
                    break;
                case 4:
                    $selectedHighlights->push('Wayahe tuku Dota Plus, 4 mati selusin! 🤡');
                    break;
                case 5:
                    $selectedHighlights->push('Rampage mati selusin! 🤯');
                    break;
            }
        }

        return $selectedHighlights->all();
    }

    /**
     * Generate Fantasy MVP based on role-neutral hybrid scoring
     * Only shows MVP when 2+ members of the same destination type played together
     *
     * Algorithm: 7-component weighted scoring system
     * - 20% Kill Participation (team-relative)
     * - 15% KDA Normalized (capped at 1.0)
     * - 20% Hero Damage Share (team-relative)
     * - 10% Tower Damage Share (team-relative)
     * - 15% Healing Impact (hybrid: team share + benchmark)
     * - 10% Economy Percentile (benchmark-based)
     * - 10% Efficiency Score (damage + healing per net worth)
     *
     * @param  array  $matchData  Complete match data from OpenDota
     * @param  array  $memberPlayers  Array of tracked member players with benchmarks
     * @param  array  $allPlayers  All players in the match
     * @param  string  $destinationType  'whatsapp' or 'telegram' - compares only within same platform
     * @return string|null Formatted MVP string or null if insufficient members
     */
    private function generateFantasyMVP(array $matchData, array $memberPlayers, array $allPlayers, string $destinationType): ?string
    {
        $playerRanking = $this->calculateFantasyRanking($matchData, $memberPlayers, $allPlayers, $destinationType);

        if ($playerRanking->isEmpty()) {
            return null;
        }

        $radiantWin = $matchData['radiant_win'] ?? false;
        $bestPlayer = $playerRanking->first();
        $worstPlayer = $playerRanking->last();

        if (! $bestPlayer) {
            return null;
        }

        // Determine if MVP was on winning team
        $mvpWon = $bestPlayer['is_radiant'] === $radiantWin;
        $memberName = $bestPlayer['member']->name;
        $memberScore = round($bestPlayer['score'], 2);
        $heroName = $bestPlayer['hero_name'];

        if ($mvpWon) {
            $sections = [];
            $honorTitles = [
                ['👑 Fantasy MVP', '💬 _mention_'],
                ['🐮 _jabatan_ MBG', '🐐 _babu_ MBG'],
                ['🐖 _dukun_', '🧛🏻 _sekte_'],
                ['👨🏻‍💼 Cocok dadi PNS', '👷🏻 Pantese mung Swasta'],
                // ["😎 Ternyata Anak CEO", "🪦 Kalian semua saya pecat!"],
                ["😎 Ternyata Pewaris CEO", "👷🏻 Karyawan PT _nama_pt_"],
                ["💃🏻 Oleh Duwe Bojo 2", "🏳️ Bojo Siji Wae", ['telegram']],
            ];
            [$titleBest, $titleHonor] = collect($honorTitles)
                ->filter(function ($item) use ($destinationType) {
                    // Filter titles based on destination type
                    if (isset($item[2]) && is_array($item[2])) {
                        return in_array($destinationType, $item[2]);
                    }

                    return true;
                })->random();

            if (Str::contains($titleBest, '_jabatan_')) {
                $jabatan = ['Komisaris', 'Penggagas', 'Direktur', 'Chef'];
                $randomBest = collect($jabatan)->random();

                $titleBest = str_replace('_jabatan_', $randomBest, $titleBest);
            } elseif (Str::contains($titleBest, '_dukun_')) {
                $dukunTitles = ['Dukun Pesugihan', 'Pemimpin Sekte'];
                $randomBest = collect($dukunTitles)->random();

                $titleBest = str_replace('_dukun_', $randomBest, $titleBest);
            }

            if (Str::contains($titleHonor, '_mention_')) {
                $mentionTitles = ['Honorable Mention', 'Mlebu TV', 'Ameh MVP'];
                $randomHonor = collect($mentionTitles)->random();

                $titleHonor = str_replace('_mention_', $randomHonor, $titleHonor);
            } elseif (Str::contains($titleHonor, '_babu_')) {
                $babuTitles = ['Staff Dapur', 'Isah-isah Piring', 'Icip-icip'];
                $randomHonor = collect($babuTitles)->random();

                $titleHonor = str_replace('_babu_', $randomHonor, $titleHonor);
            } elseif (Str::contains($titleHonor, '_sekte_')) {
                $sekteNames = ['Pengikut Sekte', 'Tumbal Sekte', 'Tumbal Pesugihan'];
                $randomHonor = collect($sekteNames)->random();

                $titleHonor = str_replace('_sekte_', $randomHonor, $titleHonor);
            } elseif (Str::contains($titleHonor, '_nama_pt_')) {
                $namaPts = ['Yu Xian Chuox', 'Xianxu', 'ZHANG'];
                $randomHonor = collect($namaPts)->random();

                $titleHonor = str_replace('_nama_pt_', $randomHonor, $titleHonor);
            }

            $sections[] = "{$titleBest}\n- {$memberName} __({$heroName} | {$memberScore} pts)__ ";

            $honors = [];
            foreach ($playerRanking->sortByDesc('score') as $player) {
                // Limit 2 honorable mentions
                if (count($honors) >= 2) {
                    break;
                } elseif ($player['member']->id === $bestPlayer['member']->id) {
                    continue; // Skip MVP player
                }

                $honorScore = round($player['score'], 2);
                $honors[] = "- {$player['member']->name} __({$player['hero_name']} | {$honorScore} pts)__";
            }

            if (count($honors)) {
                $section = "{$titleHonor}\n";
                $section .= implode("\n", $honors);

                $sections[] = $section;
            }

            return implode("\n\n", $sections);
        } else {
            $sections = [];
            $losingTitles = [
                ["🤕 Tulang Punggung", "🥴 Tulang Tulung"],
                ["🤒 Sing Gendong", "🩼 Sing Digendong"],
                ["🪜 Calon MVP", "🪑 MVP — __Most Vulnerable Player__"],
                ["🫡 Wani Maju War", "🙄 Perlu Bimbingan"],
                ["🧗🏻 Kerjo Tenanan", "🥴 Kurang Kerjaan"],
            ];
            [$titleBest, $titleWorst] = collect($losingTitles)->random();

            $sections[] = "{$titleBest}\n- {$memberName} __({$heroName} | {$memberScore} pts)__ ";

            if ($playerRanking->count() > 2 && $worstPlayer) {
                $worstScore = round($worstPlayer['score'], 2);

                $sections[] = "{$titleWorst}\n- {$worstPlayer['member']->name} __({$worstPlayer['hero_name']} | {$worstScore} pts)__";
            }

            return implode("\n\n", $sections);
        }
    }

    /**
     * Build member player data for a given collection of platform-specific members.
     *
     * @return array{array, array, bool} [$memberPlayers, $allPlayers, $membersWon]
     */
    private function buildMemberPlayers(Collection $platformMembers): array
    {
        $matchData = $this->dotaMatch->match_data;
        $membersKeyed = $platformMembers->keyBy('steam_id');
        $heroes = Hero::all()->keyBy('hero_id');
        $allPlayers = $matchData['players'] ?? [];
        $radiantWin = $matchData['radiant_win'] ?? false;

        $memberPlayers = [];
        foreach ($allPlayers as $player) {
            $accountId = $player['account_id'] ?? null;
            if (! $accountId) {
                continue;
            }

            $steamId = Member::convertAccountIdToSteamId($accountId);
            if ($membersKeyed->has($steamId)) {
                $playerSlot = $player['player_slot'] ?? 0;
                $isRadiant = $playerSlot < 128;
                $hero = $heroes->get($player['hero_id'] ?? 0);

                $memberPlayers[] = [
                    'member' => $membersKeyed->get($steamId),
                    'hero_id' => $player['hero_id'] ?? 0,
                    'hero_name' => $hero?->localized_name ?? "Hero #{$player['hero_id']}",
                    'kills' => $player['kills'] ?? 0,
                    'deaths' => $player['deaths'] ?? 0,
                    'assists' => $player['assists'] ?? 0,
                    'is_radiant' => $isRadiant,
                    'hero_damage' => $player['hero_damage'] ?? 0,
                    'tower_damage' => $player['tower_damage'] ?? 0,
                    'hero_healing' => $player['hero_healing'] ?? 0,
                    'last_hits' => $player['last_hits'] ?? 0,
                    'net_worth' => $player['net_worth'] ?? 0,
                    'gold_per_min' => $player['gold_per_min'] ?? 0,
                    'xp_per_min' => $player['xp_per_min'] ?? 0,
                    'level' => $player['level'] ?? 0,
                    'benchmarks' => $player['benchmarks'] ?? [],
                ];
            }
        }

        $membersWon = ! empty($memberPlayers) && ($memberPlayers[0]['is_radiant'] === $radiantWin);

        return [$memberPlayers, $allPlayers, $membersWon];
    }

    /**
     * Calculate and rank eligible players by fantasy score.
     *
     * Returns a Collection sorted by score descending. Each item contains:
     * - member: Member model
     * - hero_name: string
     * - is_radiant: bool
     * - score: float (0–100 total weighted score)
     * - components: array<string, float> (7 raw component values, each 0–1)
     */
    private function calculateFantasyRanking(array $matchData, array $memberPlayers, array $allPlayers, string $destinationType): SupportCollection
    {
        $eligiblePlayers = collect($memberPlayers)->filter(
            fn ($player) => $player['member']->platform === $destinationType
        );

        if ($eligiblePlayers->count() < 2) {
            return collect();
        }

        $weights = FantasyWeights::weights();
        $teamStats = $this->calculateTeamStats($allPlayers, $matchData);

        // Calculate max efficiency per team (all 5 players on the same side, not just eligible group)
        $teamMaxEfficiency = ['radiant' => 0.0, 'dire' => 0.0];
        foreach ($allPlayers as $teamPlayer) {
            $isTeamRadiant = ($teamPlayer['player_slot'] ?? 0) < 128;
            $teamKey = $isTeamRadiant ? 'radiant' : 'dire';
            $teamMaxEfficiency[$teamKey] = max($teamMaxEfficiency[$teamKey], $this->calculatePlayerEfficiency($teamPlayer));
        }

        $ranking = collect();

        foreach ($eligiblePlayers as $player) {
            $benchmarks = $player['benchmarks'] ?? [];
            $isRadiant = $player['is_radiant'];
            $teamKey = $isRadiant ? 'radiant' : 'dire';

            // Component 1: Kill Participation
            $teamKills = $isRadiant
                ? ($matchData['radiant_score'] ?? 0)
                : ($matchData['dire_score'] ?? 0);
            $killParticipation = $teamKills > 0
                ? min(($player['kills'] + $player['assists']) / $teamKills, 1.0)
                : 0;

            // Component 2: KDA Normalized
            $kdaNormalized = min(
                ($player['kills'] + $player['assists']) / (($player['deaths'] ?? 0) + 1),
                10
            ) / 10;

            // Component 3: Hero Damage Share
            $teamHeroDamage = $teamStats[$teamKey]['hero_damage'];
            $heroDamageShare = $teamHeroDamage > 0
                ? min(($player['hero_damage'] ?? 0) / $teamHeroDamage, 1.0)
                : 0;

            // Component 4: Tower Damage Share
            $teamTowerDamage = $teamStats[$teamKey]['tower_damage'];
            $towerDamageShare = $teamTowerDamage > 0
                ? min(($player['tower_damage'] ?? 0) / $teamTowerDamage, 1.0)
                : 0;

            // Component 5: Healing Impact
            $teamHealing = $teamStats[$teamKey]['hero_healing'];
            $playerHealing = $player['hero_healing'] ?? 0;
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

            // Component 6: Economy Percentile
            $gpmPct = $benchmarks['gold_per_min']['pct'] ?? null;
            $xpmPct = $benchmarks['xp_per_min']['pct'] ?? null;
            $economyPercentile = 0;
            if ($gpmPct !== null && $xpmPct !== null) {
                $economyPercentile = ($gpmPct + $xpmPct) / 2;
            } elseif ($gpmPct !== null) {
                $economyPercentile = $gpmPct;
            } elseif ($xpmPct !== null) {
                $economyPercentile = $xpmPct;
            }

            // Component 7: Efficiency Score
            $efficiency = $this->calculatePlayerEfficiency($player);
            $maxEfficiency = $teamMaxEfficiency[$teamKey] ?: 1;
            $efficiencyScore = $maxEfficiency > 0 ? $efficiency / $maxEfficiency : 0;

            $score = (
                $killParticipation * $weights['kill_participation']
                + $kdaNormalized * $weights['kda_normalized']
                + $heroDamageShare * $weights['hero_damage_share']
                + $towerDamageShare * $weights['tower_damage_share']
                + $healingImpact * $weights['healing_impact']
                + $economyPercentile * $weights['economy_percentile']
                + $efficiencyScore * $weights['efficiency_score']
            ) * 100;

            $ranking->push([
                'member' => $player['member'],
                'hero_name' => $player['hero_name'],
                'is_radiant' => $isRadiant,
                'score' => $score,
                'components' => [
                    'kill_participation' => $killParticipation,
                    'hero_damage_share' => $heroDamageShare,
                    'tower_damage_share' => $towerDamageShare,
                    'healing_impact' => $healingImpact,
                    'kda_normalized' => $kdaNormalized,
                    'efficiency_score' => $efficiencyScore,
                    'economy_percentile' => $economyPercentile,
                ],
            ]);
        }

        return $ranking->sortByDesc('score')->values();
    }

    /**
     * Determine whether a radar chart should be generated and sent.
     *
     * Conditions: match won + ≥2 ranked players + both top-2 scores ≥ 50 + gap ≤5.
     */
    private function shouldSendChart(SupportCollection $ranking, bool $membersWon): bool
    {
        if (! $membersWon || $ranking->count() < 2) {
            return false;
        }

        $top1 = $ranking->get(0)['score'];
        $top2 = $ranking->get(1)['score'];

        return $top1 >= 50 && $top2 >= 50 && ($top1 - $top2) <= 5;
    }

    /**
     * Generate a radar chart PNG comparing the top 2 players' component scores.
     *
     * Returns the absolute path to the generated file, or null on failure.
     */
    private function generateChartImage(SupportCollection $ranks, string $matchId, string $destination): ?string
    {
        if (! config('dota.message_enabled')) {
            $destination .= "_".now()->timestamp;
        }

        $outputPath = storage_path("app/private/mvp-radars/{$matchId}_{$destination}.png");

        $rankMeta = $ranks->reduce(function ($carry, $item) {
            $heroDamageShare = $item['components']['hero_damage_share'] ?? 0;
            $towerDamageShare = $item['components']['tower_damage_share'] ?? 0;
            $healingImpact = $item['components']['healing_impact'] ?? 0;

            if ($heroDamageShare > $carry['max_hero_damage']) {
                $carry['max_hero_damage'] = $heroDamageShare;
            }

            if ($towerDamageShare > $carry['max_tower_damage']) {
                $carry['max_tower_damage'] = $towerDamageShare;
            }

            if ($healingImpact > $carry['max_healing_impact']) {
                $carry['max_healing_impact'] = $healingImpact;
            }

            return $carry;
        }, [
            'max_hero_damage' => 0,
            'max_tower_damage' => 0,
            'max_healing_impact' => 0,
        ]);

        $maxScore = $ranks->max('score');
        $topPlayers = $ranks->where('score', '>=', $maxScore - 5)
            ->where('score', '>=', 50);

        $players = $topPlayers->map(function (array $player) use ($rankMeta) {
            $score = round($player['score'], 2);
            $components = [];

            foreach ($player['components'] as $key => $value) {
                // Normalize hero/tower damage shares to the max among top 2 for better chart scaling
                if (in_array($key, ['hero_damage_share', 'tower_damage_share', 'healing_impact'])) {
                    $maxValue = [
                        'hero_damage_share' => $rankMeta['max_hero_damage'],
                        'tower_damage_share' => $rankMeta['max_tower_damage'],
                        'healing_impact' => $rankMeta['max_healing_impact'],
                    ][$key];

                    $normalizedValue = $maxValue > 0 ? ($value / $maxValue) : 0;
                    $components[$key] = round($normalizedValue, 2); // Scale to percentage

                    continue;
                }

                $components[$key] = round($value, 2); // Scale to percentage
            }

            return [
                'name' => "{$player['hero_name']} ({$score})",
                'scores' => array_values($components),
            ];
        })->values()->all();

        $payload = json_encode([
            'outputPath' => $outputPath,
            'players' => $players,
            'weights' => array_values(FantasyWeights::weights()),
        ]);

        $result = Process::path(base_path())
            ->input($payload)
            ->run(['node', 'resources/node/mvp-comparison.js']);

        if (! $result->successful()) {
            Log::error('Failed to generate MVP comparison chart', [
                'match_id' => $matchId,
                'error' => $result->errorOutput(),
            ]);

            return null;
        }

        return $outputPath;
    }

    /**
     * Calculate team-level statistics for both Radiant and Dire
     *
     * @param  array  $allPlayers  All 10 players in the match
     * @param  array  $matchData  Match metadata including scores
     * @return array Team stats with keys 'radiant' and 'dire'
     */
    private function calculateTeamStats(array $allPlayers, array $matchData): array
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
     * Calculate player efficiency: (hero_damage + hero_healing × 2) / net_worth
     * Healing is weighted 2× to account for support players having less gold
     *
     * @param  array  $player  Player stats array
     * @return float Efficiency score
     */
    private function calculatePlayerEfficiency(array $player): float
    {
        $netWorth = max($player['net_worth'] ?? 1, 1);
        $heroDamage = $player['hero_damage'] ?? 0;
        $heroHealing = $player['hero_healing'] ?? 0;

        return ($heroDamage + ($heroHealing * 1)) / $netWorth;
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
                return (int) $formatted.'k';
            }

            return $formatted.'k';
        }

        return (string) $number;
    }

    /**
     * Get game mode name from game mode ID
     */
    private function getGameModeName(?int $gameMode): string
    {
        $gameModes = [
            0 => 'Unknown',
            1 => 'All Pick',
            2 => 'Captains Mode',
            3 => 'Random Draft',
            4 => 'Single Draft',
            5 => 'All Random',
            6 => 'Intro',
            7 => 'The Diretide',
            8 => 'Reverse Captains Mode',
            9 => 'The Greeviling',
            10 => 'Tutorial',
            11 => 'Mid Only',
            12 => 'Least Played',
            13 => 'New Player Pool',
            14 => 'Compendium Matchmaking',
            15 => 'Custom',
            16 => 'Captains Draft',
            17 => 'Balanced Draft',
            18 => 'Ability Draft',
            19 => 'Event',
            20 => 'All Random Death Match',
            21 => '1v1 Mid',
            22 => 'All Pick Ranked',
            23 => 'Turbo',
        ];

        return $gameModes[$gameMode ?? 0] ?? 'Unknown';
    }

    /**
     * Format duration in minutes
     */
    private function formatDuration(int $seconds): string
    {
        $minutes = round($seconds / 60);

        return "{$minutes} minutes";
    }

    /**
     * Send play-too-much reminders to individual members via their personal WhatsApp number.
     */
    private function sendReminders(Collection $members, FonnteService $fonnte, TelegramService $telegram): void
    {
        $reminders = Reminder::query()
            ->whereIn('steam_id', $members->pluck('steam_id'))
            ->get()
            ->keyBy('steam_id');

        foreach ($members->unique('steam_id')->values() as $member) {
            $reminder = $reminders->get($member->steam_id);

            if (! $reminder) {
                continue;
            }

            $membersForReminder = Member::query()
                ->where('steam_id', $member->steam_id)
                ->get();

            if ($membersForReminder->isEmpty()) {
                continue;
            }

            try {
                // --- Max matches check ---
                $todayMatchCount = $this->getTodayMatchCountForMembers($membersForReminder);

                if ($todayMatchCount === $reminder->max_matches) {
                    $message = "Hei {$member->name}! Kamu udah main {$todayMatchCount} game DotA hari ini. "
                        ."Udahan deh, istirahat dulu — jaga kewarasan kamu! 🎮";

                    $this->sendReminderToMemberDestinations($membersForReminder, $message, $fonnte, $telegram, [
                        'today_matches' => $todayMatchCount,
                    ], 'Play reminder sent via');
                }

                // --- Late-night boundary breach check (>= 22:00 GMT+7) ---
                $matchHour = $this->dotaMatch->match_timestamp->timezone('Asia/Jakarta')->hour;

                if ($matchHour >= 22) {
                    $matchTime = $this->dotaMatch->match_timestamp->timezone('Asia/Jakarta')->format('H:i');
                    $message = "Hei {$member->name}! Match tadi mulai jam {$matchTime} — udah lewat jam 10 malem. "
                        ."Boundary breach detected! Istirahat dulu ya, besok masih bisa main lagi. 🌙";

                    $this->sendReminderToMemberDestinations($membersForReminder, $message, $fonnte, $telegram, [
                        'match_start_time' => $matchTime,
                    ], 'Late-night boundary breach reminder sent via');
                }
            } catch (\Exception $e) {
                Log::error('Failed to send play reminder', [
                    'member' => $member->name,
                    'steam_id' => $member->steam_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function getTodayMatchCountForMembers(Collection $members): int
    {
        $memberIds = $members->pluck('id');

        if ($memberIds->isEmpty()) {
            return 0;
        }

        return DotaMatch::query()
            ->whereDate('match_timestamp', today())
            ->where(function ($query) use ($memberIds) {
                foreach ($memberIds as $memberId) {
                    $query->orWhereJsonContains('members', $memberId);
                }
            })
            ->count();
    }

    private function sendReminderToMemberDestinations(
        Collection $members,
        string $message,
        FonnteService $fonnte,
        TelegramService $telegram,
        array $context,
        string $logPrefix,
    ): void {
        foreach ($members->unique('platform')->values() as $member) {
            $target = Destination::targetForCode($member->platform);

            if (! $target) {
                Log::warning('Reminder target not configured for member destination', [
                    'member' => $member->name,
                    'steam_id' => $member->steam_id,
                    'destination' => $member->platform,
                ]);

                continue;
            }

            if ($member->platform === Destination::CODE_WHATSAPP) {
                $fonnte->sendMessage($target, $message);

                Log::info("{$logPrefix} WhatsApp", array_merge($context, [
                    'member' => $member->name,
                    'steam_id' => $member->steam_id,
                    'phone' => $target,
                ]));
            }

            if ($member->platform === Destination::CODE_TELEGRAM) {
                $telegram->sendMessage($message, 'ai', $target);

                Log::info("{$logPrefix} Telegram", array_merge($context, [
                    'member' => $member->name,
                    'steam_id' => $member->steam_id,
                    'chat_id' => $target,
                ]));
            }
        }
    }
}
