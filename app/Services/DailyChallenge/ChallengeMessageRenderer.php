<?php

namespace App\Services\DailyChallenge;

use App\Models\ChallengeNotification;

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
            'backlog_full' => $this->renderBacklogFull(),
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
            "Tim kamu menyelesaikan {$count} tantangan:",
            '',
            ...$descriptions,
            '',
            'Teruskan.',
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

        return "🎯 TANTANGAN HARIAN\n\n"
            ."{$description}\n\n"
            ."Progress:\n"
            ."{$progress} / {$requirement}";
            // ."\n\nSemoga beruntung.";
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

        return "🎉 TANTANGAN SELESAI\n\n"
            ."✅ {$description}\n\n"
            .'Kerja bagus.';
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

        $encouragement = match (true) {
            $failedCount === 1 => 'Masih bisa dikejar besok.',
            $failedCount >= 2 && $failedCount <= 3 => 'Yuk lebih fokus besok.',
            default => 'Evaluasi strategi kalian.',
        };

        $lines = [
            "🪦 {$failedCount} tantangan gak selesai",
            '',
            $encouragement,
            '',
            'Sebagai hukuman, tantangan kalian ditambah 👺:',
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
    private function renderBacklogFull(): string
    {
        $max = (int) config('dota.daily_challenge.max_active_per_destination', 5);

        return "📚 TANTANGAN MENUMPUK\n\n"
            ."Kamu sudah punya {$max} tantangan aktif.\n\n"
            .'Selesaikan dulu yang ada.';
    }

    /**
     * Render the review_delayed notification type.
     */
    private function renderReviewDelayed(): string
    {
        return "⏳ Review tantangan hari ini ditunda.\n\n"
            ."Masih ada pertandingan yang belum diproses OpenDota.\n"
            .'Kami akan mengecek ulang secara otomatis setelah hasil pertandingan tersedia.';
    }
}
