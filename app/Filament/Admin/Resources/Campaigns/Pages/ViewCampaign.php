<?php

namespace App\Filament\Admin\Resources\Campaigns\Pages;

use App\Filament\Admin\Resources\Campaigns\CampaignActions;
use App\Filament\Admin\Resources\Campaigns\CampaignResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** The campaign's report, with its lifecycle actions (approve, back to draft, cancel). */
class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), ...CampaignActions::all()];
    }
}
