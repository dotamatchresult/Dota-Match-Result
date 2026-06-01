<?php

namespace App\Console\Commands;

use App\Services\HeroAbilityService;
use App\Services\HeroService;
use App\Services\ItemService;
use Illuminate\Console\Command;

class SyncDotaConstants extends Command
{
    protected $signature = 'dota:sync-constants
                            {--heroes : Sync heroes only}
                            {--items : Sync items only}
                            {--abilities : Sync hero abilities and facets only}';

    protected $description = 'Sync DotA 2 constants (heroes, items, abilities, facets) from OpenDota API';

    public function handle(
        HeroService $heroService,
        ItemService $itemService,
        HeroAbilityService $heroAbilityService,
    ): int {
        $syncAll = ! $this->option('heroes') && ! $this->option('items') && ! $this->option('abilities');

        if ($syncAll || $this->option('heroes')) {
            $this->info('Syncing heroes...');
            $heroService->syncHeroes()
                ? $this->info('✓ Heroes synced successfully.')
                : $this->error('✗ Failed to sync heroes.');
        }

        if ($syncAll || $this->option('items')) {
            $this->info('Syncing items...');
            $itemService->syncItems()
                ? $this->info('✓ Items synced successfully.')
                : $this->error('✗ Failed to sync items.');
        }

        if ($syncAll || $this->option('abilities')) {
            $this->info('Syncing hero abilities and facets...');
            $heroAbilityService->sync()
                ? $this->info('✓ Hero abilities and facets synced successfully.')
                : $this->error('✗ Failed to sync hero abilities and facets.');
        }

        return self::SUCCESS;
    }
}
