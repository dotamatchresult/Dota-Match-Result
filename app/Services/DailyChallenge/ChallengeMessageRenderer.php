<?php

namespace App\Services\DailyChallenge;

use App\Models\ChallengeNotification;
use App\Models\Destination;
use App\Models\DestinationChallenge;
use App\Services\TelegramService;

class ChallengeMessageRenderer
{
    public function __construct(
        protected ChallengeDescriptionService $descriptionService
    ) {}

    /**
     * Render a single notification into a formatted message string.
     */
    public function render(ChallengeNotification $notification, ?Destination $destination = null): string
    {
        $isTelegram = $this->isTelegram($destination);

        return match ($notification->type) {
            'assigned_announcement' => $this->renderAssignedAnnouncement($notification, $isTelegram),
            'completed' => $this->renderSingleCompleted($notification, $isTelegram),
            'backlog_full' => $this->renderBacklogFull($notification, $isTelegram),
            'recap' => $this->renderRecap($notification, $isTelegram),
            'review_delayed' => '', // $this->renderReviewDelayed(),
            default => '',
        };
    }

    /**
     * Render a batch of completed notifications into a single multi-completion message.
     *
     * If the batch contains only 1 notification, uses the single-completion format.
     *
     * @param  ChallengeNotification[]  $notifications
     */
    public function renderBatch(array $notifications, ?Destination $destination = null): string
    {
        $isTelegram = $this->isTelegram($destination);

        if (count($notifications) === 1) {
            return $this->renderSingleCompleted($notifications[0], $isTelegram);
        }

        $descriptions = array_map(function (ChallengeNotification $notification) use ($isTelegram) {
            $destinationChallenge = $notification->destinationChallenge;
            $description = $this->descriptionService->describe($destinationChallenge);

            return '✅ '.$this->escape($description, $isTelegram);
        }, $notifications);

        $count = count($notifications);
        $lines = [
            "*🎉 Tantangan Selesai*",
            '',
            "Kalian udah selesaikan {$count} tantangan:",
            '',
            ...$descriptions,
        ];

        return implode("\n", $lines);
    }

    /**
     * Render the assigned_announcement notification type.
     */
    private function renderAssignedAnnouncement(ChallengeNotification $notification, bool $isTelegram = false): string
    {
        $destinationChallenge = $notification->destinationChallenge;

        if (! $destinationChallenge) {
            return '';
        }

        $description = $this->escape($this->descriptionService->describe($destinationChallenge), $isTelegram);
        $progress = $destinationChallenge->current_progress ?? 0;
        $requirement = $destinationChallenge->current_requirement ?? 0;
        $activeLines = [];

        if ($destinationId = $destinationChallenge->destination_id) {
            $activeChallenges = DestinationChallenge::query()
                ->where('destination_id', $destinationId)
                ->where('id', '!=', $destinationChallenge->id)
                ->where('status', 'active')
                ->with('challenge')
                ->get();

            if ($activeChallenges->isNotEmpty()) {
                foreach ($activeChallenges as $dc) {
                    $dcDesc = $this->escape($this->descriptionService->describe($dc), $isTelegram);
                    $dcProgress = $dc->current_progress;
                    $dcRequirement = $dc->current_requirement;

                    $activeLines[] = "- {$dcDesc} ({$dcProgress}/{$dcRequirement} selesai)";
                }
            }
        }

        return implode("\n", [
            "*🎯 Tantangan Harian*",
            '',
            "{$description}",
            '',
            "Progress:",
            "{$progress} / {$requirement}",
            ...(!count($activeLines) ? [] : [
                '',
                "*🏇🏻 Tantangan Aktif*",
                ...$activeLines,
            ]),
        ]);
    }

    /**
     * Render a single completed notification.
     */
    private function renderSingleCompleted(ChallengeNotification $notification, bool $isTelegram = false): string
    {
        $destinationChallenge = $notification->destinationChallenge;

        if (! $destinationChallenge) {
            return '';
        }

        $description = $this->escape($this->descriptionService->describe($destinationChallenge), $isTelegram);

        $comments = [
            "Kerja bagus 👍🏻",
            "Meski kroco tapi boleh juga 💯",
        ];
        $randomComment = $comments[array_rand($comments)];

        return implode("\n", [
            "*🎉 Tantangan Selesai*",
            '',
            "✅ {$description}",
            '',
            $randomComment,
        ]);
    }

    /**
     * Render the recap notification type.
     *
     * Only sent when there are failed challenges. Reads failed challenge
     * details from the notification payload.
     */
    private function renderRecap(ChallengeNotification $notification, bool $isTelegram = false): string
    {
        $payload = $notification->payload;
        $failedCount = (int) ($payload['failed_count'] ?? 0);
        $failedChallenges = $payload['failed_challenges'] ?? [];

        if ($failedCount === 0 || empty($failedChallenges)) {
            return '';
        }

        $failOneComments = [
            'Wah, pada kesusahan ya 😢',
            'Jangan sedih, coba evaluasi lagi strateginya 💡',
            'Mungkin bisa coba hero/role lain? 🤔',
        ];
        $failOneComment = $failOneComments[array_rand($failOneComments)];

        $failMultipleComments = [
            'Pancen kroco jan arek-arek iki 🪳',
            'Main dota kui nggo strategi bos 😉',
        ];
        $failMultipleComment = $failMultipleComments[array_rand($failMultipleComments)];

        $encouragement = match (true) {
            $failedCount === 1 => $failOneComment,
            $failedCount >= 2 => $failMultipleComment,
            default => 'Evaluasi strategi kalian.',
        };

        $incrementComments = [
            'Sebagai hukuman, tugas kalian ditambah 👺:',
            'Remidi dulu, tugas kalian ditambah 👺:',
            'Tugas ditambah ben gayeng 👺:',
        ];
        $incrementComment = $incrementComments[array_rand($incrementComments)]; 

        $lines = [
            "🪦 {$failedCount} tantangan gak selesai",
            '',
            $encouragement,
            '',
            $incrementComment,
        ];

        foreach ($failedChallenges as $challenge) {
            $description = $this->escape($challenge['description'] ?? '', $isTelegram);
            $progress = $challenge['progress'] ?? 0;
            $requirement = $challenge['requirement'] ?? 0;

            $lines[] = "- {$description} ({$progress}/{$requirement} selesai)";
        }

        return implode("\n", $lines);
    }

    /**
     * Render the backlog_full notification type.
     */
    private function renderBacklogFull(ChallengeNotification $notification, bool $isTelegram = false): string
    {
        $max = (int) config('dota.daily_challenge.max_active_per_destination', 5);
        $failedLines = [];

        $payload = $notification->payload;

        if (is_array($payload) && isset($payload['destination_id'])) {
            $destinationId = (int) $payload['destination_id'];
            $activeChallenges = DestinationChallenge::query()
                ->where('destination_id', $destinationId)
                ->where('status', 'active')
                ->with('challenge')
                ->get();

            if ($activeChallenges->isNotEmpty()) {
                foreach ($activeChallenges as $dc) {
                    $description = $this->escape($this->descriptionService->describe($dc), $isTelegram);
                    $progress = $dc->current_progress;
                    $requirement = $dc->current_requirement;

                    $failedLines[] = "- {$description} ({$progress}/{$requirement} selesai)";
                }
            }
        }

        return implode("\n", [
            "*📚 Kakehan Tantangan*",
            '',
            'Minimal main sing bener bos 🤪',
            '',
            "Kalian sudah punya {$max} tantangan aktif:",
            ...$failedLines,
        ]);
    }

    /**
     * Render the review_delayed notification type.
     */
    private function renderReviewDelayed(): string
    {
        return implode("\n", [
            "⏳ Review tantangan hari ini ditunda.",
            '',
            "Masih ada pertandingan yang belum diproses OpenDota.",
            'Kami akan mengecek ulang secara otomatis setelah hasil pertandingan tersedia.',
        ]);
    }

    /**
     * Determine whether the destination is Telegram, which requires Markdown escaping.
     */
    private function isTelegram(?Destination $destination): bool
    {
        return $destination?->code === Destination::CODE_TELEGRAM;
    }

    /**
     * Escape dynamic content for Telegram Markdown to prevent "can't parse entities" errors.
     *
     * Descriptions come from hero/item names and admin-authored challenge text, which
     * may contain unescaped *, _, `, or [ characters that break Telegram's parser.
     */
    private function escape(string $text, bool $isTelegram): string
    {
        return $isTelegram ? TelegramService::escapeMarkdown($text) : $text;
    }
}
