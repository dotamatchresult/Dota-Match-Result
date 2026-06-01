<?php

namespace App\Jobs;

use App\Models\Destination;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Services\Analysis\MatchAnalysisPipeline;
use App\Services\FonnteService;
use App\Services\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class AnalyzeMatchWithAI implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public DotaMatch $dotaMatch
    ) {}

    /**
     * Execute the job.
     */
    public function handle(MatchAnalysisPipeline $pipeline, FonnteService $fonnte, TelegramService $telegram): void
    {
        // Ensure match is parsed
        if ($this->dotaMatch->parse_status !== 'parsed') {
            Log::warning('Attempted to analyze unparsed match', [
                'match_id' => $this->dotaMatch->match_id,
                'parse_status' => $this->dotaMatch->parse_status,
            ]);

            return;
        }

        set_time_limit(300); // 5 minutes

        // Get member steam IDs
        $memberIds = $this->dotaMatch->members ?? [];
        $members = Member::whereIn('id', $memberIds)->get();
        $memberSteamIds = $members->pluck('steam_id')->toArray();
        $memberTelegramCount = $members->where('destination', 'telegram')->unique('steam_id')->count();
        $memberWhatsAppCount = $members->where('destination', 'whatsapp')->unique('steam_id')->count();
        $memberTelegramMin = 3;
        $memberWhatsAppMin = 2;

        if (empty($memberSteamIds)) {
            Log::warning('No members found for match analysis', [
                'match_id' => $this->dotaMatch->match_id,
            ]);

            return;
        } elseif ($memberTelegramCount < $memberTelegramMin && $memberWhatsAppCount < $memberWhatsAppMin) {
            Log::info('Not enough members subscribed for AI analysis', [
                'match_id' => $this->dotaMatch->match_id,
                'telegram_count' => $memberTelegramCount,
                'whatsapp_count' => $memberWhatsAppCount,
            ]);

            return;
        }

        // Execute analysis pipeline
        $result = $pipeline->analyze($this->dotaMatch->match_data, $memberSteamIds);

        if (! $result) {
            Log::error('Analysis pipeline returned null', [
                'match_id' => $this->dotaMatch->match_id,
            ]);

            return;
        }

        // Store analysis results
        $this->dotaMatch->update([
            'ai_analysis' => $result['analysis_text'],
            'analysis_data' => array_merge(
                $result['computed_metrics'],
                ['metadata' => $result['metadata']]
            ),
            'ai_analyzed_at' => now(),
        ]);

        Log::info('Match analyzed and stored successfully', [
            'match_id' => $this->dotaMatch->match_id,
            'loss_type' => $result['computed_metrics']['loss_type'] ?? null,
            'semantic_tags' => $result['computed_metrics']['semantic_tags'] ?? [],
            'stage1_tokens' => $result['metadata']['stage1_tokens'] ?? 0,
            'stage2_tokens' => $result['metadata']['stage2_tokens'] ?? 0,
            'tokens_used' => $result['metadata']['tokens_used'] ?? 0,
        ]);

        // Send analysis to messaging platforms
        $phoneNumber = Destination::targetForCode(Destination::CODE_WHATSAPP);
        $analysis = $result['analysis_text'];

        if ($memberTelegramCount >= $memberTelegramMin) {
            $message = "*Analisis kekalahan ({$this->dotaMatch->match_id}):*\n{$analysis}";
            $telegram->sendMessage($message, 'ai');

            Log::info('AI analysis sent to Telegram', [
                'match_id' => $this->dotaMatch->match_id,
            ]);
        }

        if ($phoneNumber && $memberWhatsAppCount >= $memberWhatsAppMin) {
            $message = "*Analisis kekalahan ({$this->dotaMatch->match_id}):*\n{$analysis}";
            $fonnte->sendMessage($phoneNumber, $message);

            Log::info('AI analysis sent to WhatsApp', [
                'match_id' => $this->dotaMatch->match_id,
                'phone_number' => $phoneNumber,
            ]);
        }

        Log::info("Match analysis job completed", [
            'ram_usage' => round(memory_get_usage() / 1024 / 1024, 2).' MB',
            'peak_ram_usage' => round(memory_get_peak_usage() / 1024 / 1024, 2).' MB',
        ]);
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
