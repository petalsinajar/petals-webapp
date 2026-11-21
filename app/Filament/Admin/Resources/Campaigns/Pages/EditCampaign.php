<?php

namespace App\Filament\Admin\Resources\Campaigns\Pages;

use App\Filament\Admin\Resources\Campaigns\CampaignActions;
use App\Filament\Admin\Resources\Campaigns\CampaignResource;
use App\Modules\Campaigns\Services\CampaignService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

/** The composer, with test, send and schedule. Saves the draft before each of them. */
class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    private string|false|null $imageId = false;

    protected function getHeaderActions(): array
    {
        $saveFirst = fn ($action) => $action->before(fn () => $this->save(shouldRedirect: false, shouldSendSavedNotification: false))
            ->after(fn () => $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record])));

        return [
            CampaignActions::sendTest()->before(fn () => $this->save(shouldRedirect: false, shouldSendSavedNotification: false)),
            $saveFirst(CampaignActions::sendNow()),
            $saveFirst(CampaignActions::schedule()),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->imageId = array_key_exists('image_media_id', $data) ? $data['image_media_id'] : false;
        unset($data['image_media_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->imageId !== false && $this->imageId !== $this->record->image_media_id) {
            app(CampaignService::class)->setImage($this->record, $this->imageId, Auth::user());
        }
    }
}
