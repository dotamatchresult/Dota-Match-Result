<?php

namespace App\Console\Commands;

use App\Enums\DestinationType;
use App\Jobs\RequestMatchParse;
use App\Models\DotaMatch;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RequestMatchParsesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'matches:request-parses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Request parse for eligible matches that are at least 2 minutes old';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->writeLog();

        $this->info('Checking for matches eligible for parse request...');

        // Get matches that:
        // - Have 'pending' parse status
        // - Have zero parse retry count
        // - Were created at least 2 minutes ago
        $matches = DotaMatch::where('parse_status', 'pending')
            ->where('parse_retry_count', 0)
            ->where('created_at', '<=', now()->subMinutes(2))
            ->get();

        if ($matches->isEmpty()) {
            $this->info('No matches eligible for parse request');

            return self::SUCCESS;
        }

        $this->line("Found {$matches->count()} matches to evaluate");

        $dispatchedCount = 0;

        foreach ($matches as $match) {
            // Check if match is a loss
            if ($match->outcome !== 'Lost') {
                $this->line("Match {$match->match_id}: Not a loss ({$match->outcome}), skipping");

                continue;
            }

            // Count members by destination type
            $memberIds = $match->members ?? [];

            if (empty($memberIds)) {
                $this->line("Match {$match->match_id}: No members found, skipping");

                continue;
            }

            $members = Member::whereIn('id', $memberIds)->get();
            $whatsappCount = $members->where('destination', DestinationType::WhatsApp)->unique('steam_id')->count();
            $telegramCount = $members->where('destination', DestinationType::Telegram)->unique('steam_id')->count();

            // Check if meets minimum member requirements
            if ($whatsappCount < 2 && $telegramCount < 3) {
                $this->line("Match {$match->match_id}: Insufficient members (WhatsApp: {$whatsappCount}, Telegram: {$telegramCount}), skipping");

                $match->update([
                    'parse_status' => 'failed',
                ]);

                Log::info("Match {$match->match_id} marked as failed due to insufficient members. (WhatsApp: {$whatsappCount}, Telegram: {$telegramCount})");

                continue;
            }

            // Dispatch parse request
            $this->line("Match {$match->match_id}: Dispatching parse request");
            Log::info('Requesting parse for match', [
                'match_id' => $match->match_id,
            ]);

            RequestMatchParse::dispatch($match);

            $dispatchedCount++;

            sleep(1); // Sleep for 1 second between dispatches
        }

        if ($dispatchedCount > 0) {
            $this->info("Dispatched {$dispatchedCount} parse requests");
        } else {
            $this->info('No matches met the criteria for parse request');
        }

        return self::SUCCESS;
    }

    protected function writeLog(): void
    {
        $logPath = 'matches-parse-requests.log';

        if (Storage::disk('local')->exists($logPath)) {
            $lastExecution = Storage::disk('local')->get($logPath);
            $this->info("Last parse request: {$lastExecution}");
        } else {
            $this->info('First time parse request');
        }

        // Write current execution time
        $currentTime = now()->format('Y-m-d H:i:s');

        Storage::disk('local')->put($logPath, $currentTime);
    }
}
