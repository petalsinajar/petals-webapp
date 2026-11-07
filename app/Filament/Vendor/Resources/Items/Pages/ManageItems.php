<?php

namespace App\Filament\Vendor\Resources\Items\Pages;

use App\Filament\Vendor\Resources\Items\ItemResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageItems extends ManageRecords
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(function (array $data): array {
                // Items are searched by location, copied from the vendor's base.
                $vendor = Filament::getTenant();
                if ($vendor->base_lat !== null && $vendor->base_lng !== null) {
                    $data['geo'] = ['type' => 'Point', 'coordinates' => [(float) $vendor->base_lng, (float) $vendor->base_lat]];
                }

                return $data;
            }),
        ];
    }
}
