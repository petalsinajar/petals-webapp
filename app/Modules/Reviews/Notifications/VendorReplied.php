<?php

namespace App\Modules\Reviews\Notifications;

use App\Modules\Reviews\Models\Review;
use App\Support\Push\HasPushMessage;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** The vendor answered your review (first reply only, not edits). */
class VendorReplied extends Notification implements HasPushMessage, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Review $review)
    {
        $this->onQueue(config('eventapp.reviews.queue'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(
            $this->review->vendor->name,
            'Replied to your review.',
            route: "/vendors/{$this->review->vendor_id}/reviews",
            data: ['review_id' => $this->review->id],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'vendor_replied',
            'target' => ['type' => 'review', 'id' => $this->review->id],
            'vendor' => ['id' => $this->review->vendor_id, 'name' => $this->review->vendor->name],
        ];
    }
}
