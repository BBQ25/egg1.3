<?php
// Campus attendance geofence policy + submission validation helpers.

if (!function_exists('attendance_geo_bool')) {
    function attendance_geo_bool($value) {
        if (is_bool($value)) return $value;
        $v = strtolower(trim((string) $value));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('attendance_geo_to_float_or_null')) {
    function attendance_geo_to_float_or_null($value) {
        if ($value === null) return null;
        $v = trim((string) $value);
        if ($v === '') return null;
        if (!is_numeric($v)) return null;
        $f = (float) $v;
        if (!is_finite($f)) return null;
        return $f;
    }
}

if (!function_exists('attendance_geo_normalize_lat')) {
    function attendance_geo_normalize_lat($value) {
        $f = attendance_geo_to_float_or_null($value);
        if ($f === null) return null;
        if ($f < -90.0 || $f > 90.0) return null;
        return $f;
    }
}

if (!function_exists('attendance_geo_normalize_lng')) {
    function attendance_geo_normalize_lng($value) {
        $f = attendance_geo_to_float_or_null($value);
        if ($f === null) return null;
        if ($f < -180.0 || $f > 180.0) return null;
        return $f;
    }
}

if (!function_exists('attendance_geo_normalize_accuracy')) {
    function attendance_geo_normalize_accuracy($value) {
        $f = attendance_geo_to_float_or_null($value);
        if ($f === null) return null;
        if ($f < 0) return null;
        if ($f > 50000) $f = 50000;
        return $f;
    }
}

if (!function_exists('attendance_geo_has_column')) {
    function attendance_geo_has_column(mysqli $conn, $table, $column) {
        if (function_exists('attendance_db_has_column')) {
            return attendance_db_has_column($conn, $table, $column);
        }

        $table = trim((string) $table);
        $column = trim((string) $column);
        if ($table === '' || $column === '') return false;

        $stmt = $conn->prepare(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $res = $stmt->get_result();
        $ok = ($res && $res->num_rows === 1);
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('attendance_geo_normalize_radius')) {
    function attendance_geo_normalize_radius($value) {
        $r = (int) $value;
        if ($r < 25) $r = 25;
        if ($r > 50000) $r = 50000;
        return $r;
    }
}

if (!function_exists('attendance_geo_max_boundary_shapes')) {
    function attendance_geo_max_boundary_shapes() {
        return 5;
    }
}

if (!function_exists('attendance_geo_normalize_max_accuracy')) {
    function attendance_geo_normalize_max_accuracy($value) {
        if ($value === null) return null;
        $v = trim((string) $value);
        if ($v === '') return null;
        if (!is_numeric($v)) return null;

        $n = (int) round((float) $v);
        if ($n <= 0) return null;
        if ($n < 5) $n = 5;
        if ($n > 5000) $n = 5000;
        return $n;
    }
}

if (!function_exists('attendance_geo_to_datetime_or_null')) {
    function attendance_geo_to_datetime_or_null($value) {
        $v = trim((string) $value);
        if ($v === '') return null;

        $ts = strtotime($v);
        if ($ts === false) return null;

        return date('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('attendance_geo_policy_defaults')) {
    function attendance_geo_policy_defaults($campusId = 0) {
        return [
            'campus_id' => (int) $campusId,
            'geofence_enabled' => 0,
            'center_latitude' => null,
            'center_longitude' => null,
            'radius_meters' => 250,
            'max_accuracy_m' => null,
            'boundary_type' => 'circle',
            'boundary_polygon' => [],
            'boundary_shapes' => [],
            'updated_by' => null,
            'updated_by_superadmin' => 0,
            'updated_at' => null,
        ];
    }
}

if (!function_exists('attendance_geo_ensure_columns')) {
    function attendance_geo_ensure_columns(mysqli $conn) {
        if (!attendance_geo_has_column($conn, 'campus_attendance_geofence_settings', 'boundary_type')) {
            $conn->query(
                "ALTER TABLE campus_attendance_geofence_settings
                 ADD COLUMN boundary_type ENUM('circle','polygon') NOT NULL DEFAULT 'circle' AFTER radius_meters"
            );
        }
        if (!attendance_geo_has_column($conn, 'campus_attendance_geofence_settings', 'boundary_polygon')) {
            $conn->query(
                "ALTER TABLE campus_attendance_geofence_settings
                 ADD COLUMN boundary_polygon TEXT NULL AFTER boundary_type"
            );
        }
        if (!attendance_geo_has_column($conn, 'campus_attendance_geofence_settings', 'boundary_shapes')) {
            $conn->query(
                "ALTER TABLE campus_attendance_geofence_settings
                 ADD COLUMN boundary_shapes TEXT NULL AFTER boundary_polygon"
            );
        }
        if (!attendance_geo_has_column($conn, 'campus_attendance_geofence_settings', 'max_accuracy_m')) {
            $conn->query(
                "ALTER TABLE campus_attendance_geofence_settings
                 ADD COLUMN max_accuracy_m INT UNSIGNED NULL AFTER radius_meters"
            );
        }
    }
}

if (!function_exists('attendance_geo_normalize_boundary_type')) {
    function attendance_geo_normalize_boundary_type($value) {
        $v = strtolower(trim((string) $value));
        return $v === 'polygon' ? 'polygon' : 'circle';
    }
}

if (!function_exists('attendance_geo_normalize_polygon')) {
    function attendance_geo_normalize_polygon($value) {
        $raw = $value;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $raw = $decoded;
            } else {
                return [];
            }
        }
        if (!is_array($raw)) return [];

        $points = [];
        foreach ($raw as $pt) {
            if (!is_array($pt)) continue;
            $lat = attendance_geo_normalize_lat($pt['lat'] ?? ($pt[0] ?? null));
            $lng = attendance_geo_normalize_lng($pt['lng'] ?? ($pt[1] ?? null));
            if ($lat === null || $lng === null) continue;
            $points[] = ['lat' => $lat, 'lng' => $lng];
            if (count($points) >= 512) break;
        }

        return $points;
    }
}

if (!function_exists('attendance_geo_polygon_to_json')) {
    function attendance_geo_polygon_to_json(array $points) {
        if (count($points) < 3) return '';
        $json = json_encode(array_values($points));
        return $json === false ? '' : $json;
    }
}

if (!function_exists('attendance_geo_normalize_shape')) {
    function attendance_geo_normalize_shape($shape) {
        if (is_string($shape)) {
            $decoded = json_decode($shape, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $shape = $decoded;
            }
        }
        if (!is_array($shape)) return null;

        $type = attendance_geo_normalize_boundary_type($shape['type'] ?? ($shape['boundary_type'] ?? 'circle'));
        if ($type === 'polygon') {
            $points = attendance_geo_normalize_polygon(
                $shape['points'] ?? ($shape['boundary_polygon'] ?? ($shape['polygon'] ?? []))
            );
            if (count($points) < 3) return null;
            return [
                'type' => 'polygon',
                'points' => array_values($points),
            ];
        }

        $centerLat = attendance_geo_normalize_lat(
            $shape['center_latitude'] ?? ($shape['latitude'] ?? ($shape['lat'] ?? null))
        );
        $centerLng = attendance_geo_normalize_lng(
            $shape['center_longitude'] ?? ($shape['longitude'] ?? ($shape['lng'] ?? null))
        );
        if ($centerLat === null || $centerLng === null) return null;

        $radius = attendance_geo_normalize_radius((int) ($shape['radius_meters'] ?? ($shape['radius'] ?? 250)));
        return [
            'type' => 'circle',
            'center_latitude' => $centerLat,
            'center_longitude' => $centerLng,
            'radius_meters' => $radius,
        ];
    }
}

if (!function_exists('attendance_geo_normalize_shapes')) {
    function attendance_geo_normalize_shapes($value, $limit = 0) {
        $maxShapes = attendance_geo_max_boundary_shapes();
        if ($limit <= 0 || $limit > $maxShapes) $limit = $maxShapes;

        $raw = $value;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $raw = $decoded;
            } else {
                return [];
            }
        }

        if (is_array($raw) && isset($raw['boundary_shapes']) && is_array($raw['boundary_shapes'])) {
            $raw = $raw['boundary_shapes'];
        }

        // Accept a single shape object and normalize it into a one-item list.
        if (is_array($raw) && (isset($raw['type']) || isset($raw['boundary_type']) || isset($raw['center_latitude']))) {
            $single = attendance_geo_normalize_shape($raw);
            return $single ? [$single] : [];
        }

        if (!is_array($raw)) return [];

        $shapes = [];
        foreach ($raw as $entry) {
            $shape = attendance_geo_normalize_shape($entry);
            if (!is_array($shape)) continue;
            $shapes[] = $shape;
            if (count($shapes) >= $limit) break;
        }
        return $shapes;
    }
}

if (!function_exists('attendance_geo_shapes_to_json')) {
    function attendance_geo_shapes_to_json(array $shapes) {
        $normalized = attendance_geo_normalize_shapes($shapes, attendance_geo_max_boundary_shapes());
        if (count($normalized) === 0) return '';
        $json = json_encode(array_values($normalized));
        return $json === false ? '' : $json;
    }
}

if (!function_exists('attendance_geo_shapes_first_legacy')) {
    function attendance_geo_shapes_first_legacy(array $shapes) {
        $defaults = [
            'boundary_type' => 'circle',
            'boundary_polygon' => [],
            'center_latitude' => null,
            'center_longitude' => null,
            'radius_meters' => 250,
        ];

        $list = attendance_geo_normalize_shapes($shapes, attendance_geo_max_boundary_shapes());
        if (count($list) === 0) return $defaults;

        $first = $list[0];
        if (($first['type'] ?? 'circle') === 'polygon') {
            $defaults['boundary_type'] = 'polygon';
            $defaults['boundary_polygon'] = attendance_geo_normalize_polygon($first['points'] ?? []);
            return $defaults;
        }

        $defaults['boundary_type'] = 'circle';
        $defaults['center_latitude'] = attendance_geo_normalize_lat($first['center_latitude'] ?? null);
        $defaults['center_longitude'] = attendance_geo_normalize_lng($first['center_longitude'] ?? null);
        $defaults['radius_meters'] = attendance_geo_normalize_radius((int) ($first['radius_meters'] ?? 250));
        return $defaults;
    }
}

if (!function_exists('attendance_geo_policy_shapes')) {
    function attendance_geo_policy_shapes(array $policy) {
        $shapes = attendance_geo_normalize_shapes($policy['boundary_shapes'] ?? null, attendance_geo_max_boundary_shapes());
        if (count($shapes) > 0) return $shapes;

        $type = attendance_geo_normalize_boundary_type($policy['boundary_type'] ?? 'circle');
        if ($type === 'polygon') {
            $poly = attendance_geo_normalize_polygon($policy['boundary_polygon'] ?? null);
            if (count($poly) >= 3) {
                return [[
                    'type' => 'polygon',
                    'points' => $poly,
                ]];
            }
            return [];
        }

        $lat = attendance_geo_normalize_lat($policy['center_latitude'] ?? null);
        $lng = attendance_geo_normalize_lng($policy['center_longitude'] ?? null);
        if ($lat === null || $lng === null) return [];

        return [[
            'type' => 'circle',
            'center_latitude' => $lat,
            'center_longitude' => $lng,
            'radius_meters' => attendance_geo_normalize_radius((int) ($policy['radius_meters'] ?? 250)),
        ]];
    }
}

if (!function_exists('attendance_geo_point_on_segment')) {
    function attendance_geo_point_on_segment($pLat, $pLng, $aLat, $aLng, $bLat, $bLng, $eps = 0.000000001) {
        $pLat = (float) $pLat;
        $pLng = (float) $pLng;
        $aLat = (float) $aLat;
        $aLng = (float) $aLng;
        $bLat = (float) $bLat;
        $bLng = (float) $bLng;
        $eps = (float) $eps;
        if ($eps <= 0) $eps = 0.000000001;

        $cross = (($pLat - $aLat) * ($bLng - $aLng)) - (($pLng - $aLng) * ($bLat - $aLat));
        if (abs($cross) > $eps) return false;

        if ($pLat < min($aLat, $bLat) - $eps || $pLat > max($aLat, $bLat) + $eps) return false;
        if ($pLng < min($aLng, $bLng) - $eps || $pLng > max($aLng, $bLng) + $eps) return false;

        return true;
    }
}

if (!function_exists('attendance_geo_point_in_polygon')) {
    function attendance_geo_point_in_polygon($lat, $lng, array $points) {
        $lat = attendance_geo_normalize_lat($lat);
        $lng = attendance_geo_normalize_lng($lng);
        if ($lat === null || $lng === null) return false;
        $count = count($points);
        if ($count < 3) return false;

        $inside = false;
        $j = $count - 1;
        for ($i = 0; $i < $count; $i++) {
            $yi = (float) $points[$i]['lat'];
            $xi = (float) $points[$i]['lng'];
            $yj = (float) $points[$j]['lat'];
            $xj = (float) $points[$j]['lng'];

            // Consider points on polygon edges as inside to reduce false outside denials.
            if (attendance_geo_point_on_segment($lat, $lng, $yi, $xi, $yj, $xj)) {
                return true;
            }

            $den = $yj - $yi;
            if (abs($den) < 0.0000000001) $den = ($den < 0) ? -0.0000000001 : 0.0000000001;
            $xIntersect = (($xj - $xi) * ($lat - $yi)) / $den + $xi;
            $intersects = (($yi > $lat) !== ($yj > $lat)) && ($lng < $xIntersect);
            if ($intersects) $inside = !$inside;

            $j = $i;
        }
        return $inside;
    }
}

if (!function_exists('attendance_geo_point_in_shape')) {
    function attendance_geo_point_in_shape($lat, $lng, array $shape, &$distanceMeters = null, &$radiusMeters = null) {
        $distanceMeters = null;
        $radiusMeters = null;

        $lat = attendance_geo_normalize_lat($lat);
        $lng = attendance_geo_normalize_lng($lng);
        if ($lat === null || $lng === null) return false;

        $type = attendance_geo_normalize_boundary_type($shape['type'] ?? ($shape['boundary_type'] ?? 'circle'));
        if ($type === 'polygon') {
            $points = attendance_geo_normalize_polygon($shape['points'] ?? ($shape['boundary_polygon'] ?? null));
            if (count($points) < 3) return false;
            return attendance_geo_point_in_polygon($lat, $lng, $points);
        }

        $centerLat = attendance_geo_normalize_lat($shape['center_latitude'] ?? null);
        $centerLng = attendance_geo_normalize_lng($shape['center_longitude'] ?? null);
        if ($centerLat === null || $centerLng === null) return false;

        $radius = attendance_geo_normalize_radius((int) ($shape['radius_meters'] ?? 250));
        $distance = attendance_geo_haversine_meters($lat, $lng, $centerLat, $centerLng);
        $distanceMeters = round((float) $distance, 2);
        $radiusMeters = $radius;
        return ((float) $distanceMeters <= (float) $radius);
    }
}

if (!function_exists('attendance_geo_evaluate_point_in_shapes')) {
    function attendance_geo_evaluate_point_in_shapes($lat, $lng, array $shapes) {
        $normalized = attendance_geo_normalize_shapes($shapes, attendance_geo_max_boundary_shapes());
        $out = [
            'within' => false,
            'distance_m' => null,
            'radius_meters' => null,
            'matched_shape_type' => null,
            'matched_shape_index' => null,
        ];
        if (count($normalized) === 0) return $out;

        foreach ($normalized as $idx => $shape) {
            $distance = null;
            $radius = null;
            $inside = attendance_geo_point_in_shape($lat, $lng, $shape, $distance, $radius);
            if (!$inside) continue;

            $out['within'] = true;
            $out['distance_m'] = $distance;
            $out['radius_meters'] = $radius;
            $out['matched_shape_type'] = attendance_geo_normalize_boundary_type($shape['type'] ?? 'circle');
            $out['matched_shape_index'] = (int) $idx;
            return $out;
        }

        return $out;
    }
}

if (!function_exists('attendance_geo_shape_sample_points')) {
    function attendance_geo_shape_sample_points(array $shape) {
        $type = attendance_geo_normalize_boundary_type($shape['type'] ?? ($shape['boundary_type'] ?? 'circle'));
        $points = [];

        if ($type === 'polygon') {
            $poly = attendance_geo_normalize_polygon($shape['points'] ?? ($shape['boundary_polygon'] ?? null));
            $count = count($poly);
            if ($count < 3) return [];

            for ($i = 0; $i < $count; $i++) {
                $a = $poly[$i];
                $b = $poly[($i + 1) % $count];
                $points[] = ['lat' => (float) $a['lat'], 'lng' => (float) $a['lng']];
                // Add interior edge sample points to reduce false containment positives.
                for ($s = 1; $s <= 3; $s++) {
                    $t = $s / 4.0;
                    $points[] = [
                        'lat' => ((1.0 - $t) * (float) $a['lat']) + ($t * (float) $b['lat']),
                        'lng' => ((1.0 - $t) * (float) $a['lng']) + ($t * (float) $b['lng']),
                    ];
                }
                if (count($points) >= 1024) break;
            }
            return $points;
        }

        $centerLat = attendance_geo_normalize_lat($shape['center_latitude'] ?? null);
        $centerLng = attendance_geo_normalize_lng($shape['center_longitude'] ?? null);
        if ($centerLat === null || $centerLng === null) return [];

        $radius = attendance_geo_normalize_radius((int) ($shape['radius_meters'] ?? 250));
        $earthRadius = 6371000.0;
        $angularDistance = $radius / $earthRadius;
        $centerLatRad = deg2rad($centerLat);
        $centerLngRad = deg2rad($centerLng);

        $points[] = ['lat' => $centerLat, 'lng' => $centerLng];
        $segments = 24;
        for ($i = 0; $i < $segments; $i++) {
            $bearing = (2 * M_PI * $i) / $segments;

            $lat2 = asin(
                sin($centerLatRad) * cos($angularDistance) +
                cos($centerLatRad) * sin($angularDistance) * cos($bearing)
            );
            $lng2 = $centerLngRad + atan2(
                sin($bearing) * sin($angularDistance) * cos($centerLatRad),
                cos($angularDistance) - sin($centerLatRad) * sin($lat2)
            );

            $ptLat = rad2deg($lat2);
            $ptLng = rad2deg($lng2);
            if ($ptLng > 180) $ptLng -= 360;
            if ($ptLng < -180) $ptLng += 360;

            $nLat = attendance_geo_normalize_lat($ptLat);
            $nLng = attendance_geo_normalize_lng($ptLng);
            if ($nLat === null || $nLng === null) continue;
            $points[] = ['lat' => $nLat, 'lng' => $nLng];
        }

        return $points;
    }
}

if (!function_exists('attendance_geo_shape_within_shapes')) {
    function attendance_geo_shape_within_shapes(array $shape, array $containerShapes) {
        $shapeNorm = attendance_geo_normalize_shape($shape);
        if (!is_array($shapeNorm)) return false;

        $container = attendance_geo_normalize_shapes($containerShapes, attendance_geo_max_boundary_shapes());
        if (count($container) === 0) return false;

        $samples = attendance_geo_shape_sample_points($shapeNorm);
        if (count($samples) === 0) return false;

        foreach ($samples as $sample) {
            $insideAny = false;
            foreach ($container as $c) {
                if (attendance_geo_point_in_shape($sample['lat'], $sample['lng'], $c)) {
                    $insideAny = true;
                    break;
                }
            }
            if (!$insideAny) return false;
        }
        return true;
    }
}

if (!function_exists('attendance_geo_ensure_table')) {
    function attendance_geo_ensure_table(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query(
            "CREATE TABLE IF NOT EXISTS campus_attendance_geofence_settings (
                campus_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                geofence_enabled TINYINT(1) NOT NULL DEFAULT 0,
                center_latitude DECIMAL(10,8) NULL,
                center_longitude DECIMAL(11,8) NULL,
                radius_meters INT UNSIGNED NOT NULL DEFAULT 250,
                max_accuracy_m INT UNSIGNED NULL,
                boundary_type ENUM('circle','polygon') NOT NULL DEFAULT 'circle',
                boundary_polygon TEXT NULL,
                boundary_shapes TEXT NULL,
                updated_by INT NULL,
                updated_by_superadmin TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cags_enabled (geofence_enabled),
                CONSTRAINT fk_cags_campus
                    FOREIGN KEY (campus_id) REFERENCES campuses(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_cags_updated_by
                    FOREIGN KEY (updated_by) REFERENCES users(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        attendance_geo_ensure_columns($conn);
    }
}

if (!function_exists('attendance_geo_class_policy_defaults')) {
    function attendance_geo_class_policy_defaults($classRecordId = 0) {
        return [
            'class_record_id' => (int) $classRecordId,
            'geofence_enabled' => 0,
            'center_latitude' => null,
            'center_longitude' => null,
            'radius_meters' => 60,
            'max_accuracy_m' => null,
            'boundary_type' => 'circle',
            'boundary_polygon' => [],
            'boundary_shapes' => [],
            'updated_by' => null,
            'updated_at' => null,
        ];
    }
}

if (!function_exists('attendance_geo_ensure_class_columns')) {
    function attendance_geo_ensure_class_columns(mysqli $conn) {
        if (!attendance_geo_has_column($conn, 'class_attendance_geofence_settings', 'boundary_type')) {
            $conn->query(
                "ALTER TABLE class_attendance_geofence_settings
                 ADD COLUMN boundary_type ENUM('circle','polygon') NOT NULL DEFAULT 'circle' AFTER radius_meters"
            );
        }
        if (!attendance_geo_has_column($conn, 'class_attendance_geofence_settings', 'boundary_polygon')) {
            $conn->query(
                "ALTER TABLE class_attendance_geofence_settings
                 ADD COLUMN boundary_polygon TEXT NULL AFTER boundary_type"
            );
        }
        if (!attendance_geo_has_column($conn, 'class_attendance_geofence_settings', 'boundary_shapes')) {
            $conn->query(
                "ALTER TABLE class_attendance_geofence_settings
                 ADD COLUMN boundary_shapes TEXT NULL AFTER boundary_polygon"
            );
        }
        if (!attendance_geo_has_column($conn, 'class_attendance_geofence_settings', 'max_accuracy_m')) {
            $conn->query(
                "ALTER TABLE class_attendance_geofence_settings
                 ADD COLUMN max_accuracy_m INT UNSIGNED NULL AFTER radius_meters"
            );
        }
    }
}

if (!function_exists('attendance_geo_ensure_class_table')) {
    function attendance_geo_ensure_class_table(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query(
            "CREATE TABLE IF NOT EXISTS class_attendance_geofence_settings (
                class_record_id INT NOT NULL PRIMARY KEY,
                geofence_enabled TINYINT(1) NOT NULL DEFAULT 0,
                center_latitude DECIMAL(10,8) NULL,
                center_longitude DECIMAL(11,8) NULL,
                radius_meters INT UNSIGNED NOT NULL DEFAULT 60,
                max_accuracy_m INT UNSIGNED NULL,
                boundary_type ENUM('circle','polygon') NOT NULL DEFAULT 'circle',
                boundary_polygon TEXT NULL,
                boundary_shapes TEXT NULL,
                updated_by INT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cags_enabled (geofence_enabled),
                CONSTRAINT fk_clags_class_record
                    FOREIGN KEY (class_record_id) REFERENCES class_records(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_clags_updated_by
                    FOREIGN KEY (updated_by) REFERENCES users(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        attendance_geo_ensure_class_columns($conn);
    }
}

if (!function_exists('attendance_geo_get_policy')) {
    function attendance_geo_get_policy(mysqli $conn, $campusId) {
        attendance_geo_ensure_table($conn);
        $campusId = (int) $campusId;
        $defaults = attendance_geo_policy_defaults($campusId);
        if ($campusId <= 0) return $defaults;

        $stmt = $conn->prepare(
            "SELECT campus_id, geofence_enabled, center_latitude, center_longitude, radius_meters, max_accuracy_m, boundary_type, boundary_polygon, boundary_shapes, updated_by, updated_by_superadmin, updated_at
             FROM campus_attendance_geofence_settings
             WHERE campus_id = ?
             LIMIT 1"
        );
        if (!$stmt) return $defaults;

        $stmt->bind_param('i', $campusId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) return $defaults;

        $base = [
            'campus_id' => (int) ($row['campus_id'] ?? $campusId),
            'geofence_enabled' => ((int) ($row['geofence_enabled'] ?? 0) === 1) ? 1 : 0,
            'center_latitude' => attendance_geo_normalize_lat($row['center_latitude'] ?? null),
            'center_longitude' => attendance_geo_normalize_lng($row['center_longitude'] ?? null),
            'radius_meters' => attendance_geo_normalize_radius((int) ($row['radius_meters'] ?? 250)),
            'max_accuracy_m' => attendance_geo_normalize_max_accuracy($row['max_accuracy_m'] ?? null),
            'boundary_type' => attendance_geo_normalize_boundary_type($row['boundary_type'] ?? null),
            'boundary_polygon' => attendance_geo_normalize_polygon($row['boundary_polygon'] ?? null),
            'boundary_shapes' => attendance_geo_normalize_shapes($row['boundary_shapes'] ?? null, attendance_geo_max_boundary_shapes()),
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'updated_by_superadmin' => ((int) ($row['updated_by_superadmin'] ?? 0) === 1) ? 1 : 0,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];

        $shapes = attendance_geo_policy_shapes($base);
        $legacy = attendance_geo_shapes_first_legacy($shapes);
        $base['boundary_type'] = $legacy['boundary_type'];
        $base['boundary_polygon'] = $legacy['boundary_polygon'];
        $base['center_latitude'] = $legacy['center_latitude'];
        $base['center_longitude'] = $legacy['center_longitude'];
        $base['radius_meters'] = $legacy['radius_meters'];
        $base['boundary_shapes'] = $shapes;
        return $base;
    }
}

if (!function_exists('attendance_geo_save_policy')) {
    function attendance_geo_save_policy(mysqli $conn, $campusId, array $policy, $updatedBy, $updatedBySuperadmin = false) {
        attendance_geo_ensure_table($conn);
        $campusId = (int) $campusId;
        $updatedBy = (int) $updatedBy;
        if ($campusId <= 0 || $updatedBy <= 0) return [false, 'Invalid campus or user.'];

        $enabled = !empty($policy['geofence_enabled']) ? 1 : 0;
        $rawShapes = $policy['boundary_shapes'] ?? null;
        $shapes = attendance_geo_normalize_shapes($rawShapes, attendance_geo_max_boundary_shapes());

        // Backward-compatibility for legacy single-boundary payloads.
        if (count($shapes) === 0) {
            $legacyType = attendance_geo_normalize_boundary_type($policy['boundary_type'] ?? 'circle');
            if ($legacyType === 'polygon') {
                $legacyPoly = attendance_geo_normalize_polygon($policy['boundary_polygon'] ?? null);
                if (count($legacyPoly) >= 3) {
                    $shapes = [[
                        'type' => 'polygon',
                        'points' => $legacyPoly,
                    ]];
                }
            } else {
                $legacyLat = attendance_geo_normalize_lat($policy['center_latitude'] ?? null);
                $legacyLng = attendance_geo_normalize_lng($policy['center_longitude'] ?? null);
                if ($legacyLat !== null && $legacyLng !== null) {
                    $shapes = [[
                        'type' => 'circle',
                        'center_latitude' => $legacyLat,
                        'center_longitude' => $legacyLng,
                        'radius_meters' => attendance_geo_normalize_radius((int) ($policy['radius_meters'] ?? 250)),
                    ]];
                }
            }
        }

        if (count($shapes) > attendance_geo_max_boundary_shapes()) {
            return [false, 'A campus can only have up to ' . attendance_geo_max_boundary_shapes() . ' boundaries.'];
        }

        $legacy = attendance_geo_shapes_first_legacy($shapes);
        $boundaryType = attendance_geo_normalize_boundary_type($legacy['boundary_type'] ?? 'circle');
        $polygon = attendance_geo_normalize_polygon($legacy['boundary_polygon'] ?? null);
        $polygonJson = attendance_geo_polygon_to_json($polygon);
        $radius = attendance_geo_normalize_radius((int) ($legacy['radius_meters'] ?? 250));
        $maxAccuracy = attendance_geo_normalize_max_accuracy($policy['max_accuracy_m'] ?? null);
        $lat = attendance_geo_normalize_lat($legacy['center_latitude'] ?? null);
        $lng = attendance_geo_normalize_lng($legacy['center_longitude'] ?? null);
        $shapesJson = attendance_geo_shapes_to_json($shapes);
        $updatedBySuperadmin = $updatedBySuperadmin ? 1 : 0;

        if ($enabled === 1) {
            if (count($shapes) < 1 || $shapesJson === '') {
                return [false, 'At least one valid boundary is required when geofence is enabled.'];
            }
            if (count($shapes) > attendance_geo_max_boundary_shapes()) {
                return [false, 'A campus can only have up to ' . attendance_geo_max_boundary_shapes() . ' boundaries.'];
            }
        } else {
            // Keep the stored shape data for quick re-enable, but ensure legacy mirrors remain coherent.
            if (count($shapes) === 0) {
                $boundaryType = 'circle';
                $polygon = [];
                $polygonJson = '';
                $lat = null;
                $lng = null;
                $radius = attendance_geo_normalize_radius((int) ($policy['radius_meters'] ?? 250));
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO campus_attendance_geofence_settings
                (campus_id, geofence_enabled, center_latitude, center_longitude, radius_meters, max_accuracy_m, boundary_type, boundary_polygon, boundary_shapes, updated_by, updated_by_superadmin)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                geofence_enabled = VALUES(geofence_enabled),
                center_latitude = VALUES(center_latitude),
                center_longitude = VALUES(center_longitude),
                radius_meters = VALUES(radius_meters),
                max_accuracy_m = VALUES(max_accuracy_m),
                boundary_type = VALUES(boundary_type),
                boundary_polygon = VALUES(boundary_polygon),
                boundary_shapes = VALUES(boundary_shapes),
                updated_by = VALUES(updated_by),
                updated_by_superadmin = VALUES(updated_by_superadmin),
                updated_at = CURRENT_TIMESTAMP"
        );
        if (!$stmt) return [false, 'Unable to save geofence policy.'];

        $stmt->bind_param(
            'iiddiisssiii',
            $campusId,
            $enabled,
            $lat,
            $lng,
            $radius,
            $maxAccuracy,
            $boundaryType,
            $polygonJson,
            $shapesJson,
            $updatedBy,
            $updatedBySuperadmin
        );
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        if (!$ok) return [false, 'Unable to save geofence policy.'];

        return [true, attendance_geo_get_policy($conn, $campusId)];
    }
}

if (!function_exists('attendance_geo_class_record_campus_id')) {
    function attendance_geo_class_record_campus_id(mysqli $conn, $classRecordId) {
        $classRecordId = (int) $classRecordId;
        if ($classRecordId <= 0) return 0;

        $stmt = $conn->prepare(
            "SELECT
                COALESCE(
                    (
                        SELECT u_ta.campus_id
                        FROM teacher_assignments ta
                        JOIN users u_ta ON u_ta.id = ta.teacher_id
                        WHERE ta.class_record_id = cr.id
                          AND ta.status = 'active'
                          AND u_ta.campus_id IS NOT NULL
                        ORDER BY CASE WHEN ta.teacher_role = 'primary' THEN 0 ELSE 1 END, ta.id ASC
                        LIMIT 1
                    ),
                    u_cr.campus_id,
                    0
                ) AS campus_id
             FROM class_records cr
             LEFT JOIN users u_cr ON u_cr.id = cr.teacher_id
             WHERE cr.id = ?
             LIMIT 1"
        );
        if (!$stmt) return 0;

        $stmt->bind_param('i', $classRecordId);
        $stmt->execute();
        $res = $stmt->get_result();
        $campusId = 0;
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $campusId = (int) ($row['campus_id'] ?? 0);
        }
        $stmt->close();
        return $campusId;
    }
}

if (!function_exists('attendance_geo_get_class_policy')) {
    function attendance_geo_get_class_policy(mysqli $conn, $classRecordId) {
        attendance_geo_ensure_class_table($conn);
        $classRecordId = (int) $classRecordId;
        $defaults = attendance_geo_class_policy_defaults($classRecordId);
        if ($classRecordId <= 0) return $defaults;

        $stmt = $conn->prepare(
            "SELECT class_record_id, geofence_enabled, center_latitude, center_longitude, radius_meters, max_accuracy_m, boundary_type, boundary_polygon, boundary_shapes, updated_by, updated_at
             FROM class_attendance_geofence_settings
             WHERE class_record_id = ?
             LIMIT 1"
        );
        if (!$stmt) return $defaults;

        $stmt->bind_param('i', $classRecordId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) return $defaults;

        $base = [
            'class_record_id' => (int) ($row['class_record_id'] ?? $classRecordId),
            'geofence_enabled' => ((int) ($row['geofence_enabled'] ?? 0) === 1) ? 1 : 0,
            'center_latitude' => attendance_geo_normalize_lat($row['center_latitude'] ?? null),
            'center_longitude' => attendance_geo_normalize_lng($row['center_longitude'] ?? null),
            'radius_meters' => attendance_geo_normalize_radius((int) ($row['radius_meters'] ?? 60)),
            'max_accuracy_m' => attendance_geo_normalize_max_accuracy($row['max_accuracy_m'] ?? null),
            'boundary_type' => attendance_geo_normalize_boundary_type($row['boundary_type'] ?? null),
            'boundary_polygon' => attendance_geo_normalize_polygon($row['boundary_polygon'] ?? null),
            'boundary_shapes' => attendance_geo_normalize_shapes($row['boundary_shapes'] ?? null, attendance_geo_max_boundary_shapes()),
            'updated_by' => isset($row['updated_by']) ? (int) ($row['updated_by']) : null,
            'updated_at' => isset($row['updated_at']) ? (string) ($row['updated_at']) : null,
        ];

        $shapes = attendance_geo_policy_shapes($base);
        $legacy = attendance_geo_shapes_first_legacy($shapes);
        $base['boundary_type'] = $legacy['boundary_type'];
        $base['boundary_polygon'] = $legacy['boundary_polygon'];
        $base['center_latitude'] = $legacy['center_latitude'];
        $base['center_longitude'] = $legacy['center_longitude'];
        $base['radius_meters'] = $legacy['radius_meters'];
        $base['boundary_shapes'] = $shapes;
        return $base;
    }
}

if (!function_exists('attendance_geo_save_class_policy')) {
    function attendance_geo_save_class_policy(mysqli $conn, $classRecordId, array $policy, $updatedBy, $updatedBySuperadmin = false) {
        attendance_geo_ensure_table($conn);
        attendance_geo_ensure_class_table($conn);

        $classRecordId = (int) $classRecordId;
        $updatedBy = (int) $updatedBy;
        if ($classRecordId <= 0 || $updatedBy <= 0) return [false, 'Invalid class record or user.'];

        $enabled = !empty($policy['geofence_enabled']) ? 1 : 0;
        $rawShapes = $policy['boundary_shapes'] ?? null;
        $shapes = attendance_geo_normalize_shapes($rawShapes, attendance_geo_max_boundary_shapes());

        if (count($shapes) === 0) {
            $legacyType = attendance_geo_normalize_boundary_type($policy['boundary_type'] ?? 'circle');
            if ($legacyType === 'polygon') {
                $legacyPoly = attendance_geo_normalize_polygon($policy['boundary_polygon'] ?? null);
                if (count($legacyPoly) >= 3) {
                    $shapes = [[
                        'type' => 'polygon',
                        'points' => $legacyPoly,
                    ]];
                }
            } else {
                $legacyLat = attendance_geo_normalize_lat($policy['center_latitude'] ?? null);
                $legacyLng = attendance_geo_normalize_lng($policy['center_longitude'] ?? null);
                if ($legacyLat !== null && $legacyLng !== null) {
                    $shapes = [[
                        'type' => 'circle',
                        'center_latitude' => $legacyLat,
                        'center_longitude' => $legacyLng,
                        'radius_meters' => attendance_geo_normalize_radius((int) ($policy['radius_meters'] ?? 60)),
                    ]];
                }
            }
        }

        if (count($shapes) > 1) {
            return [false, 'Classroom premises supports one boundary only (circle or polygon).'];
        }

        $legacy = attendance_geo_shapes_first_legacy($shapes);
        $boundaryType = attendance_geo_normalize_boundary_type($legacy['boundary_type'] ?? 'circle');
        $polygon = attendance_geo_normalize_polygon($legacy['boundary_polygon'] ?? null);
        $polygonJson = attendance_geo_polygon_to_json($polygon);
        $radius = attendance_geo_normalize_radius((int) ($legacy['radius_meters'] ?? 60));
        $maxAccuracy = attendance_geo_normalize_max_accuracy($policy['max_accuracy_m'] ?? null);
        $lat = attendance_geo_normalize_lat($legacy['center_latitude'] ?? null);
        $lng = attendance_geo_normalize_lng($legacy['center_longitude'] ?? null);
        $shapesJson = attendance_geo_shapes_to_json($shapes);

        if ($enabled === 1) {
            if (count($shapes) < 1 || $shapesJson === '') {
                return [false, 'Draw a classroom boundary first (circle or polygon).'];
            }

            $campusId = attendance_geo_class_record_campus_id($conn, $classRecordId);
            $campusPolicy = attendance_geo_get_policy($conn, $campusId);
            $campusEnabled = ((int) ($campusPolicy['geofence_enabled'] ?? 0) === 1);
            $campusShapes = attendance_geo_policy_shapes($campusPolicy);

            if ($campusId <= 0 || !$campusEnabled || count($campusShapes) === 0) {
                return [false, 'Campus attendance boundary must be configured and enabled before classroom premises can be enabled.'];
            }

            foreach ($shapes as $shape) {
                if (!attendance_geo_shape_within_shapes($shape, $campusShapes)) {
                    return [false, 'Classroom premises must stay within the campus boundary set by campus admin.'];
                }
            }
        } else {
            if (count($shapes) === 0) {
                $boundaryType = 'circle';
                $polygon = [];
                $polygonJson = '';
                $lat = null;
                $lng = null;
                $radius = attendance_geo_normalize_radius((int) ($policy['radius_meters'] ?? 60));
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO class_attendance_geofence_settings
                (class_record_id, geofence_enabled, center_latitude, center_longitude, radius_meters, max_accuracy_m, boundary_type, boundary_polygon, boundary_shapes, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                geofence_enabled = VALUES(geofence_enabled),
                center_latitude = VALUES(center_latitude),
                center_longitude = VALUES(center_longitude),
                radius_meters = VALUES(radius_meters),
                max_accuracy_m = VALUES(max_accuracy_m),
                boundary_type = VALUES(boundary_type),
                boundary_polygon = VALUES(boundary_polygon),
                boundary_shapes = VALUES(boundary_shapes),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP"
        );
        if (!$stmt) return [false, 'Unable to save classroom premises boundary.'];

        $stmt->bind_param(
            'iiddiisssi',
            $classRecordId,
            $enabled,
            $lat,
            $lng,
            $radius,
            $maxAccuracy,
            $boundaryType,
            $polygonJson,
            $shapesJson,
            $updatedBy
        );
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        if (!$ok) return [false, 'Unable to save classroom premises boundary.'];

        return [true, attendance_geo_get_class_policy($conn, $classRecordId)];
    }
}

if (!function_exists('attendance_geo_haversine_meters')) {
    function attendance_geo_haversine_meters($lat1, $lng1, $lat2, $lng2) {
        $lat1 = (float) $lat1;
        $lng1 = (float) $lng1;
        $lat2 = (float) $lat2;
        $lng2 = (float) $lng2;

        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
        return $earthRadius * $c;
    }
}

if (!function_exists('attendance_geo_location_from_request_array')) {
    function attendance_geo_location_from_request_array(array $req) {
        $lat = attendance_geo_normalize_lat(
            $req['geo_latitude'] ?? ($req['latitude'] ?? ($req['lat'] ?? null))
        );
        $lng = attendance_geo_normalize_lng(
            $req['geo_longitude'] ?? ($req['longitude'] ?? ($req['lng'] ?? ($req['lon'] ?? null)))
        );
        $accuracy = attendance_geo_normalize_accuracy(
            $req['geo_accuracy_m'] ?? ($req['geo_accuracy'] ?? ($req['accuracy'] ?? null))
        );
        $capturedAt = attendance_geo_to_datetime_or_null(
            $req['geo_captured_at'] ?? ($req['captured_at'] ?? null)
        );

        return [
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy_m' => $accuracy,
            'captured_at' => $capturedAt,
        ];
    }
}

if (!function_exists('attendance_geo_session_campus_id')) {
    function attendance_geo_session_campus_id(mysqli $conn, array $session) {
        static $classCache = [];
        static $teacherCache = [];

        $classRecordId = (int) ($session['class_record_id'] ?? 0);
        if ($classRecordId > 0) {
            if (isset($classCache[$classRecordId])) return (int) $classCache[$classRecordId];
            $classCampusId = attendance_geo_class_record_campus_id($conn, $classRecordId);
            $classCache[$classRecordId] = $classCampusId;
            if ($classCampusId > 0) return $classCampusId;
        }

        $teacherUserId = (int) ($session['teacher_id'] ?? 0);
        if ($teacherUserId <= 0) return 0;
        if (isset($teacherCache[$teacherUserId])) return (int) $teacherCache[$teacherUserId];

        $stmt = $conn->prepare(
            "SELECT campus_id
             FROM users
             WHERE id = ?
             LIMIT 1"
        );
        if (!$stmt) return 0;

        $stmt->bind_param('i', $teacherUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $campusId = 0;
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $campusId = (int) ($row['campus_id'] ?? 0);
        }
        $stmt->close();

        $teacherCache[$teacherUserId] = $campusId;
        return $campusId;
    }
}

if (!function_exists('attendance_geo_evaluate_submission')) {
    function attendance_geo_evaluate_submission(mysqli $conn, array $session, array $location) {
        $location = array_merge([
            'latitude' => null,
            'longitude' => null,
            'accuracy_m' => null,
            'captured_at' => null,
        ], $location);

        $lat = attendance_geo_normalize_lat($location['latitude'] ?? null);
        $lng = attendance_geo_normalize_lng($location['longitude'] ?? null);
        $accuracy = attendance_geo_normalize_accuracy($location['accuracy_m'] ?? null);
        $capturedAt = attendance_geo_to_datetime_or_null($location['captured_at'] ?? null);
        if ($lat !== null && $lng !== null && $capturedAt === null) {
            $capturedAt = date('Y-m-d H:i:s');
        }

        $campusId = attendance_geo_session_campus_id($conn, $session);
        $policy = attendance_geo_get_policy($conn, $campusId);
        $campusEnabled = ((int) ($policy['geofence_enabled'] ?? 0) === 1);
        $campusShapes = attendance_geo_policy_shapes($policy);

        $classRecordId = (int) ($session['class_record_id'] ?? 0);
        $classPolicy = attendance_geo_get_class_policy($conn, $classRecordId);
        $classEnabled = ((int) ($classPolicy['geofence_enabled'] ?? 0) === 1);
        $classShapes = attendance_geo_policy_shapes($classPolicy);

        $result = [
            'allowed' => true,
            'enforced' => ($campusEnabled || $classEnabled),
            'message' => '',
            'campus_id' => $campusId,
            'class_record_id' => $classRecordId,
            'policy' => $policy,
            'class_policy' => $classPolicy,
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy_m' => $accuracy,
            'captured_at' => $capturedAt,
            'distance_m' => null,
            'within_boundary' => null,
            'radius_meters' => null,
            'max_accuracy_m' => attendance_geo_normalize_max_accuracy($policy['max_accuracy_m'] ?? null),
            'boundary_type' => attendance_geo_normalize_boundary_type($policy['boundary_type'] ?? 'circle'),
            'boundary_scope' => $classEnabled ? 'class' : 'campus',
            'campus_within_boundary' => null,
            'class_within_boundary' => null,
            'campus_boundary_count' => count($campusShapes),
            'class_boundary_count' => count($classShapes),
        ];

        if (!$result['enforced']) return $result;

        if ($lat === null || $lng === null) {
            $result['allowed'] = false;
            $result['message'] = 'Location is required for attendance in this class. Enable location services and try again.';
            return $result;
        }

        $maxAccuracy = attendance_geo_normalize_max_accuracy($policy['max_accuracy_m'] ?? null);
        $classMaxAccuracy = attendance_geo_normalize_max_accuracy($classPolicy['max_accuracy_m'] ?? null);
        if ($classMaxAccuracy !== null && ($maxAccuracy === null || $classMaxAccuracy < $maxAccuracy)) {
            $maxAccuracy = $classMaxAccuracy;
        }
        $result['max_accuracy_m'] = $maxAccuracy;

        if ($maxAccuracy !== null) {
            if ($accuracy === null) {
                $result['allowed'] = false;
                $result['message'] = 'Location accuracy is required for attendance in this class. Enable precise location and try again.';
                return $result;
            }
            if ((float) $accuracy > (float) $maxAccuracy) {
                $result['allowed'] = false;
                $result['message'] = 'Your GPS accuracy is too low (+/-' .
                    number_format((float) $accuracy, 0) . 'm). Required +/-' . (int) $maxAccuracy . 'm or better.';
                return $result;
            }
        }

        if ($campusEnabled) {
            if (count($campusShapes) < 1) {
                $result['allowed'] = false;
                $result['message'] = 'Attendance location boundary is enabled but not fully configured. Please contact your campus admin.';
                return $result;
            }

            $campusEval = attendance_geo_evaluate_point_in_shapes($lat, $lng, $campusShapes);
            $result['campus_within_boundary'] = $campusEval['within'] ? 1 : 0;
            if (!$campusEval['within']) {
                $result['allowed'] = false;
                $result['within_boundary'] = 0;
                $result['boundary_scope'] = 'campus';
                $result['message'] = 'You are outside the allowed campus attendance boundary.';
                return $result;
            }

            if ($result['distance_m'] === null && $campusEval['distance_m'] !== null) {
                $result['distance_m'] = $campusEval['distance_m'];
                $result['radius_meters'] = $campusEval['radius_meters'];
                $result['boundary_type'] = $campusEval['matched_shape_type'] ?? $result['boundary_type'];
            }
        }

        if ($classEnabled) {
            if (count($classShapes) < 1) {
                $result['allowed'] = false;
                $result['within_boundary'] = 0;
                $result['boundary_scope'] = 'class';
                $result['message'] = 'Classroom premises boundary is enabled but not configured. Please contact your teacher.';
                return $result;
            }

            $classEval = attendance_geo_evaluate_point_in_shapes($lat, $lng, $classShapes);
            $result['class_within_boundary'] = $classEval['within'] ? 1 : 0;
            $result['boundary_scope'] = 'class';
            if (!$classEval['within']) {
                $result['allowed'] = false;
                $result['within_boundary'] = 0;
                $result['message'] = 'You are outside the allowed classroom premises boundary.';
                return $result;
            }

            $result['distance_m'] = $classEval['distance_m'];
            $result['radius_meters'] = $classEval['radius_meters'];
            $result['boundary_type'] = $classEval['matched_shape_type'] ?? attendance_geo_normalize_boundary_type($classPolicy['boundary_type'] ?? 'circle');
            $result['within_boundary'] = 1;
            return $result;
        }

        if ($campusEnabled) {
            $campusEval = attendance_geo_evaluate_point_in_shapes($lat, $lng, $campusShapes);
            $result['distance_m'] = $campusEval['distance_m'];
            $result['radius_meters'] = $campusEval['radius_meters'];
            $result['boundary_type'] = $campusEval['matched_shape_type'] ?? attendance_geo_normalize_boundary_type($policy['boundary_type'] ?? 'circle');
            $result['within_boundary'] = $campusEval['within'] ? 1 : 0;
            return $result;
        }

        return $result;
    }
}
