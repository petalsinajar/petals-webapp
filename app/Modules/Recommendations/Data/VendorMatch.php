<?php

namespace App\Modules\Recommendations\Data;

use App\Modules\Identity\Models\Vendor;

/** A vendor who can supply a category for an event, with its score. */
final class VendorMatch
{
    /** @param  array<string, float>  $components */
    public function __construct(
        public readonly Vendor $vendor,
        public readonly string $categoryId,
        public readonly float $score,
        public readonly int $fromPriceMinor,
        public readonly string $currency,
        public readonly ?float $distanceKm,
        public readonly array $components,
    ) {}

    /** @return array<string, mixed> stored on the recommendation for the vendor card */
    public function details(): array
    {
        return [
            'from_price_minor' => $this->fromPriceMinor,
            'currency' => $this->currency,
            'distance_km' => $this->distanceKm === null ? null : round($this->distanceKm, 1),
            'components' => $this->components,
        ];
    }
}
