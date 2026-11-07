<?php

namespace App\Modules\Catalog\Availability;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly string $variantId,
        public readonly string $day,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct("Only {$available} available on {$day} (requested {$requested}).");
    }

    /** Rendered as a validation-style error so the app can show it next to the item. */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['stock' => [$this->getMessage()]],
            'stock' => [
                'variant_id' => $this->variantId,
                'day' => $this->day,
                'requested' => $this->requested,
                'available' => $this->available,
            ],
        ], 409);
    }
}
