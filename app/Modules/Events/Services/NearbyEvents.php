<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Happening near you": upcoming public events with a cover, soonest first. Near means a
 * venue pin within radius_km, or (for events without a pin) a venue city naming the
 * viewer's nearest town. Without a place it's everywhere. Events the viewer is already
 * part of are left out; they're in My Events.
 */
final class NearbyEvents
{
    /** @return Collection<int, Event> */
    public function for(?User $viewer, ?float $lat, ?float $lng): Collection
    {
        $config = config('eventapp.nearby_events');
        $radius = (float) $config['radius_km'];

        // Signed in without a place: their profile city stands in.
        if ($lat === null || $lng === null) {
            $town = $this->townNamed($viewer?->city);
            [$lat, $lng] = $town ? [$town['lat'], $town['lng']] : [null, null];
        }

        $query = Event::query()
            ->where('visibility', 'public')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('cover_media_id')
            ->where('ends_at', '>=', now())
            ->where('starts_at', '<=', now()->addDays($config['horizon_days']))
            ->when($viewer, fn (Builder $q) => $q->whereNotIn('id', DB::table('event_members')->select('event_id')->where('user_id', $viewer->id)))
            ->with(['host', 'cover'])
            ->orderBy('starts_at')->orderBy('id');

        if ($lat === null || $lng === null) {
            return $query->limit($config['limit'])->get();
        }

        $names = $this->townNear($lat, $lng, $radius);
        // A box first so the database narrows it; the true distance is checked below.
        $dLat = $radius / 111.0;
        $dLng = $radius / (111.0 * max(cos(deg2rad($lat)), 0.01));

        return $query
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q
                    ->whereBetween('venue_lat', [$lat - $dLat, $lat + $dLat])
                    ->whereBetween('venue_lng', [$lng - $dLng, $lng + $dLng]))
                ->when($names, fn (Builder $q) => $q->orWhere(fn (Builder $q) => $q
                    ->whereNull('venue_lat')
                    ->whereIn(DB::raw('lower(venue_city)'), $names))))
            ->limit($config['limit'] * 3)
            ->get()
            ->filter(fn (Event $e) => $e->venue_lat === null
                || self::km($lat, $lng, (float) $e->venue_lat, (float) $e->venue_lng) <= $radius)
            ->take($config['limit'])
            ->values();
    }

    /**
     * The nearest configured town within the radius, as lowercase names a host might
     * type for its city (the town and its neighbourhoods). Empty when none is near.
     *
     * @return list<string>
     */
    private function townNear(float $lat, float $lng, float $radius): array
    {
        $best = collect(config('eventapp.nearby_events.towns'))
            ->map(fn (array $t, string $name) => $t + ['name' => $name, 'km' => self::km($lat, $lng, $t['lat'], $t['lng'])])
            ->sortBy('km')
            ->first();
        if (! $best || $best['km'] > $radius) {
            return [];
        }

        return array_map('mb_strtolower', [$best['name'], ...$best['aka']]);
    }

    /** @return array{lat: float, lng: float}|null */
    private function townNamed(?string $city): ?array
    {
        $city = mb_strtolower(trim((string) $city));
        if ($city === '') {
            return null;
        }
        foreach (config('eventapp.nearby_events.towns') as $name => $town) {
            if (in_array($city, array_map('mb_strtolower', [$name, ...$town['aka']]), true)) {
                return ['lat' => $town['lat'], 'lng' => $town['lng']];
            }
        }

        return null;
    }

    private static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
