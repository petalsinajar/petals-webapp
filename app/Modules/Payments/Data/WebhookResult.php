<?php

namespace App\Modules\Payments\Data;

final class WebhookResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $reference,
        public readonly bool $succeeded,
        public readonly ?int $amountMinor,
        public readonly ?string $method,
        public readonly array $raw = [],
        /** Events we don't act on (e.g. transfer updates) are acknowledged and ignored. */
        public readonly bool $relevant = true,
        /** A reusable card authorization, when the provider returns one: { code, reusable, last4 }. */
        public readonly ?array $authorization = null,
    ) {}
}
