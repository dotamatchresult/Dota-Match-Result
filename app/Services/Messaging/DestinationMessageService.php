<?php

namespace App\Services\Messaging;

use App\Enums\DestinationType;
use App\Models\Destination;
use App\Services\FonnteService;
use App\Services\TelegramService;
use InvalidArgumentException;

class DestinationMessageService
{
    public function __construct(
        protected TelegramService $telegramService,
        protected FonnteService $fonnteService
    ) {}

    /**
     * Send a message to the given destination using the appropriate transport.
     *
     * Routes by destination code: whatsapp → FonnteService, telegram → TelegramService.
     *
     * @throws InvalidArgumentException If the destination code is not supported.
     */
    public function send(Destination $destination, string $message): void
    {
        match ($destination->code) {
            DestinationType::WhatsApp->value => $this->fonnteService->sendMessage($destination->target, $message),
            DestinationType::Telegram->value => $this->telegramService->sendMessage($message, 'default', $destination->target),
            default => throw new InvalidArgumentException("Unsupported destination code: {$destination->code}"),
        };
    }
}
