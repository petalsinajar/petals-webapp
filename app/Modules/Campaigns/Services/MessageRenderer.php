<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Identity\Models\User;

/**
 * Fills personalisation tokens. Unknown tokens are left as typed so a typo shows in the
 * preview. {event_title} is the recipient's event (automations), else their next upcoming one.
 */
final class MessageRenderer
{
    public const TOKENS = ['{first_name}', '{city}', '{event_title}', '{vendor_name}', '{week_bookings}'];

    /**
     * @param  array<string, scalar|null>  $context
     * @param  ?string  $nextEventTitle  the recipient's next upcoming event, if looked up
     */
    public static function render(string $template, User $user, array $context = [], ?string $nextEventTitle = null): string
    {
        $firstName = trim(strtok(trim((string) $user->display_name), ' ') ?: '');

        return strtr($template, [
            '{first_name}' => $firstName !== '' ? $firstName : 'there',
            '{city}' => filled($user->city) ? $user->city : 'you',
            '{event_title}' => (string) ($context['event_title'] ?? $nextEventTitle ?? 'your event'),
            '{vendor_name}' => (string) ($context['vendor_name'] ?? 'your business'),
            '{week_bookings}' => (string) ($context['week_bookings'] ?? 0),
        ]);
    }

    public static function usesEventTitle(?string ...$templates): bool
    {
        foreach ($templates as $t) {
            if ($t !== null && str_contains($t, '{event_title}')) {
                return true;
            }
        }

        return false;
    }

    /** A stand-in for previews: an Ama in Accra with a wedding coming up. */
    public static function sample(string $template): string
    {
        return strtr($template, [
            '{first_name}' => 'Ama', '{city}' => 'Accra', '{event_title}' => 'Ama & Kofi’s Wedding',
            '{vendor_name}' => 'Royal Canopies', '{week_bookings}' => '4',
        ]);
    }
}
