<?php

namespace App\Support;

use App\Models\Farm;
use App\Models\FarmPremisesZone;
use InvalidArgumentException;
use Illuminate\Support\Collection;
use Throwable;

class FarmPremises
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
    public static function settingsForFarm(Farm $farm): array
    {
        $defaultCenter = Geofence::defaultCenter();
        $geometry = self::zoneGeometry(self::zoneRecordForFarm($farm));
        $mapCenter = self::mapCenterForGeometry($geometry);

        if ($mapCenter === null && $farm->latitude !== null && $farm->longitude !== null) {
            $mapCenter = [
                'latitude' => (float) $farm->latitude,
                'longitude' => (float) $farm->longitude,
            ];
        }

        if ($mapCenter === null) {
            $mapCenter = $defaultCenter;
        }

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
     * @param Collection<int, Farm> $farms
     * @return array{
     *   geofence: array<string, mixed>,
     *   general_geofence_configured: bool,
     *   default_center: array{latitude: float, longitude: float},
     *   map_center: array{latitude: float, longitude: float},
     *   farms: array<int, array<string, mixed>>
     * }
     */
    public static function mapPayloadForFarms(Collection $farms): array
    {
        $geofencePayload = Geofence::mapPayload();
        $defaultCenter = Geofence::defaultCenter();
        $mapCenter = $geofencePayload['map_center'] ?? $defaultCenter;

        $farmRows = $farms
            ->map(static function (Farm $farm): array {
                $settings = self::settingsForFarm($farm);
                $locationParts = array_values(array_filter([
                    $farm->location,
                    $farm->sitio,
                    $farm->barangay,
                    $farm->municipality,
                    $farm->province,
                ], static fn ($value): bool => is_string($value) && trim($value) !== ''));

                return [
                    'id' => (int) $farm->id,
                    'farm_name' => (string) $farm->farm_name,
                    'owner_name' => $farm->owner?->full_name ? (string) $farm->owner->full_name : null,
                    'owner_username' => $farm->owner?->username ? (string) $farm->owner->username : null,
                    'location' => $farm->location,
                    'sitio' => $farm->sitio,
                    'barangay' => $farm->barangay,
                    'municipality' => $farm->municipality,
                    'province' => $farm->province,
                    'location_label' => $locationParts !== [] ? implode(', ', $locationParts) : null,
                    'latitude' => $farm->latitude !== null ? (float) $farm->latitude : null,
                    'longitude' => $farm->longitude !== null ? (float) $farm->longitude : null,
                    'fence' => [
                        'enabled' => (bool) ($settings['enabled'] ?? false),
                        'shape_type' => $settings['shape_type'] ?? null,
                        'geometry' => $settings['geometry'] ?? null,
                    ],
                ];
            })
            ->values()
            ->all();

        return [
            'geofence' => $geofencePayload,
            'general_geofence_configured' => (bool) ($geofencePayload['configured'] ?? false),
            'default_center' => $defaultCenter,
            'map_center' => [
                'latitude' => (float) ($mapCenter['latitude'] ?? $defaultCenter['latitude']),
                'longitude' => (float) ($mapCenter['longitude'] ?? $defaultCenter['longitude']),
            ],
            'farms' => $farmRows,
        ];
    }

    public static function containsForFarm(Farm $farm, float $latitude, float $longitude): bool
    {
        $settings = self::settingsForFarm($farm);
        if (!($settings['enabled'] ?? false) || !($settings['configured'] ?? false)) {
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
    public static function containsInDraftGeometry(string $shapeType, array $geometry, float $latitude, float $longitude): bool
    {
        $shapeType = strtoupper(trim($shapeType));
        $normalized = self::normalizeGeometry($shapeType, $geometry);
        if ($normalized === null) {
            return false;
        }

        return self::containsInGeometry($normalized, $latitude, $longitude);
    }

    /**
     * @param array<string, mixed> $geometry
     */
    public static function saveZoneForFarm(Farm $farm, string $shapeType, array $geometry, ?int $actorUserId): void
    {
        $shapeType = strtoupper(trim($shapeType));
        if (!array_key_exists($shapeType, Geofence::shapeOptions())) {
            throw new InvalidArgumentException('Invalid farm fence shape type.');
        }

        $normalized = self::normalizeGeometry($shapeType, $geometry);
        if ($normalized === null) {
            throw new InvalidArgumentException('Please draw and save a valid farm fence.');
        }

        self::assertInsideGeneralPerimeter($shapeType, $normalized);

        $zone = self::zoneRecordForFarm($farm) ?? new FarmPremisesZone();
        $zone->farm_id = (int) $farm->id;
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

    public static function clearForFarm(Farm $farm, ?int $actorUserId): void
    {
        try {
            FarmPremisesZone::query()
                ->where('farm_id', (int) $farm->id)
                ->update([
                    'is_active' => false,
                    'updated_by_user_id' => $actorUserId,
                ]);
        } catch (Throwable) {
            // Ignore clear failures to avoid blocking owner profile updates.
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
     * @param array<string, mixed> $geometry
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

    /**
     * @param array<string, mixed> $normalizedGeometry
     */
    private static function assertInsideGeneralPerimeter(string $shapeType, array $normalizedGeometry): void
    {
        $settings = Geofence::settings();
        if (!($settings['configured'] ?? false)) {
            throw new InvalidArgumentException('Configure the general geofence first before saving farm fences.');
        }

        $samplePoints = self::samplePoints($shapeType, $normalizedGeometry);
        if ($samplePoints === []) {
            throw new InvalidArgumentException('Unable to validate farm fence against general geofence.');
        }

        foreach ($samplePoints as $point) {
            if (!Geofence::containsInConfiguredGeometry((float) $point[0], (float) $point[1])) {
                throw new InvalidArgumentException('Farm fence must stay inside the general geofence.');
            }
        }
    }

    private static function zoneRecordForFarm(Farm $farm): ?FarmPremisesZone
    {
        if ($farm->relationLoaded('premisesZone')) {
            $loaded = $farm->getRelation('premisesZone');
            return $loaded instanceof FarmPremisesZone ? $loaded : null;
        }

        try {
            return FarmPremisesZone::query()
                ->where('farm_id', (int) $farm->id)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function zoneGeometry(?FarmPremisesZone $zone): ?array
    {
        if (!$zone || !$zone->is_active) {
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
