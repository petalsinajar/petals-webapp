<?php

namespace App\Filament\Admin\Resources\CampaignSegments\Pages;

use App\Filament\Admin\Resources\CampaignSegments\CampaignSegmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageCampaignSegments extends ManageRecords
{
    protected static string $resource = CampaignSegmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data) => [
                ...CampaignSegmentResource::withCleanFilters($data),
                'created_by' => Auth::id(),
            ]),
        ];
    }
}
