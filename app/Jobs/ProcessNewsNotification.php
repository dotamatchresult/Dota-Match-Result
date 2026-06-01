<?php

namespace App\Jobs;

use App\Models\Destination;
use App\Models\SteamNews;
use App\Services\FonnteService;
use App\Services\TelegramService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessNewsNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(public SteamNews $steamNews)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(FonnteService $fonnte, TelegramService $telegram): void
    {
        try {
            $whatsappSuccess = false;
            $telegramSuccess = false;

            // Send to WhatsApp
            try {
                $phoneNumber = Destination::targetForCode(Destination::CODE_WHATSAPP);

                if (! $phoneNumber) {
                    Log::warning('WhatsApp destination target not configured');
                } else {
                    $message = $this->formatMessage('whatsapp');
                    $whatsappSuccess = $fonnte->sendMessage($phoneNumber, $message);

                    if ($whatsappSuccess) {
                        Log::info('WhatsApp news notification sent successfully', [
                            'gid' => $this->steamNews->gid,
                            'title' => $this->steamNews->title,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('WhatsApp news notification failed', [
                    'gid' => $this->steamNews->gid,
                    'error' => $e->getMessage(),
                ]);
            }

            // Send to Telegram
            try {
                $message = $this->formatMessage('telegram');
                $telegramSuccess = $telegram->sendMessage($message, 'ai');

                if ($telegramSuccess) {
                    Log::info('Telegram news notification sent successfully', [
                        'gid' => $this->steamNews->gid,
                        'title' => $this->steamNews->title,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Telegram news notification failed', [
                    'gid' => $this->steamNews->gid,
                    'error' => $e->getMessage(),
                ]);
            }

            // Mark as notified if at least one platform succeeded
            if ($whatsappSuccess || $telegramSuccess) {
                $this->steamNews->update([
                    'notified_at' => now(),
                ]);

                Log::info('Steam news notification completed', [
                    'gid' => $this->steamNews->gid,
                    'title' => $this->steamNews->title,
                    'whatsapp' => $whatsappSuccess,
                    'telegram' => $telegramSuccess,
                ]);
            } else {
                throw new \Exception('Failed to send notification to any platform');
            }
        } catch (\Exception $e) {
            Log::error('Steam news notification failed completely', [
                'gid' => $this->steamNews->gid,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Get the retry backoff times
     */
    public function backoff(): array
    {
        return [30, 120, 300]; // 30s, 2min, 5min
    }

    /**
     * Format the notification message
     */
    private function formatMessage(string $platform): string
    {
        $title = $this->steamNews->title;
        $url = $this->steamNews->url;

        // Extract plain text from BBCode contents and limit length
        $contents = $this->stripBBCode($this->steamNews->contents);
        $excerpt = Str::limit($contents, 200);
        $heading = "📰 **DotA 2 News**";

        if ($this->steamNews->feedname === 'github') {
            $heading = "👾 **DotA 2 Coding Update**";
            $excerpt = $contents;
        }

        if ($platform === 'telegram') {
            // Telegram uses **bold** markdown
            $result = "{$heading}\n\n**{$title}**\n\n{$excerpt}";

            if ($this->steamNews->feedname === 'github') {
                $result .= "\n\n🌐 Sumber: SteamDB Dota 2 - GitHub";
            } else {
                $result .= "\n\n🔗 {$url}";
            }

            return $result;
        }

        $heading = str_replace('**', '*', $heading);

        // WhatsApp uses *bold* markdown
        return "{$heading}\n\n*{$title}*\n\n{$excerpt}\n\n> 🔗 {$url}";
    }

    /**
     * Strip BBCode tags from text
     */
    private function stripBBCode(string $text): string
    {
        // Convert basic BBCode to plain text
        $text = preg_replace('/\[url=[^\]]+\]([^\[]+)\[\/url\]/i', '$1', $text);
        $text = preg_replace('/\[url\]([^\[]+)\[\/url\]/i', '$1', $text);
        $text = preg_replace('/\[img\][^\[]+\[\/img\]/i', '', $text);
        $text = preg_replace('/\[\/?(p|b|i|u|list|\*)\]/i', '', $text);
        $text = preg_replace('/\[\/?[^\]]+\]/i', '', $text);

        // Clean up extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
