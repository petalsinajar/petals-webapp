<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Distances and "does this vendor serve that venue?".
 *
 * On PostgreSQL this uses the PostGIS column vendors.base_location (GIST-indexed). Other
 * drivers (SQLite in tests) get a bounding box, which can use plain indexes, followed by an
 * exact haversine check. Both give the same answer: a vendor serves a venue only inside
 * its own service_radius_km.
 */
final class Geo
{
    private const EARTH_KM = 6371.0;

    /** Kilometres per degree of latitude. */
    private const KM_PER_DEGREE = 111.32;

    /** Great-circle distance in kilometres (haversine). */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Keep vendors whose service area covers the point. The query must have the vendors
     * table available (a vendors query, or one that joins it).
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function vendorsServing(Builder $query, float $lat, float $lng): Builder
    {
        if (self::postgis($query)) {
            return $query->whereRaw(
                'ST_DWithin(vendors.base_location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, vendors.service_radius_km * 1000)',
                [$lng, $lat],
            );
        }

        // Bounding box first: cheap, and drops most rows before the trigonometry.
        $kmPerLngDegree = max(0.01, self::KM_PER_DEGREE * cos(deg2rad($lat)));

        return $query
            ->whereNotNull('vendors.base_lat')
            ->whereNotNull('vendors.base_lng')
            ->whereRaw('abs(vendors.base_lat - ?) * ? <= vendors.service_radius_km', [$lat, self::KM_PER_DEGREE])
            ->whereRaw('abs(vendors.base_lng - ?) * ? <= vendors.service_radius_km', [$lng, $kmPerLngDegree])
            ->whereRaw(self::haversineSql().' <= vendors.service_radius_km', [$lat, $lat, $lng]);
    }

    /**
     * Nearest vendor first. With $planBoost, a paid plan's visibility_boost (at most
     * eventapp.visibility.max_boost, e.g. 0.1) counts the vendor as that much closer: a
     * nudge between similar distances, never past a clearly nearer vendor.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function orderByDistance(Builder $query, float $lat, float $lng, bool $planBoost = true): Builder
    {
        $boost = $planBoost ? ' * (1 - vendors.visibility_boost)' : '';

        if (self::postgis($query)) {
            return $query->orderByRaw('(vendors.base_location <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)'.$boost, [$lng, $lat]);
        }

        return $query->orderByRaw(self::haversineSql().$boost, [$lat, $lat, $lng]);
    }

    /** Distance in km from vendors.base_lat/base_lng; bindings: lat, lat, lng. */
    private static function haversineSql(): string
    {
        return '('.self::EARTH_KM.' * 2 * asin(sqrt('
            .'power(sin(radians(vendors.base_lat - ?) / 2), 2)'
            .' + cos(radians(?)) * cos(radians(vendors.base_lat)) * power(sin(radians(vendors.base_lng - ?) / 2), 2)'
            .')))';
    }

    private static function postgis(Builder $query): bool
    {
        return $query->getConnection()->getDriverName() === 'pgsql';
    }
}
