<?php

namespace App\Console\Commands;

use App\Jobs\ProcessNewsNotification;
use App\Models\Setting;
use App\Models\SteamNews;
use App\Services\SteamApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

class FetchSteamNewsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'steam:fetch-news';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch latest DotA 2 official news from Steam API';

    /**
     * Execute the console command.
     */
    public function handle(SteamApiService $steamApi): int
    {
        $this->writeLog();

        $this->info('Fetching latest DotA 2 news from Steam API...');

        // Get settings
        $minimumMatchDate = Setting::get('minimum_match_date');
        if ($minimumMatchDate) {
            $minimumMatchDate = Date::parse($minimumMatchDate);
        }

        $fetchCount = (int) Setting::get('steam_news_fetch_count', 10);
        $enabled = (bool) Setting::get('steam_news_enabled', true);

        if (! $enabled) {
            $this->warn('Steam news fetching is disabled. Set steam_news_enabled to true to enable.');

            return self::FAILURE;
        }

        // Fetch news from Steam API
        $newsItems = $steamApi->getLatestNews($fetchCount);

        if ($newsItems === null) {
            $this->error('Failed to fetch news from Steam API. Check logs for details.');

            return self::FAILURE;
        }

        if (empty($newsItems)) {
            $this->info('No official news items found.');

            return self::SUCCESS;
        }

        $this->info('Found '.count($newsItems).' official news item(s).');

        $newCount = 0;
        $skippedCount = 0;

        foreach ($newsItems as $newsItem) {
            $gid = $newsItem['gid'] ?? null;
            $publishedTimestamp = $newsItem['date'] ?? null;

            if (! $gid || ! $publishedTimestamp) {
                $this->warn('Skipping news item with missing gid or date.');
                $skippedCount++;

                continue;
            }

            // Check if already exists
            if (SteamNews::where('gid', $gid)->exists()) {
                $skippedCount++;

                continue;
            }

            $publishedAt = Date::createFromTimestamp($publishedTimestamp);

            // Filter by minimum date if set
            if ($minimumMatchDate && $publishedAt->lt($minimumMatchDate)) {
                $skippedCount++;

                continue;
            }

            // Create news record
            $steamNews = SteamNews::create([
                'gid' => $gid,
                'title' => $newsItem['title'] ?? 'Untitled',
                'url' => $newsItem['url'] ?? '',
                'author' => $newsItem['author'] ?? null,
                'contents' => $newsItem['contents'] ?? '',
                'feedname' => $newsItem['feedname'] ?? 'steam_community_announcements',
                'published_at' => $publishedAt,
                'tags' => $newsItem['tags'] ?? [],
            ]);

            $this->line("Created: {$steamNews->title}");

            // Dispatch notification job
            ProcessNewsNotification::dispatch($steamNews);

            $newCount++;
        }

        // Update last check timestamp
        Setting::set('last_steam_news_check', now()->toDateTimeString());

        $this->info("Completed: {$newCount} new, {$skippedCount} skipped.");

        return self::SUCCESS;
    }

    protected function writeLog(): void
    {
        $logPath = 'steam-news-fetch.log';

        if (Storage::disk('local')->exists($logPath)) {
            $lastExecution = Storage::disk('local')->get($logPath);
            $this->info("Last fetch news: {$lastExecution}");
        } else {
            $this->info('First time fetch news');
        }

        // Write current execution time
        $currentTime = now()->format('Y-m-d H:i:s');

        Storage::disk('local')->put($logPath, $currentTime);
    }
}
