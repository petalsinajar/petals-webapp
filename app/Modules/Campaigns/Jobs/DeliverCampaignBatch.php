<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Enums\Channel;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignDelivery;
use App\Modules\Campaigns\Services\SendWindow;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one batch on one channel, on the "campaigns" queue. Retries are counted per
 * recipient (CampaignDelivery), so the job itself keeps trying until they're all done.
 */
class DeliverCampaignBatch implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $recipientIds */
    public function __construct(
        public readonly string $campaignId,
        public readonly string $channel,
        public readonly array $recipientIds,
    ) {
        $this->onQueue(config('eventapp.campaigns.queue'));
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(CampaignDelivery $delivery): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign) {
            return;
        }

        $now = CarbonImmutable::now();
        if (SendWindow::isQuiet($now)) {
            $this->release((int) ceil($now->diffInSeconds(SendWindow::nextAllowed($now))));

            return;
        }

        if ($delivery->deliver($campaign, Channel::from($this->channel), $this->recipientIds, $now)) {
            $this->release(60 * 2 ** min(5, $this->attempts()));
        }
    }
}
