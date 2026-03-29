<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserPremisesZone;
use InvalidArgumentException;
use Throwable;

class UserPremises
{
    /**
     * @return array{
     *   enabled: bool,
     *   configured: bool,
     *   shape_type: string|null,
     *   geometry: array<string, mixed>|null,
     *   map_center_latitude: float,
     *   map_center_longitude: float,
     *   default_center_latitude: float,
     *   default_center_longitude: float
     * }
     */
    public static function settingsForUser(?User $user): array
    {
        $defaultCenter = Geofence::defaultCenter();
        if (!$user || $user->isAdmin()) {
            return [
                'enabled' => false,
                'configured' => false,
                'shape_type' => null,
                'geometry' => null,
                'map_center_latitude' => $defaultCenter['latitude'],
                'map_center_longitude' => $defaultCenter['longitude'],
                'default_center_latitude' => $defaultCenter['latitude'],
                'default_center_longitude' => $defaultCenter['longitude'],
            ];
        }

        $geometry = self::zoneGeometry(self::activeZoneForUser((int) $user->id));
        $mapCenter = self::mapCenterForGeometry($geometry) ?? $defaultCenter;

        return [
            'enabled' => $geometry !== null,
            'configured' => $geometry !== null,
            'shape_type' => $geometry['shape_type'] ?? null,
            'geometry' => $geometry,
            'map_center_latitude' => $mapCenter['latitude'],
            'map_center_longitude' => $mapCenter['longitude'],
            'default_center_latitude' => $defaultCenter['latitude'],
            'default_center_longitude' => $defaultCenter['longitude'],
        ];
    }

    /**
     * @return array{
     *   enabled: bool,
     *   configured: bool,
     *   geofence: array<string, mixed>,
     *   general_geofence_configured: bool,
     *   geometry: array<string, mixed>|null,
     *   map_center: array{latitude: float, longitude: float},
     *   default_center: array{latitude: float, longitude: float}
     * }
     */
    public static function mapPayloadForUser(?User $user): array
    {
        $settings = self::settingsForUser($user);
        $geofencePayload = Geofence::mapPayload();

        return [
            'enabled' => $settings['enabled'],
            'configured' => $settings['configured'],
            'geofence' => $geofencePayload,
            'general_geofence_configured' => (bool) ($geofencePayload['configured'] ?? false),
            'geometry' => $settings['geometry'],
            'map_center' => [
                'latitude' => $settings['map_center_latitude'],
                'longitude' => $settings['map_center_longitude'],
            ],
            'default_center' => Geofence::defaultCenter(),
        ];
    }

    public static function containsForUser(?User $user, float $latitude, float $longitude): bool
    {
        if (!$user || $user->isAdmin()) {
            return true;
        }

        $settings = self::settingsForUser($user);
        if (!$settings['enabled'] || !$settings['configured']) {
            return true;
        }

        $geometry = $settings['geometry'];
        if (!is_array($geometry)) {
            return true;
        }

        return self::containsInGeometry($geometry, $latitude, $longitude);
    }

    /**
     * @param array<string, mixed> $geometry
     */
    public static function saveZoneForUser(User $user, string $shapeType, array $geometry, ?int $actorUserId): void
    {
        if ($user->isAdmin()) {
            throw new InvalidArgumentException('Admin accounts bypass geofence and do not require a user premises zone.');
        }

        $shapeType = strtoupper(trim($shapeType));
        if (!array_key_exists($shapeType, Geofence::shapeOptions())) {
            throw new InvalidArgumentException('Invalid user premises shape type.');
        }

        $normalized = self::normalizeGeometry($shapeType, $geometry);
        if ($normalized === null) {
            throw new InvalidArgumentException('Invalid user premises geometry.');
        }

        self::assertInsideGeneralPerimeter($shapeType, $normalized);

        $zone = self::activeZoneForUser((int) $user->id) ?? new UserPremisesZone();
        $zone->user_id = (int) $user->id;
        $zone->shape_type = $shapeType;
        $zone->center_latitude = null;
        $zone->center_longitude = null;
        $zone->radius_meters = null;
        $zone->bounds_north = null;
        $zone->bounds_south = null;
        $zone->bounds_east = null;
        $zone->bounds_west = null;
        $zone->vertices_json = null;
        $zone->is_active = true;

        if ($shapeType === Geofence::SHAPE_CIRCLE) {
            $zone->center_latitude = $normalized['center_latitude'];
            $zone->center_longitude = $normalized['center_longitude'];
            $zone->radius_meters = $normalized['radius_meters'];
        } elseif ($shapeType === Geofence::SHAPE_RECTANGLE || $shapeType === Geofence::SHAPE_SQUARE) {
            $zone->bounds_north = $normalized['bounds']['north'];
            $zone->bounds_south = $normalized['bounds']['south'];
            $zone->bounds_east = $normalized['bounds']['east'];
            $zone->bounds_west = $normalized['bounds']['west'];
        } else {
            $zone->vertices_json = json_encode($normalized['vertices']);
        }

        if (!$zone->exists && $actorUserId !== null) {
            $zone->created_by_user_id = $actorUserId;
        }
        $zone->updated_by_user_id = $actorUserId;
        $zone->save();
    }

    public static function clearForUser(User $user, ?int $actorUserId): void
    {
        try {
            UserPremisesZone::query()
                ->where('user_id', (int) $user->id)
                ->update([
                    'is_active' => false,
                    'updated_by_user_id' => $actorUserId,
                ]);
        } catch (Throwable) {
            // Ignore clear failures to avoid blocking profile updates.
        }
    }

    /**
     * @param array<string, mixed> $geometry
     * @return array<string, mixed>|null
     */
    private static function normalizeGeometry(string $shapeType, array $geometry): ?array
    {
        if ($shapeType === Geofence::SHAPE_CIRCLE) {
            $centerLatitude = $geometry['center_latitude'] ?? null;
            $centerLongitude = $geometry['center_longitude'] ?? null;
            $radiusMeters = $geometry['radius_meters'] ?? null;

            if (!is_numeric($centerLatitude) || !is_numeric($centerLongitude) || !is_numeric($radiusMeters)) {
                return null;
            }

            $centerLatitude = (float) $centerLatitude;
            $centerLongitude = (float) $centerLongitude;
            $radiusMeters = (int) round((float) $radiusMeters);

            if ($centerLatitude < -90 || $centerLatitude > 90 || $centerLongitude < -180 || $centerLongitude > 180 || $radiusMeters < 25) {
                return null;
            }

            return [
                'shape_type' => Geofence::SHAPE_CIRCLE,
                'center_latitude' => $centerLatitude,
                'center_longitude' => $centerLongitude,
                'radius_meters' => $radiusMeters,
            ];
        }

        if ($shapeType === Geofence::SHAPE_RECTANGLE || $shapeType === Geofence::SHAPE_SQUARE) {
            $bounds = $geometry['bounds'] ?? $geometry;
            if (!is_array($bounds)) {
                return null;
            }

            $north = $bounds['north'] ?? null;
            $south = $bounds['south'] ?? null;
            $east = $bounds['east'] ?? null;
            $west = $bounds['west'] ?? null;

            if (!is_numeric($north) || !is_numeric($south) || !is_numeric($east) || !is_numeric($west)) {
                return null;
            }

            $north = (float) $north;
            $south = (float) $south;
            $east = (float) $east;
            $west = (float) $west;

            if ($north <= $south || $east <= $west) {
                return null;
            }

            if ($shapeType === Geofence::SHAPE_SQUARE) {
                $latSpan = $north - $south;
                $lngSpan = $east - $west;
                $span = max($latSpan, $lngSpan);
                $centerLat = ($north + $south) / 2.0;
                $centerLng = ($east + $west) / 2.0;

                $north = $centerLat + ($span / 2.0);
                $south = $centerLat - ($span / 2.0);
                $east = $centerLng + ($span / 2.0);
                $west = $centerLng - ($span / 2.0);
            }

            if ($north < -90 || $north > 90 || $south < -90 || $south > 90 || $east < -180 || $east > 180 || $west < -180 || $west > 180) {
                return null;
            }

            return [
                'shape_type' => $shapeType,
                'bounds' => [
                    'north' => $north,
                    'south' => $south,
                    'east' => $east,
                    'west' => $west,
                ],
            ];
        }

        if ($shapeType === Geofence::SHAPE_POLYGON) {
            $vertices = $geometry['vertices'] ?? $geometry;
            if (!is_array($vertices) || count($vertices) < 3) {
                return null;
            }

            $normalized = [];
            foreach ($vertices as $point) {
                if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) {
                    return null;
                }

                $lat = (float) $point[0];
                $lng = (float) $point[1];
                if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                    return null;
                }

                $normalized[] = [$lat, $lng];
            }

            if (count($normalized) < 3) {
                return null;
            }

            return [
                'shape_type' => Geofence::SHAPE_POLYGON,
                'vertices' => $normalized,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $geometry
     */
    private static function containsInGeometry(array $geometry, float $latitude, float $longitude): bool
    {
        $shapeType = strtoupper((string) ($geometry['shape_type'] ?? ''));

        if ($shapeType === Geofence::SHAPE_CIRCLE) {
            $centerLatitude = $geometry['center_latitude'] ?? null;
            $centerLongitude = $geometry['center_longitude'] ?? null;
            $radiusMeters = $geometry['radius_meters'] ?? null;

            if (!is_numeric($centerLatitude) || !is_numeric($centerLongitude) || !is_numeric($radiusMeters)) {
                return false;
            }

            $distanceMeters = self::distanceMeters($latitude, $longitude, (float) $centerLatitude, (float) $centerLongitude);

            return $distanceMeters <= (float) $radiusMeters;
        }

        if ($shapeType === Geofence::SHAPE_RECTANGLE || $shapeType === Geofence::SHAPE_SQUARE) {
            $bounds = $geometry['bounds'] ?? null;
            if (!is_array($bounds)) {
                return false;
            }

            $north = $bounds['north'] ?? null;
            $south = $bounds['south'] ?? null;
            $east = $bounds['east'] ?? null;
            $west = $bounds['west'] ?? null;

            if (!is_numeric($north) || !is_numeric($south) || !is_numeric($east) || !is_numeric($west)) {
                return false;
            }

            return $latitude <= (float) $north
                && $latitude >= (float) $south
                && $longitude <= (float) $east
                && $longitude >= (float) $west;
        }

        if ($shapeType === Geofence::SHAPE_POLYGON) {
            $vertices = $geometry['vertices'] ?? null;
            if (!is_array($vertices)) {
                return false;
            }

            return self::pointInPolygon($latitude, $longitude, $vertices);
        }

        return false;
    }

    /**
     * @return array<int, array{0: float, 1: float}>
     */
    private static function samplePoints(string $shapeType, array $geometry): array
    {
        if ($shapeType === Geofence::SHAPE_CIRCLE) {
            $centerLatitude = (float) $geometry['center_latitude'];
            $centerLongitude = (float) $geometry['center_longitude'];
            $radiusMeters = (float) $geometry['radius_meters'];

            $latDelta = $radiusMeters / 111320.0;
            $cosLatitude = cos(deg2rad($centerLatitude));
            $lngDelta = $cosLatitude === 0.0 ? 0.0 : $radiusMeters / (111320.0 * max(0.000001, abs($cosLatitude)));

            return [
                [$centerLatitude, $centerLongitude],
                [$centerLatitude + $latDelta, $centerLongitude],
                [$centerLatitude - $latDelta, $centerLongitude],
                [$centerLatitude, $centerLongitude + $lngDelta],
                [$centerLatitude, $centerLongitude - $lngDelta],
            ];
        }

        if ($shapeType === Geofence::SHAPE_RECTANGLE || $shapeType === Geofence::SHAPE_SQUARE) {
            $bounds = $geometry['bounds'];
            $north = (float) $bounds['north'];
            $south = (float) $bounds['south'];
            $east = (float) $bounds['east'];
            $west = (float) $bounds['west'];

            return [
                [$north, $east],
                [$north, $west],
                [$south, $east],
                [$south, $west],
                [($north + $south) / 2.0, ($east + $west) / 2.0],
            ];
        }

        if ($shapeType === Geofence::SHAPE_POLYGON) {
            $vertices = $geometry['vertices'] ?? [];
            $sample = [];
            $latSum = 0.0;
            $lngSum = 0.0;
            $count = 0;

            foreach ($vertices as $vertex) {
                if (!is_array($vertex) || count($vertex) < 2) {
                    continue;
                }

                $lat = (float) $vertex[0];
                $lng = (float) $vertex[1];
                $sample[] = [$lat, $lng];
                $latSum += $lat;
                $lngSum += $lng;
                $count++;
            }

            if ($count > 0) {
                $sample[] = [$latSum / $count, $lngSum / $count];
            }

            return $sample;
        }

        return [];
    }

    private static function assertInsideGeneralPerimeter(string $shapeType, array $normalizedGeometry): void
    {
        $settings = Geofence::settings();
        if (!($settings['configured'] ?? false)) {
            throw new InvalidArgumentException('Configure the general geofence perimeter first before setting user premises.');
        }

        $samplePoints = self::samplePoints($shapeType, $normalizedGeometry);
        if ($samplePoints === []) {
            throw new InvalidArgumentException('Unable to validate the user premises against general perimeter.');
        }

        foreach ($samplePoints as $point) {
            if (!Geofence::containsInConfiguredGeometry((float) $point[0], (float) $point[1])) {
                throw new InvalidArgumentException('User premises must stay inside the general perimeter.');
            }
        }
    }

    private static function activeZoneForUser(int $userId): ?UserPremisesZone
    {
        try {
            return UserPremisesZone::query()
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->latest('id')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function zoneGeometry(?UserPremisesZone $zone): ?array
    {
        if (!$zone) {
            return null;
        }

        $shapeType = strtoupper((string) $zone->shape_type);

        if ($shapeType === Geofence::SHAPE_CIRCLE) {
            if ($zone->center_latitude === null || $zone->center_longitude === null || $zone->radius_meters === null) {
                return null;
            }

            return [
                'shape_type' => Geofence::SHAPE_CIRCLE,
                'center_latitude' => (float) $zone->center_latitude,
                'center_longitude' => (float) $zone->center_longitude,
                'radius_meters' => (int) $zone->radius_meters,
            ];
        }

        if ($shapeType === Geofence::SHAPE_RECTANGLE || $shapeType === Geofence::SHAPE_SQUARE) {
            if ($zone->bounds_north === null || $zone->bounds_south === null || $zone->bounds_east === null || $zone->bounds_west === null) {
                return null;
            }

            return [
                'shape_type' => $shapeType,
                'bounds' => [
                    'north' => (float) $zone->bounds_north,
                    'south' => (float) $zone->bounds_south,
                    'east' => (float) $zone->bounds_east,
                    'west' => (float) $zone->bounds_west,
                ],
            ];
        }

        if ($shapeType === Geofence::SHAPE_POLYGON) {
            $decoded = json_decode((string) $zone->vertices_json, true);
            if (!is_array($decoded) || count($decoded) < 3) {
                return null;
            }

            $vertices = [];
            foreach ($decoded as $point) {
                if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) {
                    return null;
                }

                $vertices[] = [(float) $point[0], (float) $point[1]];
            }

            if (count($vertices) < 3) {
                return null;
            }

            return [
                'shape_type' => Geofence::SHAPE_POLYGON,
                'vertices' => $vertices,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $geometry
     * @return array{latitude: float, longitude: float}|null
     */
    private static function mapCenterForGeometry(?array $geometry): ?array
    {
        if (!$geometry || !isset($geometry['shape_type'])) {
            return null;
        }

        $shapeType = strtoupper((string) $geometry['shape_type']);
        if ($shapeType === Geofence::SHAPE_CIRCLE) {
            return [
                'latitude' => (float) $geometry['center_latitude'],
                'longitude' => (float) $geometry['center_longitude'],
            ];
        }

        if ($shapeType === Geofence::SHAPE_RECTANGLE || $shapeType === Geofence::SHAPE_SQUARE) {
            $bounds = $geometry['bounds'] ?? null;
            if (!is_array($bounds)) {
                return null;
            }

            return [
                'latitude' => ((float) $bounds['north'] + (float) $bounds['south']) / 2.0,
                'longitude' => ((float) $bounds['east'] + (float) $bounds['west']) / 2.0,
            ];
        }

        if ($shapeType === Geofence::SHAPE_POLYGON) {
            $vertices = $geometry['vertices'] ?? null;
            if (!is_array($vertices) || $vertices === []) {
                return null;
            }

            $latSum = 0.0;
            $lngSum = 0.0;
            $count = 0;
            foreach ($vertices as $point) {
                if (!is_array($point) || count($point) < 2) {
                    continue;
                }

                $latSum += (float) $point[0];
                $lngSum += (float) $point[1];
                $count++;
            }

            if ($count === 0) {
                return null;
            }

            return [
                'latitude' => $latSum / $count,
                'longitude' => $lngSum / $count,
            ];
        }

        return null;
    }

    private static function distanceMeters(
        float $latitudeA,
        float $longitudeA,
        float $latitudeB,
        float $longitudeB
    ): float {
        $earthRadiusMeters = 6371000.0;

        $latARad = deg2rad($latitudeA);
        $latBRad = deg2rad($latitudeB);
        $deltaLat = deg2rad($latitudeB - $latitudeA);
        $deltaLng = deg2rad($longitudeB - $longitudeA);

        $sinLat = sin($deltaLat / 2.0);
        $sinLng = sin($deltaLng / 2.0);

        $a = ($sinLat * $sinLat) + cos($latARad) * cos($latBRad) * ($sinLng * $sinLng);
        $c = 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));

        return $earthRadiusMeters * $c;
    }

    /**
     * @param array<int, array<int, float|int|string>> $vertices
     */
    private static function pointInPolygon(float $latitude, float $longitude, array $vertices): bool
    {
        return Polygon::containsPoint($latitude, $longitude, $vertices);
    }
}
