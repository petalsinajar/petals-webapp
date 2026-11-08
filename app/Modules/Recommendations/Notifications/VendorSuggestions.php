<?php

namespace App\Modules\Recommendations\Notifications;

use App\Modules\Events\Models\Event;
use App\Modules\Recommendations\Enums\RecommendationReason;
use App\Support\Push\HasPushMessage;
use App\Support\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * "3 sound vendors are free on 12 Dec" — one grouped notification per batch of suggestions,
 * in the in-app list and as a push that opens the event.
 */
class VendorSuggestions extends Notification implements HasPushMessage, ShouldQueue
{
    use Queueable;

    /** @param  list<array{id: string, handle: string, name: string, logo_url: ?string}>  $vendors */
    public function __construct(
        public readonly Event $event,
        public readonly string $category,
        public readonly RecommendationReason $reason,
        public readonly array $vendors,
    ) {
        $this->onQueue(config('eventapp.recommendations.queue'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return config('eventapp.recommendations.channels');
    }

    public function title(): string
    {
        $count = count($this->vendors);
        $label = config("eventapp.recommendations.category_labels.{$this->category}", $this->category);
        $date = $this->event->starts_at->setTimezone($this->event->timezone)->format('j M');

        return $count.' '.$label.' '.Str::plural('vendor', $count).' '.($count === 1 ? 'is' : 'are')." free on {$date}";
    }

    public function body(): string
    {
        $title = $this->event->title;
        $label = config("eventapp.recommendations.category_labels.{$this->category}", $this->category);

        return match ($this->reason) {
            RecommendationReason::Checklist => "Start your checklist for {$title}: these vendors have {$label} available on your date.",
            RecommendationReason::MissingCategory => "You haven’t booked {$label} for {$title} yet. These vendors are available.",
            RecommendationReason::LastCall => "Last call for {$title}: book {$label} soon, before these dates fill up.",
        };
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(
            $this->title(),
            $this->body(),
            route: "/events/{$this->event->id}",
            data: ['event_id' => $this->event->id, 'category_id' => $this->category],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'vendor_suggestions',
            'title' => $this->title(),
            'body' => $this->body(),
            'target' => ['type' => 'event', 'id' => $this->event->id],
            'deep_link' => str_replace('{event}', $this->event->id, config('eventapp.recommendations.deep_link')),
            'category_id' => $this->category,
            'reason' => $this->reason->value,
            'vendors' => $this->vendors,
            'group_key' => 'suggestions:'.$this->event->id,
        ];
    }
}
