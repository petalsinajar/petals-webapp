<?php

namespace App\Filament\Vendor\Resources\Items\Pages;

use App\Filament\Vendor\Resources\Items\ItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageItems extends ManageRecords
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
