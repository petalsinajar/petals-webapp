<?php

namespace App\Filament\Admin\Resources\Campaigns\Pages;

use App\Filament\Admin\Resources\Campaigns\CampaignResource;
use App\Modules\Campaigns\Services\CampaignService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

/** Saves a draft; sending and scheduling are actions on the next page. */
class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    private ?string $imageId = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->imageId = $data['image_media_id'] ?? null;
        unset($data['image_media_id']);

        return [...$data, 'created_by' => Auth::id()];
    }

    protected function afterCreate(): void
    {
        if ($this->imageId) {
            app(CampaignService::class)->setImage($this->record, $this->imageId, Auth::user());
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
