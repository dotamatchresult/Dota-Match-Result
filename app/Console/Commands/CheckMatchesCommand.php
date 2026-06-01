<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMatchNotification;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Models\Setting;
use App\Services\SteamApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

class CheckMatchesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'matches:check {match_id? : Optional specific match ID to process} {--force : Delete existing match data before processing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for new DotA 2 matches for all registered members, or process a specific match';

    /**
     * Execute the console command.
     */
    public function handle(SteamApiService $steamApi): int
    {
        // Check and display last execution time
        $this->writeLog();

        $force = $this->option('force');

        // If match_id argument is provided, process that specific match
        $matchId = $this->argument('match_id');
        if ($matchId) {
            return $this->processSingleMatch($steamApi, $matchId, $force);
        }

        // If force option is set, remind user to provide match_id for specific processing
        if ($force) {
            $this->warn('The --force option only available when a match_id is provided.');

            return self::FAILURE;
        }

        $this->info('Checking for new matches...');

        // Get minimum match date setting
        $minimumMatchDate = Setting::get('minimum_match_date');
        if ($minimumMatchDate) {
            $minimumMatchDate = Date::parse($minimumMatchDate);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Member> */
        $members = Member::get();

        if ($members->isEmpty()) {
            $this->warn('No members found to check');

            return self::SUCCESS;
        }

        $matchToCheck = (int) config('dota.match_to_check', 10);
        $matchesByMatchId = [];

        // Fetch matches for each member
        foreach ($members as $member) {
            $this->line("Checking matches for {$member->name} ({$member->steam_id})...");

            $matches = $steamApi->getMatchHistory($member->steam_id, $matchToCheck);

            if (! $matches) {
                $this->warn("Failed to fetch matches for {$member->name}");

                continue;
            }

            foreach ($matches as $match) {
                $matchId = (string) $match['match_id'];

                // Check if match already processed
                if (DotaMatch::where('match_id', $matchId)->exists()) {
                    continue;
                }

                // Filter by minimum match date if set
                if (isset($match['start_time'])) {
                    $matchDate = now()->setTimestamp($match['start_time']);

                    // Use the later date between minimum_match_date setting and member's created_at
                    $effectiveMinDate = $minimumMatchDate
                        ? ($minimumMatchDate->gt($member->created_at) ? $minimumMatchDate : $member->created_at)
                        : $member->created_at;

                    if ($matchDate->lt($effectiveMinDate)) {
                        continue;
                    }
                }

                // Group members by match ID
                if (! isset($matchesByMatchId[$matchId])) {
                    $matchesByMatchId[$matchId] = [
                        'match_id' => $matchId,
                        'members' => [],
                    ];
                }

                $matchesByMatchId[$matchId]['members'][] = $member->id;
            }

            sleep(1); // To avoid hitting API rate limits
        }

        // Process new matches
        $processedCount = 0;

        foreach ($matchesByMatchId as $matchId => $matchData) {
            $this->line("Processing match {$matchId}...");

            // Fetch detailed match information
            $matchDetails = $steamApi->getMatchDetails($matchId);

            if (! $matchDetails) {
                $this->warn("Failed to fetch details for match {$matchId}");

                continue;
            }

            [$relevantData, $matchMembers] = $this->buildRelevantMatchData($matchDetails, $members);

            // Create match record
            $matchMemberIds = $matchMembers->pluck('id')->toArray();
            $outcome = DotaMatch::matchOutcome($relevantData, $matchMemberIds);

            $dotaMatch = DotaMatch::create([
                'match_id' => $matchId,
                'match_timestamp' => isset($matchDetails['start_time']) ? now()->setTimestamp($matchDetails['start_time']) : null,
                'match_data' => $relevantData,
                'members' => $matchMemberIds,
                'parse_status' => $outcome !== 'Lost' ? null : 'pending',
            ]);

            // Dispatch notification job
            ProcessMatchNotification::dispatch($dotaMatch);

            $processedCount++;
        }

        if ($processedCount > 0) {
            $this->info("Dispatched {$processedCount} new match notifications");
        } else {
            $this->info('No new matches found');
        }

        return self::SUCCESS;
    }

    /**
     * Process a single specific match by match ID.
     */
    protected function processSingleMatch(SteamApiService $steamApi, string $matchId, bool $force = false): int
    {
        $this->info("Processing specific match: {$matchId}");

        // Check if match already exists
        if (DotaMatch::where('match_id', $matchId)->exists()) {
            if (! $force) {
                $this->warn("Match {$matchId} already exists in database. Skipping.");
                $this->warn("Use --force option to repeat the process.");

                return self::SUCCESS;
            }

            DotaMatch::where('match_id', $matchId)->delete();
            $this->warn("Force mode: deleted existing record for match {$matchId}.");
        }

        // Fetch detailed match information
        $matchDetails = $steamApi->getMatchDetails($matchId);

        if (! $matchDetails) {
            $this->error("Failed to fetch details for match {$matchId}. Please check the match ID and try again.");

            return self::FAILURE;
        }

        // Get all members to check who participated
        $members = Member::get();

        if ($members->isEmpty()) {
            $this->warn('No members found in database');

            return self::SUCCESS;
        }

        // Build match data and find participating members
        [$relevantData, $matchMembers] = $this->buildRelevantMatchData($matchDetails, $members);

        if ($matchMembers->isEmpty()) {
            $this->info("No tracked members found in match {$matchId}. Skipping.");

            return self::SUCCESS;
        }

        // Create match record
        $matchMemberIds = $matchMembers->pluck('id')->toArray();
        $outcome = DotaMatch::matchOutcome($relevantData, $matchMemberIds);

        $dotaMatch = DotaMatch::create([
            'match_id' => $matchId,
            'match_timestamp' => isset($matchDetails['start_time']) ? now()->setTimestamp($matchDetails['start_time']) : null,
            'match_data' => $relevantData,
            'members' => $matchMemberIds,
            'parse_status' => $outcome !== 'Lost' ? null : 'pending',
        ]);

        // Dispatch notification job
        ProcessMatchNotification::dispatch($dotaMatch);

        $memberNames = $matchMembers->pluck('name')->join(', ');
        $this->info("Match {$matchId} processed successfully!");
        $this->info("Outcome: {$outcome}");
        $this->info("Members: {$memberNames}");
        $this->info('Notification job dispatched');

        return self::SUCCESS;
    }

    /**
     * Build relevant match data and identify participating members.
     *
     * @return array{array, \Illuminate\Database\Eloquent\Collection}
     */
    protected function buildRelevantMatchData(array $matchDetails, $members): array
    {
        $players = collect([]);
        $matchMembers = collect();

        if (is_array($matchDetails['players'])) {
            foreach ($matchDetails['players'] as $player) {
                $players->push([
                    'account_id' => $player['account_id'] ?? null,
                    'player_slot' => $player['player_slot'] ?? 0,
                    'hero_id' => $player['hero_id'] ?? 0,
                    'kills' => $player['kills'] ?? 0,
                    'deaths' => $player['deaths'] ?? 0,
                    'assists' => $player['assists'] ?? 0,
                    'hero_damage' => $player['hero_damage'] ?? 0,
                    'tower_damage' => $player['tower_damage'] ?? 0,
                    'hero_healing' => $player['hero_healing'] ?? 0,
                    'last_hits' => $player['last_hits'] ?? 0,
                    'denies' => $player['denies'] ?? 0,
                    'net_worth' => $player['net_worth'] ?? 0,
                    'gold_per_min' => $player['gold_per_min'] ?? 0,
                    'xp_per_min' => $player['xp_per_min'] ?? 0,
                    'level' => $player['level'] ?? 0,
                    'benchmarks' => $player['benchmarks'] ?? [],
                ]);
            }

            $steamIds = $players->pluck('account_id')->filter()->map(function ($accountId) {
                return Member::convertAccountIdToSteamId($accountId);
            })->toArray();

            $matchMembers = $members->whereIn('steam_id', $steamIds);
        }

        // Extract only relevant data
        $relevantData = [
            'radiant_win' => $matchDetails['radiant_win'] ?? false,
            'game_mode' => $matchDetails['game_mode'] ?? null,
            'duration' => $matchDetails['duration'] ?? null,
            'radiant_score' => $matchDetails['radiant_score'] ?? 0,
            'dire_score' => $matchDetails['dire_score'] ?? 0,
            'first_blood_time' => $matchDetails['first_blood_time'] ?? null,
            'players' => $players->toArray(),
        ];

        return [$relevantData, $matchMembers];
    }

    protected function writeLog(): void
    {
        $logPath = 'matches-check.log';

        if (Storage::disk('local')->exists($logPath)) {
            $lastExecution = Storage::disk('local')->get($logPath);
            $this->info("Last execution: {$lastExecution}");
        } else {
            $this->info('First time execution');
        }

        // Write current execution time
        $currentTime = now()->format('Y-m-d H:i:s');

        Storage::disk('local')->put($logPath, $currentTime);
    }
}
