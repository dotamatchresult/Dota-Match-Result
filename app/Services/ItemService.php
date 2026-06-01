<?php

namespace App\Services;

use App\Models\Item;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ItemService
{
    /**
     * Get item display name by item ID
     */
    public function getItemName(int $itemId): string
    {
        $item = Item::where('item_id', $itemId)->first();

        if ($item) {
            return $item->dname ?? $item->name;
        }

        Log::warning('Unknown item ID', ['item_id' => $itemId]);

        return "Item #{$itemId}";
    }

    /**
     * Sync items from OpenDota API
     */
    public function syncItems(): bool
    {
        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(60)->get('https://api.opendota.com/api/constants/items');

            if (! $response->successful()) {
                Log::error('Failed to fetch items from OpenDota API', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $items = $response->json();

            if (empty($items)) {
                Log::error('No items returned from OpenDota API');

                return false;
            }

            foreach ($items as $key => $item) {
                if (empty($item['id'])) {
                    continue;
                }

                Item::updateOrCreate(
                    ['item_id' => $item['id']],
                    [
                        'name' => $key,
                        'dname' => $item['dname'] ?? null,
                        'cost' => isset($item['cost']) ? (int) $item['cost'] : null,
                        'img' => $item['img'] ?? null,
                    ]
                );
            }

            Log::info('Items synced successfully', ['count' => count($items)]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to sync items', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
