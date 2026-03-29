<?php

namespace App\Support;

class Polygon
{
    /**
     * @param array<int, array<int, float|int|string>> $vertices
     */
    public static function containsPoint(float $latitude, float $longitude, array $vertices): bool
    {
        $count = count($vertices);
        if ($count < 3) {
            return false;
        }

        $inside = false;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $latI = isset($vertices[$i][0]) && is_numeric($vertices[$i][0]) ? (float) $vertices[$i][0] : null;
            $lngI = isset($vertices[$i][1]) && is_numeric($vertices[$i][1]) ? (float) $vertices[$i][1] : null;
            $latJ = isset($vertices[$j][0]) && is_numeric($vertices[$j][0]) ? (float) $vertices[$j][0] : null;
            $lngJ = isset($vertices[$j][1]) && is_numeric($vertices[$j][1]) ? (float) $vertices[$j][1] : null;

            if ($latI === null || $lngI === null || $latJ === null || $lngJ === null) {
                continue;
            }

            if (self::pointOnSegment($latitude, $longitude, $latI, $lngI, $latJ, $lngJ)) {
                return true;
            }

            $latIntersects = (($latI > $latitude) !== ($latJ > $latitude));
            if (!$latIntersects) {
                continue;
            }

            $slope = $latJ - $latI;
            if (abs($slope) < 1.0e-12) {
                continue;
            }

            $intersectLng = (($lngJ - $lngI) * ($latitude - $latI) / $slope) + $lngI;
            if ($longitude < $intersectLng) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    private static function pointOnSegment(
        float $latitude,
        float $longitude,
        float $segmentStartLatitude,
        float $segmentStartLongitude,
        float $segmentEndLatitude,
        float $segmentEndLongitude
    ): bool {
        $epsilon = 1.0e-10;

        if (
            $latitude < min($segmentStartLatitude, $segmentEndLatitude) - $epsilon
            || $latitude > max($segmentStartLatitude, $segmentEndLatitude) + $epsilon
            || $longitude < min($segmentStartLongitude, $segmentEndLongitude) - $epsilon
            || $longitude > max($segmentStartLongitude, $segmentEndLongitude) + $epsilon
        ) {
            return false;
        }

        $crossProduct = (($latitude - $segmentStartLatitude) * ($segmentEndLongitude - $segmentStartLongitude))
            - (($longitude - $segmentStartLongitude) * ($segmentEndLatitude - $segmentStartLatitude));

        return abs($crossProduct) <= $epsilon;
    }
}
