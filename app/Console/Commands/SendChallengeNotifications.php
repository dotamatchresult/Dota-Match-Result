<?php

namespace App\Console\Commands;

use App\Services\DailyChallenge\ChallengeNotificationDispatcher;
use Illuminate\Console\Command;

class SendChallengeNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'challenges:send-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send pending challenge notifications via the configured messaging transports';

    /**
     * Execute the console command.
     */
    public function handle(ChallengeNotificationDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatch();

        $this->info("Processed: {$result['processed']}");
        $this->info("Sent: {$result['sent']}");
        $this->info("Batched: {$result['batched']} groups");
        $this->info("Failed: {$result['failed']}");

        return self::SUCCESS;
    }
}
