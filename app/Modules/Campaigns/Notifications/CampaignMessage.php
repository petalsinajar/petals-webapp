<?php

namespace App\Modules\Campaigns\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A campaign in the in-app notifications list. Written at once by the delivery job (which
 * is already on the campaigns queue), so it isn't queued again. The app reports a tap to
 * POST /v1/campaigns/{recipient_id}/opened.
 */
class CampaignMessage extends Notification
{
    public function __construct(
        public readonly string $campaignId,
        public readonly ?string $recipientId,
        public readonly string $category,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $route,
        public readonly ?string $imageUrl = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'campaign',
            'title' => $this->title,
            'body' => $this->body,
            'route' => $this->route,
            'image_url' => $this->imageUrl,
            'category' => $this->category,
            'campaign_id' => $this->campaignId,
            'recipient_id' => $this->recipientId,
            'group_key' => 'campaign:'.$this->campaignId,
        ];
    }
}
