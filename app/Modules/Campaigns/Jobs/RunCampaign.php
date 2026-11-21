<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Builds a campaign's recipients and queues their delivery, on the "campaigns" queue. */
class RunCampaign implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $campaignId)
    {
        $this->onQueue(config('eventapp.campaigns.queue'));
    }

    public function uniqueId(): string
    {
        return $this->campaignId;
    }

    public function handle(CampaignService $service): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign) {
            return;
        }

        // A backed-up queue can push this into the night: wait for the morning instead.
        $waitUntil = $service->run($campaign);
        if ($waitUntil !== null) {
            $this->release((int) ceil(CarbonImmutable::now()->diffInSeconds($waitUntil)));
        }
    }
}
