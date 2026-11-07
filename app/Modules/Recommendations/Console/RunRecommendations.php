<?php

namespace App\Modules\Recommendations\Console;

use App\Modules\Events\Models\Event;
use App\Modules\Recommendations\Jobs\GenerateEventRecommendations;
use App\Modules\Recommendations\Services\RecommendationRules;
use App\Modules\Recommendations\Services\RecommendationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily: queues one job per event that could be due for suggestions. The command only
 * picks events; matching and sending happen in the jobs, timed for each event's daytime.
 */
class RunRecommendations extends Command
{
    protected $signature = 'recommendations:run';

    protected $description = 'Queue vendor suggestions for upcoming events';

    public function handle(): int
    {
        $rules = RecommendationRules::fromConfig();
        if (! $rules->config('enabled')) {
            $this->info('Recommendations are disabled.');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        $horizon = $now->addDays(max($rules->config('stages.missing_category.from_days'), $rules->config('stages.last_call.from_days')));
        $createdSince = $now->subHours($rules->config('stages.checklist.created_within_hours'));
        $queued = 0;

        Event::query()
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->whereIn('type', array_keys($rules->config('required_categories')))
            ->where('starts_at', '>', $now)
            ->where(fn ($q) => $q->where('starts_at', '<=', $horizon)->orWhere('created_at', '>=', $createdSince))
            ->with('host:id,settings')
            ->chunkById(500, function ($events) use ($rules, $now, &$queued) {
                foreach ($events as $event) {
                    if (! $event->host || ! RecommendationService::wantsSuggestions($event->host)) {
                        continue;
                    }

                    GenerateEventRecommendations::dispatch($event->id)->delay($rules->nextSendAt($now, $event->timezone));
                    $queued++;
                }
            });

        $this->info("Queued suggestions for {$queued} events.");

        return self::SUCCESS;
    }
}
