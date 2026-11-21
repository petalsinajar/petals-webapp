<?php

namespace App\Modules\Campaigns\Contracts;

use App\Modules\Campaigns\Data\PushResult;
use App\Support\Push\PushMessage;

interface PushProvider
{
    /**
     * One message to every signed-in device of $userIds. Never throws for a refusal or an
     * outage: the result says so, and the batch decides whether to retry.
     *
     * @param  list<string>  $userIds
     */
    public function send(array $userIds, PushMessage $message): PushResult;
}
