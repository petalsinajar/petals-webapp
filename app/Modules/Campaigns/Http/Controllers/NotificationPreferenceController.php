<?php

namespace App\Modules\Campaigns\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Campaigns\Enums\Channel;
use App\Modules\Campaigns\Models\SmsOptOut;
use App\Modules\Campaigns\Services\Preferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Which marketing messages the user gets, per channel and category. */
class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly Preferences $preferences) {}

    /** GET /v1/me/notification-preferences */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'channels' => $this->preferences->forUser($user),
            'categories' => collect(config('eventapp.campaigns.categories'))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            // Replied STOP: no marketing SMS until an SMS category is turned on again here.
            'sms_stopped' => filled($user->phone) && SmsOptOut::where('phone', $user->phone)->exists(),
        ]);
    }

    /** PUT /v1/me/notification-preferences { channels: { push: { promotions: false }, … } } — only what's sent changes. */
    public function update(Request $request): JsonResponse
    {
        $channels = array_column(Channel::cases(), 'value');
        $categories = Preferences::categories();
        $data = $request->validate([
            'channels' => ['required', 'array', function ($attribute, $value, $fail) use ($channels, $categories) {
                foreach ($value as $channel => $prefs) {
                    if (! in_array($channel, $channels, true) || ! is_array($prefs)) {
                        return $fail("Unknown channel: {$channel}.");
                    }
                    foreach ($prefs as $category => $on) {
                        if (! in_array($category, $categories, true)) {
                            return $fail("Unknown category: {$category}.");
                        }
                    }
                }
            }],
            'channels.*.*' => ['boolean'],
        ]);

        $this->preferences->update($request->user(), array_map(
            fn ($prefs) => array_map(fn ($on) => filter_var($on, FILTER_VALIDATE_BOOLEAN), $prefs),
            $data['channels'],
        ));

        return $this->show($request);
    }
}
