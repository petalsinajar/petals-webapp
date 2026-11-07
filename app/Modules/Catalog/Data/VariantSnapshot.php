<?php

namespace App\Modules\Catalog\Data;

/** Everything a booking needs to copy from the catalog at the moment of booking. */
final class VariantSnapshot
{
    public function __construct(
        public readonly string $itemId,
        public readonly string $variantId,
        public readonly string $vendorId,
        public readonly string $title,
        public readonly int $quantity,
        public readonly int $unitPriceMinor,
        public readonly string $currency,
        public readonly int $minQty,
        public readonly int $damageDepositMinor,
        public readonly bool $instantBook,
        public readonly bool $requiresSetup,
        public readonly bool $active,
    ) {}
}
