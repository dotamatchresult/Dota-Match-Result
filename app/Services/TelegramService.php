<?php

namespace App\Services;

use App\Models\Destination;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $botToken;

    private string $baseUrl = 'https://api.telegram.org';

    public function __construct()
    {
        $this->botToken = (string) (Destination::mainTokenForCode(Destination::CODE_TELEGRAM) ?: config('services.telegram.bot_token', ''));
    }

    /**
     * Escape special characters for Telegram's legacy Markdown parse mode.
     *
     * Characters escaped: \, *, _, `, [
     * This prevents Telegram's parser from misinterpreting literal characters
     * as Markdown formatting entities, which would cause "can't parse entities" errors.
     */
    public static function escapeMarkdown(string $text): string
    {
        return str_replace(
            ['\\', '*', '_', '`', '['],
            ['\\\\', '\\*', '\\_', '\\`', '\\['],
            $text
        );
    }

    /**
     * Send a photo to the Telegram group with a caption.
     *
     * Caption is truncated to Telegram's 1024-character API limit.
     */
    public function sendPhoto(string $imagePath, string $caption = '', string $token = 'default', ?string $chatId = null): bool
    {
        $groupId = $chatId ?? Destination::targetForCode(Destination::CODE_TELEGRAM);
        $botToken = $this->botToken;

        if (! $groupId) {
            Log::error('Telegram destination target is not configured');

            return false;
        }

        if ($token === 'ai' && $ai_token = Destination::aiTokenForCode(Destination::CODE_TELEGRAM)) {
            $botToken = $ai_token;
        }

        $caption = str_replace('**', '*', $caption);
        $caption = str_replace('__', '_', $caption);

        if (mb_strlen($caption) > 1024) {
            $caption = mb_substr($caption, 0, 1021).'...';
        }

        try {
            if (! config('dota.message_enabled')) {
                Log::info('Message sending is disabled in config, skipping photo send', [
                    'group_id' => $groupId,
                    'message' => $caption,
                    'image' => $imagePath,
                ]);

                return true;
            }

            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(60)
                ->attach('photo', file_get_contents($imagePath), 'chart.png')
                ->post("{$this->baseUrl}/bot{$botToken}/sendPhoto", [
                    'chat_id' => $groupId,
                    'caption' => $caption,
                    'parse_mode' => 'Markdown',
                ]);

            if ($response->successful()) {
                Log::info('Telegram photo sent successfully', [
                    'group_id' => $groupId,
                ]);

                return true;
            }

            Log::error('Telegram API sendPhoto failed', [
                'group_id' => $groupId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Telegram API sendPhoto exception', [
                'group_id' => $groupId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send message to Telegram group via Bot API
     *
     * @var string The message to send
     * @var string The bot token to use (default from settings)
     *
     * @return bool True if sent successfully, false otherwise
     */
    public function sendMessage(string $message, $token = 'default', ?string $chatId = null): bool
    {
        $groupId = $chatId ?? Destination::targetForCode(Destination::CODE_TELEGRAM);
        $botToken = $this->botToken;

        if (! $groupId) {
            Log::error('Telegram destination target is not configured');

            return false;
        }

        if ($token === 'ai' && $ai_token = Destination::aiTokenForCode(Destination::CODE_TELEGRAM)) {
            $botToken = $ai_token;
        }

        $message = str_replace('**', '*', $message);
        $message = str_replace('__', '_', $message);

        if (! config('dota.message_enabled')) {
            Log::info('Message sending is disabled in config, skipping send', [
                'token_type' => $token === 'ai' ? 'AI Bot' : 'Default Bot',
                'group_id' => $groupId,
                'message' => $message,
            ]);

            return true; // Simulate success when disabled
        }

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(60)
                ->post("{$this->baseUrl}/bot{$botToken}/sendMessage", [
                    'chat_id' => $groupId,
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                ]);

            if ($response->successful()) {
                Log::info('Telegram message sent successfully', [
                    'group_id' => $groupId,
                ]);

                return true;
            }

            Log::error('Telegram API send failed', [
                'group_id' => $groupId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Telegram API request exception', [
                'group_id' => $groupId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
