<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Enums\Channel;
use App\Modules\Campaigns\Enums\SkipReason;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who in an audience can get a campaign on a channel, and why not. Used when a campaign
 * runs (skips are recorded with the reason) and for the composer's estimate, so the two
 * always agree. Frequency caps are checked at delivery instead (see CampaignDelivery).
 */
class Eligibility
{
    public function __construct(private readonly Preferences $preferences) {}

    /** The columns the checks read. */
    public const USER_COLUMNS = ['id', 'status', 'deleted_at', 'phone', 'phone_verified_at', 'settings', 'display_name', 'city'];

    /**
     * @param  Collection<string, User>  $users  keyed by id; ids missing from it are deleted accounts
     * @param  list<string>  $ids
     * @return array<string, ?SkipReason> user id => null (eligible) or why not
     */
    public function check(Campaign $campaign, Channel $channel, Collection $users, array $ids): array
    {
        $active = $users->filter(fn (User $u) => $u->status === 'active' && $u->deleted_at === null);
        $optedOut = $this->preferences->optedOut($active->values(), $channel, $campaign->category);

        $withDevice = $channel === Channel::Push
            ? DB::table('devices')->whereIn('user_id', $active->keys())->distinct()->pluck('user_id')->flip()->all()
            : [];
        $stopped = $channel === Channel::Sms && $campaign->isMarketing()
            ? $this->preferences->smsStopped($active->pluck('phone')->filter()->values()->all())
            : [];

        $result = [];
        foreach ($ids as $id) {
            $user = $active->get($id);
            $result[$id] = match (true) {
                $user === null => SkipReason::Inactive,
                isset($optedOut[$id]) => SkipReason::OptedOut,
                $channel === Channel::Push && ! isset($withDevice[$id]) => SkipReason::NoDevice,
                $channel === Channel::Sms && ($user->phone_verified_at === null || blank($user->phone)) => SkipReason::NoVerifiedPhone,
                $channel === Channel::Sms && isset($stopped[$user->phone]) => SkipReason::SmsStopped,
                default => null,
            };
        }

        return $result;
    }

    /** @param list<string> $ids @return Collection<string, User> */
    public static function loadUsers(array $ids): Collection
    {
        return User::withTrashed()->whereIn('id', $ids)->get(self::USER_COLUMNS)->keyBy('id');
    }
}
