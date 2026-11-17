<?php

namespace App\Filament\Admin\Resources\Items\Pages;

use App\Filament\Admin\Resources\Items\ItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageItems extends ManageRecords
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add item for a vendor')
                // Opened from a vendor's row: that vendor is already picked.
                ->fillForm(fn () => ['vendor_id' => $this->tableFilters['vendor']['value'] ?? null]),
        ];
    }
}
