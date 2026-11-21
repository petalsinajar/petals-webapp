<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Data\AudienceMember;
use App\Modules\Campaigns\Enums\Channel;
use App\Modules\Campaigns\Enums\RecipientStatus;
use App\Modules\Campaigns\Enums\SkipReason;
use App\Modules\Campaigns\Jobs\DeliverCampaignBatch;
use App\Modules\Campaigns\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

/**
 * Turns an audience into campaign_recipients rows (queued, or skipped with the reason) and
 * queues their delivery in batches, spread out to the channel's rate. A (campaign, run,
 * user, channel) row is only ever created once, so running again never sends twice.
 */
class CampaignDispatcher
{
    private const CHUNK = 1000;

    /** Recipients per delivery job. Push is one provider request per batch. */
    private const BATCH = ['sms' => 100, 'in_app' => 500];

    public function __construct(private readonly Eligibility $eligibility) {}

    /**
     * @param  iterable<AudienceMember>  $members
     * @return array{queued: int, skipped: int}
     */
    public function run(Campaign $campaign, iterable $members, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $pending = [];
        $dispatched = [];
        $totals = ['queued' => 0, 'skipped' => 0];

        LazyCollection::make(fn () => yield from $members)
            ->chunk(self::CHUNK)
            ->each(function (LazyCollection $chunk) use ($campaign, $now, &$pending, &$dispatched, &$totals) {
                $chunk = $chunk->values()->all();
                $ids = array_values(array_unique(array_map(fn (AudienceMember $m) => $m->userId, $chunk)));
                $users = Eligibility::loadUsers($ids);

                foreach ($campaign->channelList() as $channel) {
                    $reasons = $this->eligibility->check($campaign, $channel, $users, $ids);
                    $queued = $this->insert($campaign, $channel, $chunk, $reasons, $now, $totals);
                    $pending[$channel->value] = [...($pending[$channel->value] ?? []), ...$queued];

                    while (count($pending[$channel->value]) >= $this->batchSize($channel)) {
                        $batch = array_splice($pending[$channel->value], 0, $this->batchSize($channel));
                        $this->dispatch($campaign, $channel, $batch, $dispatched, $now);
                    }
                }
            });

        foreach ($pending as $channel => $batch) {
            if ($batch !== []) {
                $this->dispatch($campaign, Channel::from($channel), $batch, $dispatched, $now);
            }
        }

        return $totals;
    }

    public function batchSize(Channel $channel): int
    {
        return $channel === Channel::Push
            ? (int) config('eventapp.campaigns.push_batch_size')
            : self::BATCH[$channel->value];
    }

    /**
     * Inserts the rows that don't exist yet and returns the ids of the new queued ones.
     *
     * @param  list<AudienceMember>  $members
     * @param  array<string, ?SkipReason>  $reasons
     * @param  array{queued: int, skipped: int}  $totals
     * @return list<string>
     */
    private function insert(Campaign $campaign, Channel $channel, array $members, array $reasons, CarbonImmutable $now, array &$totals): array
    {
        $existing = DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->where('channel', $channel->value)
            ->whereIn('user_id', array_keys($reasons))
            ->get(['user_id', 'run_key'])
            ->map(fn ($r) => $r->run_key.'|'.$r->user_id)
            ->flip();

        $rows = [];
        $queued = [];
        $seen = [];
        foreach ($members as $member) {
            $key = $member->runKey.'|'.$member->userId;
            if (isset($existing[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $reason = $reasons[$member->userId] ?? null;
            $id = strtolower((string) Str::ulid()); // as HasUlids makes them
            $rows[] = [
                'id' => $id,
                'campaign_id' => $campaign->id,
                'user_id' => $member->userId,
                'channel' => $channel->value,
                'run_key' => $member->runKey,
                'context' => $member->context === [] ? null : json_encode($member->context),
                'status' => $reason ? RecipientStatus::Skipped->value : RecipientStatus::Queued->value,
                'skip_reason' => $reason?->value,
                'cost_minor' => 0,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($reason === null) {
                $queued[] = $id;
            }
            $totals[$reason ? 'skipped' : 'queued']++;
        }

        foreach (array_chunk($rows, 500) as $part) {
            // A concurrent run inserting the same row loses quietly: the unique index wins.
            DB::table('campaign_recipients')->insertOrIgnore($part);
        }

        return $queued;
    }

    /**
     * Each batch starts later than the last, so the channel never goes faster than its
     * rate_per_minute.
     *
     * @param  list<string>  $ids
     * @param  array<string, int>  $dispatched  recipients already scheduled per channel
     */
    private function dispatch(Campaign $campaign, Channel $channel, array $ids, array &$dispatched, CarbonImmutable $now): void
    {
        $rate = max(1, (int) config("eventapp.campaigns.rate_per_minute.{$channel->value}"));
        $offset = $dispatched[$channel->value] ?? 0;
        $delaySeconds = intdiv($offset * 60, $rate);
        $dispatched[$channel->value] = $offset + count($ids);

        DeliverCampaignBatch::dispatch($campaign->id, $channel->value, $ids)
            ->delay($delaySeconds > 0 ? $now->addSeconds($delaySeconds) : null);
    }
}
