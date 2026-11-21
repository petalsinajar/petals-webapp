<?php

namespace App\Filament\Admin\Resources\Campaigns\Pages;

use App\Filament\Admin\Resources\Campaigns\CampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCampaigns extends ListRecords
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
