<?php

namespace App\Modules\Campaigns\Data;

/** What the push provider said about one request. */
final class PushResult
{
    /**
     * @param  list<string>  $unreachableUserIds  users with no subscribed device
     * @param  bool  $retryable  an outage or rate limit, worth trying again
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly ?string $messageId = null,
        public readonly array $unreachableUserIds = [],
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
    ) {}
}
