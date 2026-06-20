<?php

namespace App\Services\DailyChallenge;

use App\Models\ChallengeNotification;
use App\Models\DestinationChallenge;

class ChallengeMessageRenderer
{
    public function __construct(
        protected ChallengeDescriptionService $descriptionService
    ) {}

    /**
     * Render a single notification into a formatted message string.
     */
    public function render(ChallengeNotification $notification): string
    {
        return match ($notification->type) {
            'assigned_announcement' => $this->renderAssignedAnnouncement($notification),
            'completed' => $this->renderSingleCompleted($notification),
            'backlog_full' => $this->renderBacklogFull($notification),
            'recap' => $this->renderRecap($notification),
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
    public function renderBatch(array $notifications): string
    {
        if (count($notifications) === 1) {
            return $this->renderSingleCompleted($notifications[0]);
        }

        $descriptions = array_map(function (ChallengeNotification $notification) {
            $destinationChallenge = $notification->destinationChallenge;

            return '✅ '.$this->descriptionService->describe($destinationChallenge);
        }, $notifications);

        $count = count($notifications);
        $lines = [
            "🎉 TANTANGAN SELESAI",
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
    private function renderAssignedAnnouncement(ChallengeNotification $notification): string
    {
        $destinationChallenge = $notification->destinationChallenge;

        if (! $destinationChallenge) {
            return '';
        }

        $description = $this->descriptionService->describe($destinationChallenge);
        $progress = $destinationChallenge->current_progress ?? 0;
        $requirement = $destinationChallenge->current_requirement ?? 0;

        return implode("\n", [
            "🎯 TANTANGAN HARIAN",
            '',
            "{$description}",
            '',
            "Progress:",
            "{$progress} / {$requirement}",
            // "\n\nSemoga beruntung."
        ]);
    }

    /**
     * Render a single completed notification.
     */
    private function renderSingleCompleted(ChallengeNotification $notification): string
    {
        $destinationChallenge = $notification->destinationChallenge;

        if (! $destinationChallenge) {
            return '';
        }

        $description = $this->descriptionService->describe($destinationChallenge);

        $comments = [
            "Kerja bagus 👍🏻",
            "Meski kroco tapi boleh juga 💯",
        ];
        $randomComment = $comments[array_rand($comments)];

        return implode("\n", [
            "🎉 TANTANGAN SELESAI",
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
    private function renderRecap(ChallengeNotification $notification): string
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
            $description = $challenge['description'] ?? '';
            $progress = $challenge['progress'] ?? 0;
            $requirement = $challenge['requirement'] ?? 0;

            $lines[] = "- {$description} ({$progress}/{$requirement} selesai)";
        }

        return implode("\n", $lines);
    }

    /**
     * Render the backlog_full notification type.
     */
    private function renderBacklogFull(ChallengeNotification $notification): string
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
                    $description = $this->descriptionService->describe($dc);
                    $progress = $dc->current_progress;
                    $requirement = $dc->current_requirement;

                    $failedLines[] = "- {$description} ({$progress}/{$requirement} selesai)";
                }
            }
        }

        return implode("\n", [
            "📚 TANTANGAN MENUMPUK",
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
}
