<?php

namespace App\Services;

use App\Models\Hero;
use App\Models\Member;
use Illuminate\Support\Facades\Log;
use OpenAI\Contracts\ClientContract;

class OpenAiService
{
    public function __construct(
        protected ClientContract $client
    ) {}

    /**
     * Analyze defeat match using GPT-5.4-mini
     * Returns casual Bahasa Indonesia analysis with gaming slang
     */
    public function analyzeDefeat(array $matchData, array $memberSteamIds): ?string
    {
        try {
            // Extract member hero names and determine team
            $heroes = Hero::all()->keyBy('hero_id');
            $memberHeroNames = [];
            $heroNames = [];
            $heroIds = [];
            $memberTeam = null;

            foreach ($matchData['players'] ?? [] as $player) {
                $accountId = $player['account_id'] ?? null;
                if (! $accountId) {
                    continue;
                }

                $steamId = Member::convertAccountIdToSteamId($accountId);
                $heroId = $player['hero_id'] ?? 0;
                $heroName = $heroes->get($heroId)?->localized_name ?? "Hero #{$heroId}";
                $isRadiant = ($player['player_slot'] ?? 0) < 128;

                if (in_array($steamId, $memberSteamIds)) {
                    $memberHeroNames[] = $heroName;
                    if ($memberTeam === null) {
                        $memberTeam = $isRadiant ? 'Radiant' : 'Dire';
                    }
                }

                $heroNames[] = $heroName;
                $heroIds[] = $heroId;
            }

            // Calculate win/loss context
            $radiantWin = $matchData['radiant_win'] ?? false;
            $membersWon = ($memberTeam === 'Radiant' && $radiantWin) || ($memberTeam === 'Dire' && ! $radiantWin);
            $radiantScore = $matchData['radiant_score'] ?? 0;
            $direScore = $matchData['dire_score'] ?? 0;
            $duration = isset($matchData['duration']) ? round($matchData['duration'] / 60) : 'unknown';
            $gameMode = $this->getGameModeName($matchData['game_mode'] ?? null);

            // Build user content with team context and member heroes
            $userContent = [];

            // Add match context
            $userContent[] = "Mode: {$gameMode}, Durasi: {$duration} menit";
            $userContent[] = "Tim kami: {$memberTeam}";
            $userContent[] = 'Hasil: '.($membersWon ? 'Menang' : 'Kalah');
            $userContent[] = "Score: Radiant {$radiantScore} - Dire {$direScore}";
            $userContent[] = '';

            if (! empty($memberHeroNames)) {
                $formattedHeroes = $this->formatHeroList($memberHeroNames);
                $userContent[] = "Hero yang kami gunakan: {$formattedHeroes}";
                $userContent[] = '';
                $userContent[] = 'Mapping hero_id ke Nama Hero:';

                foreach ($heroIds as $index => $id) {
                    $userContent[] = "- hero_id {$id}: {$heroNames[$index]}";
                }

                $userContent[] = '';
                $userContent[] = 'Aturan analisis:';
                $userContent[] = "- Fokus HANYA pada hero: {$formattedHeroes}.";
                $userContent[] = '- Jangan membahas atau mengevaluasi hero lain.';
                $userContent[] = '- Jika penyebab kekalahan berasal dari faktor di luar hero member, jelaskan dampaknya TERHADAP hero member, bukan performa hero lain.';
                $userContent[] = '';
            }

            $userContent[] = 'Berikut adalah data match DotA 2 dalam format JSON.';
            $userContent[] = 'Gunakan HANYA data ini untuk analisis kekalahan. Jangan mengarang.';
            $userContent[] = '';
            $userContent[] = 'JSON:';
            $userContent[] = json_encode($matchData);

            $response = $this->client->chat()->create([
                'model' => config('openai.model'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->buildSystemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => implode("\n", $userContent),
                    ],
                ],
                // 'temperature' => 0.7,
                // 'max_tokens' => 500,
                'reasoning_effort' => config('openai.reasoning_effort'),
            ]);

            $analysis = $response->choices[0]->message->content ?? null;

            if (! $analysis) {
                Log::error('OpenAI returned empty analysis');

                return null;
            }

            return trim($analysis);
        } catch (\Exception $e) {
            Log::error('OpenAI API analyzeDefeat exception', [
                'match_id' => $matchData['match_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build comprehensive analysis prompt with match context
     */
    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
            Kamu adalah analis DotA 2 yang berpengalaman dan objektif.
            User akan memberikan tim (Radiant/Dire), hasil (Menang/Kalah), dan hero yang digunakan.
            
            Fokus analisismu adalah TIM YANG KALAH:
            - Jika hasil = Menang, tidak perlu analisis kekalahan
            - Jika hasil = Kalah, analisis tim yang disebutkan user

            Gunakan HANYA data dari JSON yang diberikan. Jangan mengarang, jangan asumsi di luar data.

            Tugasmu:
            - Menarik KESIMPULAN PENYEBAB KEKALAHAN, bukan sekadar menyebut statistik.
            - Hubungkan data dengan konteks gameplay (draft, execution, timing, koordinasi, dan kondisi Turbo/Normal jika bisa disimpulkan dari data).

            Panduan berpikir:
            - Jika user menyediakan mapping hero_id ke nama hero, gunakan mapping tersebut sebagai satu-satunya sumber penamaan hero.
            - Bandingkan core vs core (damage, deaths, net worth, scaling).
            - Perhatikan siapa yang sering mati duluan di fight.
            - Lihat apakah combo / win condition tim gagal dijalankan.
            - Identifikasi snowball, pickoff berulang, atau fight yang kalah sebelum objektif.
            - Jika ada hero kunci (carry / initiator), jelaskan kenapa impact-nya kurang maksimal.

            Gaya bahasa:
            - Bahasa Indonesia casual dan friendly.
            - Santai dan ringan
            - Empati (tidak menyalahkan, tidak menghakimi)
            - Fokus ke "kita" dan "tim", bukan individu
            - Boleh pakai istilah casual gaming: "ke-pickoff", "keburu kalah", "miss timing", "win condition mati"

            Output WAJIB:
            - 4–6 bullet point menggunakan "-" atau "*"
            - Setiap poin HARUS berbasis data match dan dijelaskan secara singkat
            - Setiap poin HARUS langsung ke inti masalah
            - Setiap poin dirangkum ke dalam 6-12 kata

            Dilarang:
            - Menambah kalimat pembuka atau penutup
            - Memberi saran build
            - Menyalahkan player secara personal
            - Menyimpulkan tanpa dukungan data
            - Menggunakan bahasa yang terlalu formal
            PROMPT;
    }

    /**
     * Build comprehensive analysis prompt with match context
     *
     * @deprecated This method is no longer used. Team context is now included in analyzeDefeat()
     */
    private function buildAnalysisPrompt(array $matchData, array $memberSteamIds): string
    {
        $duration = isset($matchData['duration']) ? round($matchData['duration'] / 60) : 'unknown';
        $gameMode = $this->getGameModeName($matchData['game_mode'] ?? null);
        $radiantWin = $matchData['radiant_win'] ?? false;

        // Determine members' team
        $membersTeam = null;
        $memberPlayers = [];
        $enemyPlayers = [];

        foreach ($matchData['players'] ?? [] as $player) {
            $accountId = $player['account_id'] ?? null;
            if (! $accountId) {
                continue;
            }

            $steamId = Member::convertAccountIdToSteamId($accountId);
            $isRadiant = ($player['player_slot'] ?? 0) < 128;

            $playerData = [
                'hero_id' => $player['hero_id'] ?? 0,
                'kills' => $player['kills'] ?? 0,
                'deaths' => $player['deaths'] ?? 0,
                'assists' => $player['assists'] ?? 0,
                'last_hits' => $player['last_hits'] ?? 0,
                'net_worth' => $player['net_worth'] ?? 0,
                'gold_per_min' => $player['gold_per_min'] ?? 0,
                'xp_per_min' => $player['xp_per_min'] ?? 0,
                'hero_damage' => $player['hero_damage'] ?? 0,
                'tower_damage' => $player['tower_damage'] ?? 0,
                'hero_healing' => $player['hero_healing'] ?? 0,
            ];

            if (in_array($steamId, $memberSteamIds)) {
                $memberPlayers[] = $playerData;
                if ($membersTeam === null) {
                    $membersTeam = $isRadiant ? 'Radiant' : 'Dire';
                }
            } else {
                $enemyPlayers[] = $playerData;
            }
        }

        $membersWon = ($membersTeam === 'Radiant' && $radiantWin) || ($membersTeam === 'Dire' && ! $radiantWin);
        $radiantScore = $matchData['radiant_score'] ?? 0;
        $direScore = $matchData['dire_score'] ?? 0;

        $prompt = "Analisis kekalahan match DotA 2 berikut:\n\n";
        $prompt .= "Mode: {$gameMode}\n";
        $prompt .= "Durasi: {$duration} menit\n";
        $prompt .= "Score: Radiant {$radiantScore} - Dire {$direScore}\n";
        $prompt .= "Tim kita: {$membersTeam}\n";
        $prompt .= 'Hasil: '.($membersWon ? 'Menang' : 'Kalah')."\n\n";

        $prompt .= "=== TIM KITA ({$membersTeam}) ===\n";
        foreach ($memberPlayers as $idx => $player) {
            $prompt .= sprintf(
                "Player %d: Hero ID %d | KDA: %d/%d/%d | NW: %dk | GPM: %d | XPM: %d | HD: %dk | TD: %dk | HH: %dk | LH: %d\n",
                $idx + 1,
                $player['hero_id'],
                $player['kills'],
                $player['deaths'],
                $player['assists'],
                round($player['net_worth'] / 1000),
                $player['gold_per_min'],
                $player['xp_per_min'],
                round($player['hero_damage'] / 1000),
                round($player['tower_damage'] / 1000),
                round($player['hero_healing'] / 1000),
                $player['last_hits']
            );
        }

        $prompt .= "\n=== TIM MUSUH ===\n";
        foreach ($enemyPlayers as $idx => $player) {
            $prompt .= sprintf(
                "Player %d: Hero ID %d | KDA: %d/%d/%d | NW: %dk | GPM: %d | XPM: %d | HD: %dk | TD: %dk\n",
                $idx + 1,
                $player['hero_id'],
                $player['kills'],
                $player['deaths'],
                $player['assists'],
                round($player['net_worth'] / 1000),
                $player['gold_per_min'],
                $player['xp_per_min'],
                round($player['hero_damage'] / 1000),
                round($player['tower_damage'] / 1000)
            );
        }

        $prompt .= "\nBerikan analisis 4-6 poin tentang penyebab kekalahan tim kita. Fokus pada:\n";
        $prompt .= "1. Draft dan komposisi hero (synergy, counter)\n";
        $prompt .= "2. Execution dan positioning (siapa yang sering mati duluan, timing combo)\n";
        $prompt .= "3. Farming efficiency dan networth gap\n";
        $prompt .= "4. Objective control (tower, teamfight timing)\n";
        $prompt .= "5. Item build dan timing (apakah ada yang terlambat item key)\n\n";
        $prompt .= "Gunakan Bahasa Indonesia casual dengan gaming slang. Setiap poin dimulai dengan '- ' dan langsung to the point.";

        return $prompt;
    }

    /**
     * Format hero list with proper Indonesian grammar
     * Examples: "Pudge", "Pudge dan Drow Ranger", "Pudge, Drow Ranger, dan Juggernaut"
     */
    private function formatHeroList(array $heroNames): string
    {
        $count = count($heroNames);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $heroNames[0];
        }

        if ($count === 2) {
            return $heroNames[0].' dan '.$heroNames[1];
        }

        // For 3 or more heroes: "Hero1, Hero2, dan Hero3"
        $lastHero = array_pop($heroNames);

        return implode(', ', $heroNames).", dan {$lastHero}";
    }

    /**
     * Get game mode name from ID
     */
    private function getGameModeName(?int $gameModeId): string
    {
        return match ($gameModeId) {
            1 => 'All Pick',
            2 => 'Captains Mode',
            3 => 'Random Draft',
            4 => 'Single Draft',
            5 => 'All Random',
            16 => 'Captains Draft',
            22 => 'All Draft',
            23 => 'Turbo',
            default => 'Unknown Mode',
        };
    }
}
