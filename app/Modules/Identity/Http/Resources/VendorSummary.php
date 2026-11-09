<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Vendor;

/** The few vendor fields a card needs (item, package and suggestion cards). */
final class VendorSummary
{
    /** @return array<string, mixed>|null */
    public static function of(?Vendor $vendor): ?array
    {
        if (! $vendor) {
            return null;
        }

        return [
            'type' => 'vendor',
            'id' => $vendor->id,
            'handle' => $vendor->handle,
            'name' => $vendor->name,
            'logo_url' => $vendor->logo_url,
            'city' => $vendor->city,
            'is_verified' => $vendor->isVerified(),
            'rating' => ['avg' => $vendor->rating_avg, 'count' => $vendor->rating_count],
        ];
    }
}
