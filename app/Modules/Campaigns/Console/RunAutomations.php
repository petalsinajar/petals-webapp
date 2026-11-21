<?php

namespace App\Modules\Campaigns\Console;

use App\Modules\Campaigns\Services\LifecycleAutomations;
use Illuminate\Console\Command;

/** Hourly: welcome series, event countdowns, inactivity nudges, vendor summaries. */
class RunAutomations extends Command
{
    protected $signature = 'campaigns:automations';

    protected $description = 'Run the lifecycle automations that are switched on';

    public function handle(LifecycleAutomations $automations): int
    {
        foreach ($automations->run() as $automation => $count) {
            $this->line("{$automation}: {$count}");
        }

        return self::SUCCESS;
    }
}
