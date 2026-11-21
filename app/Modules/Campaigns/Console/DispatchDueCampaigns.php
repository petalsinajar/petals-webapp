<?php

namespace App\Modules\Campaigns\Console;

use App\Modules\Campaigns\Enums\CampaignStatus;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignService;
use Illuminate\Console\Command;

/** Every minute: queues the scheduled campaigns that are due. */
class DispatchDueCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch';

    protected $description = 'Queue scheduled campaigns that are due';

    public function handle(CampaignService $service): int
    {
        $queued = 0;
        Campaign::where('status', CampaignStatus::Scheduled->value)
            ->whereNull('automation')
            ->where('scheduled_at', '<=', now())
            ->each(function (Campaign $campaign) use ($service, &$queued) {
                $queued += (int) $service->dispatchIfDue($campaign);
            });

        $this->info("Queued {$queued} campaigns.");

        return self::SUCCESS;
    }
}
