<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\GeofenceZone;
use InvalidArgumentException;
use Throwable;

class Geofence
{
    public const SETTING_ENABLED = 'geofence_enabled';

    public const SHAPE_CIRCLE = 'CIRCLE';
    public const SHAPE_RECTANGLE = 'RECTANGLE';
    public const SHAPE_SQUARE = 'SQUARE';
    public const SHAPE_POLYGON = 'POLYGON';

    public const DEFAULT_CENTER_LATITUDE = 10.354727;
    public const DEFAULT_CENTER_LONGITUDE = 124.965980;

    /**
     * @return array<string, string>
     */
    public static function shapeOptions(): array
    {
        return [
            self::SHAPE_CIRCLE => 'Circle',
            self::SHAPE_RECTANGLE => 'Rectangle',
            self::SHAPE_SQUARE => 'Square',
            self::SHAPE_POLYGON => 'Polygon',
        ];
    }

    /**
     * @param array<string, mixed> $geometry
     */
    public static function inferShapeType(array $geometry): ?string
    {
        if (
            array_key_exists('center_latitude', $geometry)
            && array_key_exists('center_longitude', $geometry)
            && array_key_exists('radius_meters', $geometry)
        ) {
            return self::SHAPE_CIRCLE;
        }

        $bounds = $geometry['bounds'] ?? $geometry;
        if (
            is_array($bounds)
            && array_key_exists('north', $bounds)
            && array_key_exists('south', $bounds)
            && array_key_exists('east', $bounds)
            && array_key_exists('west', $bounds)
        ) {
            return self::SHAPE_RECTANGLE;
        }

        if (array_key_exists('vertices', $geometry) && is_array($geometry['vertices'])) {
            return self::SHAPE_POLYGON;
        }

        return null;
    }

    /**
     * @return array{latitude: float, longitude: float}
     */
    public static function defaultCenter(): array
    {
        return [
            'latitude' => self::DEFAULT_CENTER_LATITUDE,
            'longitude' => self::DEFAULT_CENTER_LONGITUDE,
        ];
    }

    /**
     * @return array{
     *   enabled: bool,
     *   configured: bool,
     *   geometries: array<int, array<string, mixed>>,
     *   shape_type: string|null,
     *   center_latitude: float|null,
     *   center_longitude: float|null,
     *   radius_meters: int|null,
     *   geometry: array<string, mixed>|null,
     *   map_center_latitude: float,
     *   map_center_longitude: float,
     *   default_center_latitude: float,
     *   default_center_longitude: float
     * }
     */
    public static function settings(): array
    {
        $enabled = self::readEnabledFlag();
        $geometries = self::activeGeometries();
        $geometry = $geometries[0] ?? null;
        $mapCenter = self::mapCenterForGeometry($geometry) ?? self::defaultCenter();

        return [
            'enabled' => $enabled,
            'configured' => $geometry !== null,
            'geometries' => $geometries,
            'shape_type' => $geometry['shape_type'] ?? null,
            'center_latitude' => ($geometry['shape_type'] ?? null) === self::SHAPE_CIRCLE ? ($geometry['center_latitude'] ?? null) : null,
            'center_longitude' => ($geometry['shape_type'] ?? null) === self::SHAPE_CIRCLE ? ($geometry['center_longitude'] ?? null) : null,
            'radius_meters' => ($geometry['shape_type'] ?? null) === self::SHAPE_CIRCLE ? ($geometry['radius_meters'] ?? null) : null,
            'geometry' => $geometry,
            'map_center_latitude' => $mapCenter['latitude'],
            'map_center_longitude' => $mapCenter['longitude'],
            'default_center_latitude' => self::DEFAULT_CENTER_LATITUDE,
            'default_center_longitude' => self::DEFAULT_CENTER_LONGITUDE,
        ];
    }

    public static function isEnabled(): bool
    {
        $settings = self::settings();

        return $settings['enabled'] && $settings['configured'];
    }

    public static function setEnabled(bool $enabled): void
    {
        try {
            AppSetting::query()->updateOrCreate(
                ['setting_key' => self::SETTING_ENABLED],
                ['setting_value' => $enabled ? '1' : '0']
            );
        } catch (Throwable) {
            // Swallow DB errors to avoid fataling callsites that perform best-effort checks.
        }
    }

    /**
     * Backward-compatible helper for existing tests and callsites.
     */
    public static function set(bool $enabled, ?float $centerLatitude, ?float $centerLongitude, ?int $radiusMeters): void
    {
        self::setEnabled($enabled);

        if ($centerLatitude === null || $centerLongitude === null || $radiusMeters === null || $radiusMeters <= 0) {
            return;
        }

        self::saveZone(
            $enabled,
            self::SHAPE_CIRCLE,
            [
                'center_latitude' => $centerLatitude,
                'center_longitude' => $centerLongitude,
                'radius_meters' => $radiusMeters,
            ],
            null
        );
    }

    /**
     * @param array<string, mixed> $geometry
     */
    public static function saveZone(bool $enabled, string $shapeType, array $geometry, ?int $actorUserId): void
    {
        self::saveZones($enabled, [
            [
                'shape_type' => $shapeType,
                'geometry' => $geometry,
            ],
        ], $actorUserId);
    }

    /**
     * @param array<int, array{shape_type:string, geometry:array<string, mixed>}> $zones
     */
    public static function saveZones(bool $enabled, array $zones, ?int $actorUserId): void
    {
        self::setEnabled($enabled);

        $normalizedZones = [];
        foreach ($zones as $zonePayload) {
            $shapeType = strtoupper(trim((string) ($zonePayload['shape_type'] ?? '')));
            if (!array_key_exists($shapeType, self::shapeOptions())) {
                throw new InvalidArgumentException('Invalid geofence shape type.');
            }

            $geometry = $zonePayload['geometry'] ?? null;
            if (!is_array($geometry)) {
                throw new InvalidArgumentException('Invalid geofence geometry payload.');
            }

            $normalized = self::normalizeGeometry($shapeType, $geometry);
            if ($normalized === null) {
                throw new InvalidArgumentException('Invalid geofence geometry payload.');
            }

            $normalizedZones[] = [
                'shape_type' => $shapeType,
                'geometry' => $normalized,
            ];
        }

        GeofenceZone::query()
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_by_user_id' => $actorUserId,
            ]);

        foreach ($normalizedZones as $zonePayload) {
            $shapeType = $zonePayload['shape_type'];
            $normalized = $zonePayload['geometry'];

            $zone = new GeofenceZone();
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

            if ($shapeType === self::SHAPE_CIRCLE) {
                $zone->center_latitude = $normalized['center_latitude'];
                $zone->center_longitude = $normalized['center_longitude'];
                $zone->radius_meters = $normalized['radius_meters'];
            } elseif ($shapeType === self::SHAPE_RECTANGLE || $shapeType === self::SHAPE_SQUARE) {
                $zone->bounds_north = $normalized['bounds']['north'];
                $zone->bounds_south = $normalized['bounds']['south'];
                $zone->bounds_east = $normalized['bounds']['east'];
                $zone->bounds_west = $normalized['bounds']['west'];
            } else {
                $zone->vertices_json = json_encode($normalized['vertices']);
            }

            if ($actorUserId !== null) {
                $zone->created_by_user_id = $actorUserId;
            }
            $zone->updated_by_user_id = $actorUserId;
            $zone->save();
        }
    }

    /**
     * @return array{
     *   enabled: bool,
     *   configured: bool,
     *   geometries: array<int, array<string, mixed>>,
     *   geometry: array<string, mixed>|null,
     *   map_center: array{latitude: float, longitude: float},
     *   default_center: array{latitude: float, longitude: float}
     * }
     */
    public static function mapPayload(): array
    {
        $settings = self::settings();

        return [
            'enabled' => $settings['enabled'],
            'configured' => $settings['configured'],
            'geometries' => $settings['geometries'] ?? [],
            'geometry' => $settings['geometry'],
            'map_center' => [
                'latitude' => $settings['map_center_latitude'],
                'longitude' => $settings['map_center_longitude'],
            ],
            'default_center' => self::defaultCenter(),
        ];
    }

    public static function contains(float $latitude, float $longitude): bool
    {
        if (!self::isEnabled()) {
            return true;
        }

        $settings = self::settings();
        $geometries = $settings['geometries'] ?? [];
        if (!is_array($geometries) || $geometries === []) {
            return false;
        }

        foreach ($geometries as $geometry) {
            if (is_array($geometry) && self::containsInGeometry($geometry, $latitude, $longitude)) {
                return true;
            }
        }

        return false;
    }

    public static function containsInConfiguredGeometry(float $latitude, float $longitude): bool
    {
        $settings = self::settings();
        $geometries = $settings['geometries'] ?? [];
        if (!is_array($geometries) || $geometries === []) {
            return false;
        }

        foreach ($geometries as $geometry) {
            if (is_array($geometry) && self::containsInGeometry($geometry, $latitude, $longitude)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $geometry
     */
    private static function containsInGeometry(array $geometry, float $latitude, float $longitude): bool
    {
        $shapeType = strtoupper((string) ($geometry['shape_type'] ?? ''));

        if ($shapeType === self::SHAPE_CIRCLE) {
            $centerLatitude = $geometry['center_latitude'] ?? null;
            $centerLongitude = $geometry['center_longitude'] ?? null;
            $radiusMeters = $geometry['radius_meters'] ?? null;

            if (!is_numeric($centerLatitude) || !is_numeric($centerLongitude) || !is_numeric($radiusMeters)) {
                return false;
            }

            $distanceMeters = self::distanceMeters($latitude, $longitude, (float) $centerLatitude, (float) $centerLongitude);

            return $distanceMeters <= (float) $radiusMeters;
        }

        if ($shapeType === self::SHAPE_RECTANGLE || $shapeType === self::SHAPE_SQUARE) {
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

        if ($shapeType === self::SHAPE_POLYGON) {
            $vertices = $geometry['vertices'] ?? null;
            if (!is_array($vertices)) {
                return false;
            }

            return self::pointInPolygon($latitude, $longitude, $vertices);
        }

        return false;
    }

    public static function distanceFromCenterMeters(float $latitude, float $longitude): ?float
    {
        if (!self::isEnabled()) {
            return null;
        }

        $settings = self::settings();
        if ($settings['center_latitude'] === null || $settings['center_longitude'] === null) {
            return null;
        }

        return self::distanceMeters($latitude, $longitude, $settings['center_latitude'], $settings['center_longitude']);
    }

    private static function readEnabledFlag(): bool
    {
        try {
            $value = AppSetting::query()
                ->where('setting_key', self::SETTING_ENABLED)
                ->value('setting_value');
        } catch (Throwable) {
            $value = null;
        }

        return self::toBool($value);
    }

    /**
     * @return array<int, GeofenceZone>
     */
    private static function activeZones(): array
    {
        try {
            return GeofenceZone::query()
                ->where('is_active', true)
                ->orderByDesc('id')
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function activeGeometries(): array
    {
        return array_values(array_filter(array_map(
            static fn (GeofenceZone $zone): ?array => self::zoneGeometry($zone),
            self::activeZones()
        )));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function zoneGeometry(?GeofenceZone $zone): ?array
    {
        if (!$zone) {
            return null;
        }

        $shapeType = strtoupper((string) $zone->shape_type);

        if ($shapeType === self::SHAPE_CIRCLE) {
            if ($zone->center_latitude === null || $zone->center_longitude === null || $zone->radius_meters === null) {
                return null;
            }

            return [
                'shape_type' => self::SHAPE_CIRCLE,
                'center_latitude' => (float) $zone->center_latitude,
                'center_longitude' => (float) $zone->center_longitude,
                'radius_meters' => (int) $zone->radius_meters,
            ];
        }

        if ($shapeType === self::SHAPE_RECTANGLE || $shapeType === self::SHAPE_SQUARE) {
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

        if ($shapeType === self::SHAPE_POLYGON) {
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
                'shape_type' => self::SHAPE_POLYGON,
                'vertices' => $vertices,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $geometry
     * @return array<string, mixed>|null
     */
    private static function normalizeGeometry(string $shapeType, array $geometry): ?array
    {
        if ($shapeType === self::SHAPE_CIRCLE) {
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
                'center_latitude' => $centerLatitude,
                'center_longitude' => $centerLongitude,
                'radius_meters' => $radiusMeters,
            ];
        }

        if ($shapeType === self::SHAPE_RECTANGLE || $shapeType === self::SHAPE_SQUARE) {
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

            if ($shapeType === self::SHAPE_SQUARE) {
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
                'bounds' => [
                    'north' => $north,
                    'south' => $south,
                    'east' => $east,
                    'west' => $west,
                ],
            ];
        }

        if ($shapeType === self::SHAPE_POLYGON) {
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

            return ['vertices' => $normalized];
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

        $shapeType = (string) $geometry['shape_type'];

        if ($shapeType === self::SHAPE_CIRCLE) {
            if (!isset($geometry['center_latitude'], $geometry['center_longitude'])) {
                return null;
            }

            return [
                'latitude' => (float) $geometry['center_latitude'],
                'longitude' => (float) $geometry['center_longitude'],
            ];
        }

        if ($shapeType === self::SHAPE_RECTANGLE || $shapeType === self::SHAPE_SQUARE) {
            $bounds = $geometry['bounds'] ?? null;
            if (!is_array($bounds)) {
                return null;
            }

            return [
                'latitude' => ((float) $bounds['north'] + (float) $bounds['south']) / 2.0,
                'longitude' => ((float) $bounds['east'] + (float) $bounds['west']) / 2.0,
            ];
        }

        if ($shapeType === self::SHAPE_POLYGON) {
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

    private static function toBool(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
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
