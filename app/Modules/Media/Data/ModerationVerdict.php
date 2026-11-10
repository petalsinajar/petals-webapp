<?php

namespace App\Modules\Media\Data;

final class ModerationVerdict
{
    public function __construct(
        public readonly bool $approved,
        public readonly ?string $reason = null,
    ) {}

    public static function approve(): self
    {
        return new self(true);
    }

    public static function reject(string $reason): self
    {
        return new self(false, $reason);
    }
}
