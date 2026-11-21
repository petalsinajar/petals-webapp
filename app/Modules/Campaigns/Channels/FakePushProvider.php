<?php

namespace App\Modules\Campaigns\Channels;

use App\Modules\Campaigns\Contracts\PushProvider;
use App\Modules\Campaigns\Data\PushResult;
use App\Support\Push\PushMessage;

/** Tests: records every request. Users in $unreachable have no device; $failNext fails requests. */
class FakePushProvider implements PushProvider
{
    /** @var list<array{user_ids: list<string>, message: PushMessage}> */
    public array $sent = [];

    /** @var list<string> */
    public array $unreachable = [];

    /** Requests to fail (retryable) before succeeding again. */
    public int $failNext = 0;

    public function send(array $userIds, PushMessage $message): PushResult
    {
        if ($this->failNext > 0) {
            $this->failNext--;

            return new PushResult(false, error: 'fake outage', retryable: true);
        }
        $this->sent[] = ['user_ids' => array_values($userIds), 'message' => $message];

        return new PushResult(
            true,
            messageId: 'push-'.count($this->sent),
            unreachableUserIds: array_values(array_intersect($userIds, $this->unreachable)),
        );
    }

    /** @return list<string> every user id a push went to */
    public function recipients(): array
    {
        return array_merge(...array_map(fn ($s) => $s['user_ids'], $this->sent ?: [['user_ids' => []]]));
    }
}
