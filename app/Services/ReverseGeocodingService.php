<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ReverseGeocodingService
{
    /**
     * @return array{
     *   barangay:string|null,
     *   municipality:string|null,
     *   province:string|null,
     *   display_name:string|null
     * }
     */
    public function reverse(float $latitude, float $longitude, ?string $acceptLanguage = null): array
    {
        $cacheSeconds = max(60, (int) config('services.reverse_geocoding.cache_seconds', 86400));
        $cacheKey = sprintf(
            'reverse-geocode:%s:%s:%s',
            number_format($latitude, 6, '.', ''),
            number_format($longitude, 6, '.', ''),
            md5((string) $acceptLanguage)
        );

        return Cache::remember($cacheKey, now()->addSeconds($cacheSeconds), function () use ($latitude, $longitude, $acceptLanguage): array {
            $baseUrl = rtrim((string) config('services.reverse_geocoding.base_url', 'https://nominatim.openstreetmap.org'), '/');
            $userAgent = trim((string) config('services.reverse_geocoding.user_agent', config('app.name', 'Laravel') . ' reverse-geocoder'));
            $email = trim((string) config('services.reverse_geocoding.email', ''));
            $timeout = max(2, (int) config('services.reverse_geocoding.timeout', 10));

            $query = [
                'format' => 'jsonv2',
                'lat' => $latitude,
                'lon' => $longitude,
                'addressdetails' => 1,
                'layer' => 'address',
                'zoom' => 18,
            ];

            if ($email !== '') {
                $query['email'] = $email;
            }

            $headers = [
                'User-Agent' => $userAgent,
                'Accept' => 'application/json',
            ];

            if (is_string($acceptLanguage) && trim($acceptLanguage) !== '') {
                $headers['Accept-Language'] = trim($acceptLanguage);
            }

            $response = Http::timeout($timeout)
                ->withHeaders($headers)
                ->get($baseUrl . '/reverse', $query);

            if (!$response->successful()) {
                throw new RuntimeException('Reverse geocoding request failed.');
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                throw new RuntimeException('Reverse geocoding response was invalid.');
            }

            $address = isset($payload['address']) && is_array($payload['address'])
                ? $payload['address']
                : [];

            $barangay = $this->firstString($address, [
                'city_district',
                'suburb',
                'quarter',
                'neighbourhood',
                'hamlet',
                'isolated_dwelling',
                'village',
            ]);

            $municipality = $this->firstString($address, [
                'municipality',
                'town',
                'city',
                'county',
            ]);

            $province = $this->firstString($address, [
                'state',
                'region',
                'province',
                'state_district',
            ]);

            $barangay = $this->sanitizeAdministrativeLabel($barangay, [
                $municipality,
                $province,
            ]);

            $municipality = $this->sanitizeAdministrativeLabel($municipality, [
                $barangay,
                $province,
            ]);

            return [
                'barangay' => $barangay,
                'municipality' => $municipality,
                'province' => $province,
                'display_name' => isset($payload['display_name']) && is_string($payload['display_name'])
                    ? trim($payload['display_name'])
                    : null,
            ];
        });
    }

    /**
     * @param array<string, mixed> $address
     * @param array<int, string> $keys
     */
    private function firstString(array $address, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $address[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<int, string|null> $disallowedValues
     */
    private function sanitizeAdministrativeLabel(?string $value, array $disallowedValues): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $normalizedValue = mb_strtolower(trim($value));
        foreach ($disallowedValues as $disallowedValue) {
            if (!is_string($disallowedValue) || trim($disallowedValue) === '') {
                continue;
            }

            if ($normalizedValue === mb_strtolower(trim($disallowedValue))) {
                return null;
            }
        }

        return trim($value);
    }
}
