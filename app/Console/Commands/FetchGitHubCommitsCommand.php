<?php

namespace App\Console\Commands;

use App\Jobs\ProcessNewsNotification;
use App\Models\Setting;
use App\Models\SteamNews;
use App\Services\GitHubService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FetchGitHubCommitsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'github:fetch-commits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch latest SteamDB DotA 2 repository commits and notify on significant activity';

    /**
     * Execute the console command.
     */
    public function handle(GitHubService $gitHub): int
    {
        $this->writeLog();

        $this->info('Fetching latest SteamDB GameTracking-Dota2 commits...');

        $enabled = (bool) Setting::get('github_commits_enabled', true);

        if (! $enabled) {
            $this->warn('GitHub commits fetching is disabled. Set github_commits_enabled to true to enable.');

            return self::FAILURE;
        }

        // Fetch commits from the last hour
        $commits = $gitHub->getRecentCommits(now()->subHour());

        if ($commits === null) {
            $this->error('Failed to fetch commits from GitHub API. Check logs for details.');

            return self::FAILURE;
        }

        if (empty($commits)) {
            $this->info('No commits found.');

            return self::SUCCESS;
        }

        $this->info('Found '.count($commits).' commit(s) in the last hour.');

        // Latest commit is always index 0 (API returns newest-first)
        $latestSha = $commits[0]['sha'] ?? null;

        if (! $latestSha) {
            $this->error('Could not determine latest commit SHA.');

            return self::FAILURE;
        }

        // Sum up changed file counts from all commits within the last hour
        $totalFiles = 0;
        foreach ($commits as $commit) {
            $message = $commit['commit']['message'] ?? '';
            $count = $gitHub->extractFileCount($message);
            $totalFiles += $count;

            $shortSha = substr($commit['sha'], 0, 7);
            $this->line("  [{$shortSha}] {$count} files — ".Str::limit($message, 60));
        }

        $this->info("Total files changed: {$totalFiles}");

        // Always update the watermark to the latest SHA
        Setting::set('steamdb_dota2_commit_sha', $latestSha);

        $minFiles = (int) config('dota.github.min_files', 10);

        if ($totalFiles < $minFiles) {
            $this->info("Below threshold ({$minFiles} files). No notification will be sent.");

            return self::SUCCESS;
        }

        // Use the latest SHA as the gid so we don't create duplicates for the same batch
        if (SteamNews::where('gid', $latestSha)->exists()) {
            $this->warn("SteamNews with gid {$latestSha} already exists. Skipping.");

            return self::SUCCESS;
        }

        $steamNews = SteamNews::create([
            'gid' => $latestSha,
            'title' => "Aktifitas Baru Terdeteksi - {$totalFiles} File Diubah",
            'url' => 'https://github.com/SteamDatabase/GameTracking-Dota2/commits',
            'author' => 'SteamDB',
            'contents' => $this->buildContents($totalFiles),
            'feedname' => 'github',
            'published_at' => now(),
            'tags' => ['github', 'update'],
        ]);

        $this->info("Created SteamNews: {$steamNews->title}");

        ProcessNewsNotification::dispatch($steamNews);

        $this->info('Notification job dispatched.');

        return self::SUCCESS;
    }

    /**
     * Build engaging Indonesian contents based on the scale of changed files.
     */
    private function buildContents(int $fileCount): string
    {
        return match (true) {
            $fileCount >= 301 => $this->contentsMassive($fileCount),
            $fileCount >= 101 => $this->contentsSignificant($fileCount),
            $fileCount >= 31 => $this->contentsModerate($fileCount),
            default => $this->contentsMinor($fileCount),
        };
    }

    private function contentsMinor(int $fileCount): string
    {
        return "Ada perubahan kecil yang terdeteksi di belakang layar DotA 2 — sebanyak {$fileCount} file diubah oleh tim developer. "
            ."Perubahannya belum besar dan mungkin hanya penyesuaian teknis ringan, tapi ini bisa jadi tanda awal dari sesuatu. "
            .'Belum perlu panik, tapi tetap pantengin updatenya ya! 👀';
    }

    private function contentsModerate(int $fileCount): string
    {
        return "Aktivitas yang cukup menarik terdeteksi di repositori DotA 2 — {$fileCount} file telah diubah. "
            .'Jumlah ini cukup signifikan dan bisa menandakan adanya penyesuaian hero, perubahan item, atau perbaikan mekanik yang sedang digarap. '
            .'Patch kecil mungkin sudah di depan mata! Siap-siap untuk meta baru? 🎮';
    }

    private function contentsSignificant(int $fileCount): string
    {
        return "🚨 Update besar sedang mendekat! Sebanyak {$fileCount} file diubah di repositori resmi DotA 2 — "
            .'ini adalah tanda yang cukup kuat bahwa ada patch signifikan, kemungkinan hero rework, item baru, atau perubahan besar pada game yang sedang disiapkan. '
            .'Komunitas DotA 2 biasanya menyambut perubahan sebesar ini dengan antusias. '
            .'Bersiaplah untuk penyesuaian strategi dan lineup! ⚔️';
    }

    private function contentsMassive(int $fileCount): string
    {
        return "⚠️ AKTIVITAS MASIF TERDETEKSI! Tidak kurang dari {$fileCount} file diubah sekaligus di repositori DotA 2 — "
            .'angka sebesar ini hampir selalu mengisyaratkan patch raksasa, kemunculan hero baru, event spesial berskala besar, '
            .'atau bahkan rombakan total pada beberapa aspek game. '
            .'Ini adalah salah satu aktivitas terbesar yang pernah tercatat. '
            .'Tandai kalendermu, persiapkan pool hero, dan bersiap untuk perubahan yang akan mengubah meta! 🌋🔥';
    }

    protected function writeLog(): void
    {
        $logPath = 'github-commits-fetch.log';

        if (Storage::disk('local')->exists($logPath)) {
            $lastExecution = Storage::disk('local')->get($logPath);
            $this->info("Last fetch commits: {$lastExecution}");
        } else {
            $this->info('First time fetching commits');
        }

        Storage::disk('local')->put($logPath, now()->format('Y-m-d H:i:s'));
    }
}
