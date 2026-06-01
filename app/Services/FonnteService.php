<?php

namespace App\Services;

use App\Models\Destination;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FonnteService
{
    private string $apiKey;

    private string $baseUrl = 'https://api.fonnte.com';

    public function __construct()
    {
        $this->apiKey = (string) (Destination::mainTokenForCode(Destination::CODE_WHATSAPP) ?: config('services.fonnte.api_key', ''));
    }

    /**
     * Send a WhatsApp image message with an optional text caption via Fonnte API.
     *
     * The image is first uploaded to freeimage.host and the resulting URL is
     * prepended to the message before sending via Fonnte.
     */
    public function sendImage(?string $phoneNumber, string $imagePath, string $message = ''): bool
    {
        $target = $phoneNumber ?: Destination::targetForCode(Destination::CODE_WHATSAPP);

        if (! $target) {
            Log::error('WhatsApp destination target is not configured');

            return false;
        }

        $message = $this->convertToWhatsAppFormat($message);
        $message = str_replace('Zeus', collect(['Kakek Zus', 'Mario', 'Mbah Marijan'])->random(), $message);

        $imageName = basename($imagePath);

        if (! config('dota.message_enabled')) {
            Log::info('Message sending is disabled in config, skipping image send', [
                'phone' => $target,
                'message' => $message,
            ]);

            if (config('filesystems.disks.s3.key') && false) {
                $imageUrl = $this->uploadImageToS3($imagePath);
                [$matchId] = explode('_', $imageName);
                $previewUrl = config('dota.image_preview').'?id='.$matchId;

                Log::info('Simulated image send', [
                    'image_url' => $imageUrl,
                    'preview_url' => $previewUrl,
                ]);
            }

            return true;
        }

        try {
            $imageUrl = $this->uploadImageToS3($imagePath);

            if ($imageUrl !== null) {
                [$matchId] = explode('_', $imageName);
                $previewUrl = config('dota.image_preview').'?id='.$matchId;
                $message = $previewUrl."\n\n".$message;
                @unlink($imagePath);
            }

            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => $this->apiKey,
                ])
                ->post("{$this->baseUrl}/send", [
                    'target' => $target,
                    'message' => $message,
                    'countryCode' => '62',
                    'preview' => true,
                ]);

            if ($response->successful() && $response->json('status')) {
                Log::info('WhatsApp image sent successfully', [
                    'phone' => $target,
                    'image_url' => $imageUrl,
                ]);

                return true;
            }

            Log::error('Fonnte API sendImage failed', [
                'phone' => $target,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Fonnte API sendImage exception', [
                'phone' => $target,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Upload an image file to AWS S3 and return the public URL.
     *
     * Returns null if the upload fails.
     */
    private function uploadImageToS3(string $imagePath): ?string
    {
        try {
            $key = 'mvp-radars/'.basename($imagePath);

            Storage::disk('s3')->put($key, file_get_contents($imagePath), 'public');

            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            $disk = Storage::disk('s3');
            $url = $disk->url($key);

            Log::info('Image uploaded to S3', ['url' => $url]);

            return $url;
        } catch (\Exception $e) {
            Log::error('S3 image upload exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Send WhatsApp message via Fonnte API
     */
    public function sendMessage(?string $phoneNumber, string $message): bool
    {
        $target = $phoneNumber ?: Destination::targetForCode(Destination::CODE_WHATSAPP);

        if (! $target) {
            Log::error('WhatsApp destination target is not configured');

            return false;
        }

        // Convert Telegram Markdown format to WhatsApp format
        $message = $this->convertToWhatsAppFormat($message);
        $message = str_replace("Zeus", collect(["Kakek Zus", "Mario", "Mbah Marijan"])->random(), $message);

        if (! config('dota.message_enabled')) {
            Log::info('Message sending is disabled in config, skipping send', [
                'phone' => $target,
                'message' => $message,
            ]);

            return true; // Simulate success when disabled
        }

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => $this->apiKey,
                ])
                ->post("{$this->baseUrl}/send", [
                    'target' => $target,
                    'message' => $message,
                    'countryCode' => '62',
                    'preview' => false,
                ]);

            if ($response->successful() && $response->json('status')) {
                Log::info('WhatsApp message sent successfully', [
                    'phone' => $target,
                    'reaason' => $response->json('reason'),
                ]);

                return true;
            }

            Log::error('Fonnte API send failed', [
                'phone' => $target,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Fonnte API send exception', [
                'phone' => $target,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Convert Telegram Markdown format (**bold**) to WhatsApp format (*bold*)
     */
    private function convertToWhatsAppFormat(string $message): string
    {
        $result = str_replace('**', '*', $message);
        $result = str_replace('__', '_', $result);

        return $result;
    }
}
