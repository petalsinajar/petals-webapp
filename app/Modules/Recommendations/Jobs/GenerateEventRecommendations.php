<?php

namespace App\Modules\Recommendations\Jobs;

use App\Modules\Events\Models\Event;
use App\Modules\Recommendations\Services\RecommendationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Works out and sends the next suggestions for one event, on the "recommendations" queue. */
class GenerateEventRecommendations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** One job per event at a time, even if the command runs twice. */
    public int $uniqueFor = 6 * 3600;

    public function __construct(public readonly string $eventId)
    {
        $this->onQueue(config('eventapp.recommendations.queue'));
    }

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(RecommendationService $service): void
    {
        $event = Event::find($this->eventId);
        if (! $event) {
            return;
        }

        // A backed-up queue can push the job into the night: wait for the morning instead.
        $now = CarbonImmutable::now();
        if ($service->rules()->isQuiet($now, $event->timezone)) {
            $this->release((int) ceil($now->diffInSeconds($service->rules()->nextSendAt($now, $event->timezone))));

            return;
        }

        $service->run($event, $now);
    }
}
