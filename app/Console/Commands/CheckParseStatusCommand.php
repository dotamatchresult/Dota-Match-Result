<?php

namespace App\Console\Commands;

use App\Jobs\CheckParseStatus;
use App\Models\DotaMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CheckParseStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'matches:check-parse-status {match_id? : Optional specific match ID to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check parsing status for matches and dispatch check jobs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->writeLog();

        $this->info('Checking parse status for matches...');

        $matchId = $this->argument('match_id');

        if ($matchId) {
            $matches = DotaMatch::where('match_id', $matchId)->get();
        } else {
            // Get matches that are currently parsing and have been parsing for at least 2 minutes
            $matches = DotaMatch::where('parse_status', 'parsing')
                ->where('parse_requested_at', '<=', now()->subMinutes(2))
                ->get();
        }

        if ($matches->isEmpty()) {
            $this->info('No matches to check');

            return self::SUCCESS;
        }

        $this->line("Found {$matches->count()} matches to check");

        foreach ($matches as $match) {
            $this->line("Dispatching check for match {$match->match_id}...");
            CheckParseStatus::dispatch($match);
        }

        $this->info("Dispatched {$matches->count()} parse status checks");

        return self::SUCCESS;
    }

    protected function writeLog(): void
    {
        $logPath = 'matches-parse-status.log';

        if (Storage::disk('local')->exists($logPath)) {
            $lastExecution = Storage::disk('local')->get($logPath);
            $this->info("Last parse status check: {$lastExecution}");
        } else {
            $this->info('First time parse status check');
        }

        // Write current execution time
        $currentTime = now()->format('Y-m-d H:i:s');

        Storage::disk('local')->put($logPath, $currentTime);
    }
}
