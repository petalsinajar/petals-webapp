<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Enums\Channel;
use App\Modules\Campaigns\Models\NotificationPreference;
use App\Modules\Campaigns\Models\SmsOptOut;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who wants which campaign messages. A channel/category is on unless the user turned it
 * off (notification_preferences), or turned the whole channel off in the app's existing
 * switches (settings.notify.push / .sms). Numbers that replied STOP get no marketing SMS.
 */
class Preferences
{
    /** @return list<string> */
    public static function categories(): array
    {
        return array_keys(config('eventapp.campaigns.categories'));
    }

    /** @return array<string, array<string, bool>> channel => category => on */
    public function forUser(User $user): array
    {
        $saved = NotificationPreference::where('user_id', $user->id)->get()
            ->mapWithKeys(fn ($p) => ["{$p->channel}.{$p->category}" => $p->enabled]);

        $matrix = [];
        foreach (Channel::cases() as $channel) {
            foreach (self::categories() as $category) {
                $matrix[$channel->value][$category] = $saved["{$channel->value}.{$category}"] ?? true;
            }
        }

        return $matrix;
    }

    /**
     * Saves the switches given (others keep their value). Turning any SMS category on
     * again also lifts a STOP for the user's number: they asked for it in the app.
     *
     * @param  array<string, array<string, bool>>  $changes
     */
    public function update(User $user, array $changes): void
    {
        DB::transaction(function () use ($user, $changes) {
            foreach ($changes as $channel => $categories) {
                foreach ($categories as $category => $enabled) {
                    NotificationPreference::updateOrCreate(
                        ['user_id' => $user->id, 'channel' => $channel, 'category' => $category],
                        ['enabled' => (bool) $enabled],
                    );
                }
            }
            if (in_array(true, $changes[Channel::Sms->value] ?? [], true) && filled($user->phone)) {
                SmsOptOut::where('phone', $user->phone)->delete();
            }
        });
    }

    /**
     * The users among $users who don't want $category on $channel.
     *
     * @param  iterable<User>  $users
     * @return array<string, true> user id => true
     */
    public function optedOut(iterable $users, Channel $channel, string $category): array
    {
        $out = [];
        $ids = [];
        $master = match ($channel) {
            Channel::Push => 'notify.push',
            Channel::Sms => 'notify.sms',
            Channel::InApp => null,
        };
        foreach ($users as $user) {
            $ids[] = $user->id;
            if ($master && data_get($user->settings, $master, true) === false) {
                $out[$user->id] = true;
            }
        }

        NotificationPreference::whereIn('user_id', $ids)
            ->where('channel', $channel->value)->where('category', $category)->where('enabled', false)
            ->pluck('user_id')
            ->each(function (string $id) use (&$out) {
                $out[$id] = true;
            });

        return $out;
    }

    /**
     * @param  list<string>  $phones
     * @return array<string, true> phone => true
     */
    public function smsStopped(array $phones): array
    {
        return SmsOptOut::whereIn('phone', array_filter($phones))->pluck('phone')->flip()->map(fn () => true)->all();
    }

    public function stopSms(string $phone, string $source = 'stop_reply'): void
    {
        SmsOptOut::firstOrCreate(['phone' => $phone], ['source' => $source]);
    }
}
