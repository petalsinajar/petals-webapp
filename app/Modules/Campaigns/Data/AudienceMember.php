<?php

namespace App\Modules\Campaigns\Data;

/**
 * Someone a campaign run is for. [runKey] tells runs apart (see campaign_recipients.run_key);
 * [context] carries what personalisation needs (event_id, vendor_name, …).
 */
final class AudienceMember
{
    /** @param array<string, scalar|null> $context */
    public function __construct(
        public readonly string $userId,
        public readonly string $runKey = '',
        public readonly array $context = [],
    ) {}
}
