<?php

namespace App\Support\Push;

/**
 * What a push notification says. [route] is an in-app path the mobile app
 * opens when the push is tapped (e.g. `/events/{id}`); null just opens the app.
 * Only send routes the app has a screen for.
 */
final class PushMessage
{
    /** @param array<string, scalar|null> $data */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $route = null,
        public readonly array $data = [],
    ) {}
}
