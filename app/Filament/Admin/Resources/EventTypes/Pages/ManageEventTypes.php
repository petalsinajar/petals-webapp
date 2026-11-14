<?php

namespace App\Filament\Admin\Resources\EventTypes\Pages;

use App\Filament\Admin\Resources\EventTypes\EventTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEventTypes extends ManageRecords
{
    protected static string $resource = EventTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
