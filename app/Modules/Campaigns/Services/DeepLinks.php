<?php

namespace App\Modules\Campaigns\Services;

/** A campaign's deep link ({type, id}) as the in-app path the mobile app opens. */
final class DeepLinks
{
    /** @return array<string, string> type => label, for the composer */
    public static function options(): array
    {
        return array_map(fn (array $link) => $link['label'], config('eventapp.campaigns.deep_links'));
    }

    public static function needsId(?string $type): bool
    {
        return (bool) config("eventapp.campaigns.deep_links.{$type}.needs_id", false);
    }

    /**
     * Automations link to the recipient's own event: an event link without an id uses
     * context.event_id.
     *
     * @param  array{type?: string, id?: string|null}|null  $link
     * @param  array<string, scalar|null>  $context
     */
    public static function path(?array $link, array $context = []): ?string
    {
        $type = $link['type'] ?? null;
        $config = $type ? config("eventapp.campaigns.deep_links.{$type}") : null;
        if (! $config) {
            return null;
        }
        $id = ($link['id'] ?? null) ?: ($type === 'event' ? ($context['event_id'] ?? null) : null);
        if ($config['needs_id'] && blank($id)) {
            return null;
        }

        return str_replace('{id}', (string) $id, $config['path']);
    }
}
