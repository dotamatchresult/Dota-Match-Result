<?php

use App\Jobs\CheckParseStatus;
use App\Jobs\RequestMatchParse;
use App\Models\Destination;
use App\Models\DotaMatch;
use App\Models\SteamNews;
use App\Services\SteamApiService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('whatsapp', function () {
    $fonnteApiKey = Destination::mainTokenForCode(Destination::CODE_WHATSAPP);
    $phoneNumber = Destination::targetForCode(Destination::CODE_WHATSAPP);

    if (! $fonnteApiKey || ! $phoneNumber) {
        $this->error('WhatsApp destination is not configured properly.');

        return;
    }

    /** @var \Illuminate\Http\Client\Response $response */
    $response = Http::withHeaders([
        'Authorization' => $fonnteApiKey,
    ])->post('https://api.fonnte.com/send', [
        'target' => $phoneNumber,
        'message' => "https://image-preview-delta.vercel.app/api/preview?id=8734165455\n\nWoreworewo",
        'countryCode' => '62',
        // 'preview' => false,
    ]);

    $this->info("Fonnte Key: {$fonnteApiKey}");
    $this->info("Phone Number: {$phoneNumber}");
    $this->info("Fonnte API response body: {$response->body()}");
})->purpose('WhatsApp command placeholder');

Artisan::command('telegram', function () {
    $botApiKey = Destination::mainTokenForCode(Destination::CODE_TELEGRAM);
    $groupId = Destination::targetForCode(Destination::CODE_TELEGRAM);

    if (! $botApiKey || ! $groupId) {
        $this->error('Telegram destination is not configured properly.');

        return;
    }

    Http::post('https://api.telegram.org/bot'.$botApiKey.'/sendMessage', [
        'chat_id' => $groupId,
        'text' => 'Woreworewo',
    ]);
})->purpose('Telegram command placeholder');

Artisan::command('matches:analyze {match_id}', function (SteamApiService $steamApi, $match_id) {
    $dotaMatch = DotaMatch::where('match_id', $match_id)->first();

    if (! $dotaMatch) {
        // Fetching new match
        $this->info("Fetching new match {$match_id}...");

        Artisan::call('matches:check', ['match_id' => $match_id]);

        // Create match record
        $dotaMatch = DotaMatch::where('match_id', $match_id)->first();

        if (! $dotaMatch) {
            $this->error("Failed to fetch match {$match_id}.");

            return;
        }

        $this->info("Match {$match_id} created successfully.");
    }

    // If match is a loss, request parsing for AI analysis
    if ($dotaMatch->outcome !== 'Lost') {
        $this->info("Match {$dotaMatch->match_id} is not a loss. No analysis needed. ({$dotaMatch->outcome})");

        return;
    }

    // Check parse status
    if ($dotaMatch->parse_status === 'parsed') {
        $this->info("Match {$dotaMatch->match_id} is already parsed. No need to request parsing.");
    } elseif ($dotaMatch->parse_status === 'failed') {
        $this->info("Match {$dotaMatch->match_id} parsing has failed previously. No need to request parsing.");

        return;
    } elseif ($dotaMatch->parse_status !== 'parsing') {
        $this->info("Requesting parsing for match {$dotaMatch->match_id}...");

        RequestMatchParse::dispatch($dotaMatch);

        $this->info('Parsing requested. Please wait... (5 minutes)');

        // $sleepDuration = 5 * 60;
        $sleepDuration = 5;
        $bar = $this->output->createProgressBar($sleepDuration);

        $bar->start();

        for ($i = 0; $i < $sleepDuration; $i++) {
            $this->output->write('.');

            $bar->advance();
            sleep(1); // Sleep for 1 second
        }

        $bar->finish();
        $this->info("\n");
    }

    // Dispatch job to check parse status
    CheckParseStatus::dispatch($dotaMatch);

    $this->info("Dispatched job to check parse status for match {$dotaMatch->match_id}.");
})->purpose('Display an inspiring quote');

Schedule::command('matches:check')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

Schedule::command('matches:request-parses')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('matches:check-parse-status')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->runInBackground();

Schedule::command('steam:fetch-news')
    ->hourly()
    ->withoutOverlapping(5)
    ->runInBackground();

Schedule::command('github:fetch-commits')
    ->hourlyAt(5)
    ->when(fn () => SteamNews::where('feedname', '!=', 'github')
        ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
        ->doesntExist())
    ->withoutOverlapping(5)
    ->runInBackground();

Schedule::command('matches:weekly-summary')
    ->weekly()
    ->mondays()
    ->at('10:00')
    ->when(fn () => config('dota.weekly_summary.enabled'))
    ->withoutOverlapping(5)
    ->runInBackground();

Schedule::command('dota:sync-constants')
    ->quarterly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('matches:daily-reminder')
    ->dailyAt('23:59')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('challenges:assign-daily')
    ->dailyAt('00:00')
    ->timezone(config('dota.daily_challenge.timezone', 'Asia/Jakarta'))
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('challenges:send-notifications')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
