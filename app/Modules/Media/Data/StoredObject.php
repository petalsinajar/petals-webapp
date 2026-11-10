<?php

namespace App\Modules\Media\Data;

/** What storage says about an uploaded object. */
final class StoredObject
{
    public function __construct(
        public readonly int $bytes,
        public readonly ?string $contentType,
    ) {}
}
