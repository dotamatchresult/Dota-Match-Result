<?php

namespace App\Jobs;

use App\Enums\DestinationType;
use App\Models\Destination;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\FonnteService;
use App\Services\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RetryMatchParse implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public DotaMatch $dotaMatch
    ) {
        // Calculate exponential backoff delay based on retry count
        $delayMinutes = $this->calculateDelay($dotaMatch->parse_retry_count);
        $this->delay(now()->addMinutes($delayMinutes));
    }

    /**
     * Execute the job.
     */
    public function handle(FonnteService $fonnte, TelegramService $telegram): void
    {
        // Increment retry count
        $this->dotaMatch->increment('parse_retry_count');

        // Check if max retries reached
        if ($this->dotaMatch->parse_retry_count >= 5) {
            Log::warning('Max parse retries reached for match', [
                'match_id' => $this->dotaMatch->match_id,
                'retry_count' => $this->dotaMatch->parse_retry_count,
            ]);

            // Update status to failed
            $this->dotaMatch->update([
                'parse_status' => 'failed',
            ]);

            // Send failure notification based on each member's destination
            $members = Member::whereIn('id', $this->dotaMatch->members ?? [])->get();
            $groupedMembers = $members->groupBy('platform');
            $whatsAppMembers = $groupedMembers->get(DestinationType::WhatsApp->value, collect());
            $telegramMembers = $groupedMembers->get(DestinationType::Telegram->value, collect());
            $minimumWhatsAppMembers = 2;
            $minimumTelegramMembers = 3;

            $message = "Gagal memuat analisis kekalahan untuk Match ID - {$this->dotaMatch->match_id}";

            if ($whatsAppMembers->count() >= $minimumWhatsAppMembers) {
                $phoneNumber = Destination::targetForCode(Destination::CODE_WHATSAPP);
                if ($phoneNumber) {
                    $fonnte->sendMessage($phoneNumber, $message);
                }
            }

            if ($telegramMembers->count() >= $minimumTelegramMembers) {
                $telegram->sendMessage($message);
            }

            return;
        }

        // Re-dispatch parse request
        Log::info('Retrying parse request for match', [
            'match_id' => $this->dotaMatch->match_id,
            'retry_count' => $this->dotaMatch->parse_retry_count,
        ]);

        // Reset status to pending and dispatch new parse request
        $this->dotaMatch->update([
            'parse_status' => 'pending',
        ]);

        RequestMatchParse::dispatch($this->dotaMatch);
    }

    /**
     * Calculate exponential backoff delay
     * Retry 1: 15 minutes
     * Retry 2: 30 minutes
     * Retry 3: 60 minutes
     */
    private function calculateDelay(int $retryCount): int
    {
        return match ($retryCount) {
            0 => 15,  // First retry after 15 minutes
            1 => 30,  // Second retry after 30 minutes
            2 => 60,  // Third retry after 60 minutes
            default => 15,
        };
    }

    /**
     * Get the number of times the job should be attempted.
     */
    public function tries(): int
    {
        return 3;
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [30, 120, 300]; // 30s, 2min, 5min
    }
}
