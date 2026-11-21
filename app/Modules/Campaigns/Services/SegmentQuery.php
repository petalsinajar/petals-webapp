<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Identity\Models\User;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A segment's filters as a users query. Every filter is optional and they all have to
 * match (roles match any of the roles given). Admins, suspended and deleted accounts are
 * never in an audience; per-channel exclusions (opt-outs, no device, no verified phone)
 * happen when a campaign runs, so they can be reported as skipped.
 *
 * Filters:
 *   roles: [host|guest|vendor]              host or co-host of an event, guest of one, vendor team member
 *   vendor_plans: [starter|growth|…]        with roles=vendor: their business is on one of these plans
 *   city: "Accra"                           users.city, ignoring case
 *   near: {lat, lng, km}                    users.lat/lng within km
 *   signed_up_from / signed_up_to: date
 *   last_active_after / last_active_before: date
 *   upcoming_event: {types?: [], from?: date, to?: date}   hosts or co-hosts of one
 *   has_booked: bool                        has (or has never) made a booking
 *   follows_vendor_id: ulid
 *   event_types: [wedding|…]                interest: hosted or joined an event of that type
 *   platforms: [android|ios]                has a registered device on that platform
 *   user_ids: [ulid]                        a one-off list (CSV upload)
 */
final class SegmentQuery
{
    /** @return Builder<User> */
    public static function users(array $filters): Builder
    {
        $q = User::query()
            ->where('users.status', 'active')
            ->where('users.is_admin', false);

        if ($roles = array_values(array_filter((array) ($filters['roles'] ?? [])))) {
            $q->where(function (Builder $any) use ($roles, $filters) {
                foreach ($roles as $role) {
                    if ($role === 'host') {
                        $any->orWhereExists(fn (QueryBuilder $h) => $h->selectRaw('1')->from('events')->whereColumn('events.host_id', 'users.id')->whereNull('events.deleted_at'))
                            ->orWhereExists(fn (QueryBuilder $e) => self::memberOfEvents($e, ['host', 'cohost']));
                    } elseif ($role === 'guest') {
                        $any->orWhereExists(fn (QueryBuilder $e) => self::memberOfEvents($e, ['guest']));
                    } elseif ($role === 'vendor') {
                        $any->orWhereExists(fn (QueryBuilder $m) => self::vendorMember($m, (array) ($filters['vendor_plans'] ?? [])));
                    }
                }
            });
        }

        if (filled($filters['city'] ?? null)) {
            $q->whereRaw('lower(users.city) = ?', [mb_strtolower(trim($filters['city']))]);
        }

        if (isset($filters['near']['lat'], $filters['near']['lng'], $filters['near']['km'])) {
            Geo::withinKm($q->getQuery(), 'users.lat', 'users.lng', (float) $filters['near']['lat'], (float) $filters['near']['lng'], (float) $filters['near']['km']);
        }

        foreach ([
            'signed_up_from' => ['users.created_at', '>=', false],
            'signed_up_to' => ['users.created_at', '<=', true],
            'last_active_after' => ['users.last_active_at', '>=', false],
            'last_active_before' => ['users.last_active_at', '<', false],
        ] as $key => [$column, $op, $endOfDay]) {
            if (filled($filters[$key] ?? null)) {
                $at = CarbonImmutable::parse($filters[$key], SendWindow::timezone());
                $q->where($column, $op, ($endOfDay ? $at->endOfDay() : $at)->utc());
            }
        }

        if (is_array($filters['upcoming_event'] ?? null)) {
            $e = $filters['upcoming_event'];
            $from = filled($e['from'] ?? null) ? CarbonImmutable::parse($e['from'], SendWindow::timezone())->utc() : CarbonImmutable::now();
            $to = filled($e['to'] ?? null) ? CarbonImmutable::parse($e['to'], SendWindow::timezone())->endOfDay()->utc() : null;
            $q->whereExists(fn (QueryBuilder $x) => $x->selectRaw('1')->from('events')
                ->whereNull('events.deleted_at')
                ->whereNotIn('events.status', ['cancelled', 'completed'])
                ->where('events.starts_at', '>=', max($from, CarbonImmutable::now()))
                ->when($to, fn ($x) => $x->where('events.starts_at', '<=', $to))
                ->when(array_filter((array) ($e['types'] ?? [])), fn ($x, $types) => $x->whereIn('events.type', $types))
                ->where(fn ($who) => $who->whereColumn('events.host_id', 'users.id')
                    ->orWhereExists(fn ($m) => $m->selectRaw('1')->from('event_members')
                        ->whereColumn('event_members.event_id', 'events.id')
                        ->whereColumn('event_members.user_id', 'users.id')
                        ->where('event_members.role', 'cohost'))));
        }

        if (array_key_exists('has_booked', $filters) && $filters['has_booked'] !== null && $filters['has_booked'] !== '') {
            $booked = fn (QueryBuilder $b) => $b->selectRaw('1')->from('bookings')->whereColumn('bookings.customer_id', 'users.id');
            filter_var($filters['has_booked'], FILTER_VALIDATE_BOOLEAN) ? $q->whereExists($booked) : $q->whereNotExists($booked);
        }

        if (filled($filters['follows_vendor_id'] ?? null)) {
            $q->whereExists(fn (QueryBuilder $f) => $f->selectRaw('1')->from('follows')
                ->where('follows.follower_type', 'user')->whereColumn('follows.follower_id', 'users.id')
                ->where('follows.target_type', 'vendor')->where('follows.target_id', $filters['follows_vendor_id']));
        }

        if ($types = array_values(array_filter((array) ($filters['event_types'] ?? [])))) {
            $q->where(fn (Builder $any) => $any
                ->whereExists(fn (QueryBuilder $h) => $h->selectRaw('1')->from('events')->whereColumn('events.host_id', 'users.id')->whereIn('events.type', $types))
                ->orWhereExists(fn (QueryBuilder $m) => $m->selectRaw('1')->from('event_members')
                    ->join('events', 'events.id', '=', 'event_members.event_id')
                    ->whereColumn('event_members.user_id', 'users.id')->whereIn('events.type', $types)));
        }

        if ($platforms = array_values(array_filter((array) ($filters['platforms'] ?? [])))) {
            $q->whereExists(fn (QueryBuilder $d) => $d->selectRaw('1')->from('devices')
                ->whereColumn('devices.user_id', 'users.id')->whereIn('devices.platform', $platforms));
        }

        if (array_key_exists('user_ids', $filters) && is_array($filters['user_ids'])) {
            $q->whereIn('users.id', $filters['user_ids']);
        }

        return $q;
    }

    public static function count(array $filters): int
    {
        return self::users($filters)->count();
    }

    /** @param list<string> $roles */
    private static function memberOfEvents(QueryBuilder $q, array $roles): QueryBuilder
    {
        return $q->selectRaw('1')->from('event_members')
            ->whereColumn('event_members.user_id', 'users.id')
            ->whereIn('event_members.role', $roles);
    }

    /**
     * Active members of an active vendor; with $plans, only vendors on one of them. A vendor
     * without a current subscription that gives benefits is on the default (free) plan, as
     * Entitlements reads it.
     *
     * @param  list<string>  $plans
     */
    private static function vendorMember(QueryBuilder $q, array $plans): QueryBuilder
    {
        $q->selectRaw('1')->from('vendor_members')
            ->join('vendors', 'vendors.id', '=', 'vendor_members.vendor_id')
            ->whereColumn('vendor_members.user_id', 'users.id')
            ->where('vendor_members.status', 'active')
            ->where('vendors.status', 'active')
            ->whereNull('vendors.deleted_at');

        $plans = array_values(array_filter($plans));
        if ($plans === []) {
            return $q;
        }

        $paying = fn (QueryBuilder $s) => $s->selectRaw('1')->from('vendor_subscriptions')
            ->join('plans', 'plans.id', '=', 'vendor_subscriptions.plan_id')
            ->whereColumn('vendor_subscriptions.vendor_id', 'vendors.id')
            ->where('vendor_subscriptions.is_current', true)
            ->whereIn('vendor_subscriptions.status', ['trialing', 'active', 'grace']);

        $default = config('eventapp.subscriptions.default_plan');

        return $q->where(function (QueryBuilder $plan) use ($plans, $paying, $default) {
            $plan->whereExists(fn (QueryBuilder $s) => $paying($s)->whereIn('plans.key', $plans));
            if (in_array($default, $plans, true)) {
                $plan->orWhereNotExists($paying);
            }
        });
    }
}
