<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Models\DotaMatch;
use App\Models\Member;
use App\Models\Reminder;
use App\Services\FonnteService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class DailyReminderCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'matches:daily-reminder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send end-of-day play summary to members who exceeded their daily match limit';

    /**
     * Execute the console command.
     */
    public function handle(FonnteService $fonnte, TelegramService $telegram): int
    {
        $reminders = Reminder::query()->get();

        if ($reminders->isEmpty()) {
            $this->info('No reminders configured.');

            return self::SUCCESS;
        }

        foreach ($reminders as $reminder) {
            $steamId = $reminder->steam_id;
            $members = Member::query()->where('steam_id', $steamId)->get();

            if ($members->isEmpty()) {
                $this->warn("Member not found for steam_id: {$steamId}");

                continue;
            }

            try {
                $todayMatches = $this->getTodayMatchesForMembers($members);

                $todayMatchCount = $todayMatches->count();

                if ($todayMatchCount < $reminder->max_matches) {
                    continue;
                }

                $primaryMember = $members->first();
                $totalSeconds = $todayMatches->sum(fn ($match) => $match->match_data['duration'] ?? 0);
                $hours = $this->formatHours($totalSeconds);

                $message = $this->buildMessage($primaryMember->name, $todayMatchCount, $hours);

                foreach ($members->unique('platform')->values() as $member) {
                    $target = Destination::targetForCode($member->platform);

                    if (! $target) {
                        Log::warning('Daily reminder target not configured for member destination', [
                            'member' => $member->name,
                            'steam_id' => $steamId,
                            'destination' => $member->platform,
                        ]);

                        continue;
                    }

                    if ($member->platform === 'whatsapp') {
                        $fonnte->sendMessage($target, $message);

                        Log::info('Daily reminder sent via WhatsApp', [
                            'member' => $member->name,
                            'steam_id' => $steamId,
                            'today_matches' => $todayMatchCount,
                            'total_hours' => $hours,
                            'phone' => $target,
                        ]);
                    }

                    if ($member->platform === 'telegram') {
                        $telegram->sendMessage($message, 'ai', $target);

                        Log::info('Daily reminder sent via Telegram', [
                            'member' => $member->name,
                            'steam_id' => $steamId,
                            'today_matches' => $todayMatchCount,
                            'total_hours' => $hours,
                            'chat_id' => $target,
                        ]);
                    }
                }

                $this->info("Reminder sent to {$primaryMember->name} ({$todayMatchCount} matches, {$hours}).");
            } catch (\Exception $e) {
                Log::error('Failed to send daily reminder', [
                    'member' => $members->first()?->name,
                    'steam_id' => $steamId,
                    'error' => $e->getMessage(),
                ]);

                $this->error("Failed to send reminder to {$members->first()?->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function getTodayMatchesForMembers(Collection $members): Collection
    {
        $memberIds = $members->pluck('id');

        if ($memberIds->isEmpty()) {
            return new Collection;
        }

        return DotaMatch::query()
            ->whereDate('match_timestamp', today())
            ->where(function ($query) use ($memberIds) {
                foreach ($memberIds as $memberId) {
                    $query->orWhereJsonContains('members', $memberId);
                }
            })
            ->get();
    }

    private function buildMessage(string $name, int $count, string $hours): string
    {
        $templates = [
            "{name}, setiap 'last game' itu bohong 🧢. Statistik hari ini: {count} game, {hours}. Yang terakhir cuma niat lo buat berhenti 💀",
            "Laporan kebodohan hari ini 📋: {name} jalanin {count} game, buang {hours}. Kalau tujuan lo bikin badan capek dan otak kosong, ketololan besar 🪦",
            "Mantap {name} 👏, {count} match dalam sehari dengan total {hours}. Disiplin kayak gini dipertahanin terus, masa depan lo ikut AFK 🛌",
            "Guoblok 🧨, {name}, lo setor {count} game hari ini dan bakar {hours}. Latihan kagak, capek doang. Konsisten… tapi di hal ga berguna 🫠",
            "Selamat malam {name} 🌙. Hari ini lo ngabisin {hours} buat {count}x DotA. Produktif? Nggak. Tapi nyiksa diri? Performa lo top banget 🏅",
            "Laporan harian: {name} main {count}x, total {hours}. Hasilnya? Ga ada. Skill ga naik, ranking ga naik. Semangat terus ya! 🤡",
            "SELAMAT {name}! Hari ini lo berhasil buang {hours} buat main DotA {count}x. Achievement unlocked: Produktivitas = 0 🏆",
            "Bro {name}, gue pantau lo dari tadi. {count} game, {hours} habis sia-sia. Mending diinvestasiin ke hal yang bener 🙄",
            "Temen ngajak = gas terus ya? 🚀 {name} nurut aja sampe {count} match, {hours} kebakar. Keren, mental follower, bukan player 🐑",
            "'Gak enak nolak temen' 🥺 katanya. Tapi enak buang {hours} buat {count} game? Prioritas lo lucu juga ya {name} 😂",
        ];

        $template = collect($templates)->random();

        return str_replace(
            ['{name}', '{count}', '{hours}'],
            [$name, $count, $hours],
            $template
        );
    }

    private function formatHours(int $totalSeconds): string
    {
        $totalMinutes = $totalSeconds / 60;
        $hours = $totalMinutes / 60;

        // Less than an hour
        if ($hours < 1) {
            $minutes = round($totalMinutes);

            return "{$minutes} menit";
        }

        // Format hour and minutes, e.g. "1 jam 12 menit"
        $hoursPart = floor($hours);
        $minutesPart = round(($hours - $hoursPart) * 60);

        if ($minutesPart > 0) {
            return "{$hoursPart} jam {$minutesPart} menit";
        }

        return "{$hoursPart} jam";
    }
}
