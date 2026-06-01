<?php

namespace App\Filament\Resources\Settings\Pages;

use App\Filament\Resources\Settings\SettingResource;
use App\Services\HeroAbilityService;
use App\Services\HeroService;
use App\Services\ItemService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSettings extends ListRecords
{
    protected static string $resource = SettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncHeroes')
                ->label('Sync Heroes')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Sync Heroes from OpenDota')
                ->modalDescription('This will fetch the latest hero data from OpenDota API and update the database.')
                ->modalSubmitActionLabel('Sync')
                ->action(function (HeroService $heroService) {
                    $success = $heroService->syncHeroes();

                    if ($success) {
                        Notification::make()
                            ->title('Heroes synced successfully')
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Failed to sync heroes')
                            ->body('Please check the logs for more information.')
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('syncItems')
                ->label('Sync Items')
                ->icon('heroicon-o-archive-box')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Sync Items from OpenDota')
                ->modalDescription('This will fetch the latest item data from OpenDota API and update the database.')
                ->modalSubmitActionLabel('Sync')
                ->action(function (ItemService $itemService) {
                    $success = $itemService->syncItems();

                    if ($success) {
                        Notification::make()
                            ->title('Items synced successfully')
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Failed to sync items')
                            ->body('Please check the logs for more information.')
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('syncHeroAbilities')
                ->label('Sync Abilities & Facets')
                ->icon('heroicon-o-bolt')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Sync Hero Abilities & Facets from OpenDota')
                ->modalDescription('This will fetch the latest hero abilities and facets data from OpenDota API and update the database.')
                ->modalSubmitActionLabel('Sync')
                ->action(function (HeroAbilityService $heroAbilityService) {
                    $success = $heroAbilityService->sync();

                    if ($success) {
                        Notification::make()
                            ->title('Abilities & facets synced successfully')
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Failed to sync abilities & facets')
                            ->body('Please check the logs for more information.')
                            ->danger()
                            ->send();
                    }
                }),
            CreateAction::make(),
        ];
    }
}
