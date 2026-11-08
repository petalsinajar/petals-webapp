<?php

namespace App\Support\Push;

/** A notification that can also go to the recipient's phone (the `push` channel). */
interface HasPushMessage
{
    /** Null to skip the push for this recipient. */
    public function toPush(object $notifiable): ?PushMessage;
}
