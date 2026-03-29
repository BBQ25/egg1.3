<?php

namespace App\Domain\DocEase;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

class DocEaseGateway
{
    public function enabled(): bool
    {
        return (bool) config('doc_ease.enabled', false);
    }

    /**
     * @return list<string>
     */
    public function allowedRoles(): array
    {
        $roles = config('doc_ease.allowed_roles', []);
        if (!is_array($roles)) {
            return [];
        }

        $out = [];
        foreach ($roles as $role) {
            $value = strtoupper(trim((string) $role));
            if ($value === '') {
                continue;
            }

            $out[] = $value;
        }

        return array_values(array_unique($out));
    }

    public function userCanAccess(?Authenticatable $user): bool
    {
        if (!$user) {
            return false;
        }

        $allowedRoles = $this->allowedRoles();
        if ($allowedRoles === []) {
            return false;
        }

        $roleValue = $this->extractUserRoleValue($user);
        if ($roleValue === '') {
            return false;
        }

        return in_array($roleValue, $allowedRoles, true);
    }

    public function entrypoint(): string
    {
        return $this->normalizeLegacyPath(
            (string) config('doc_ease.entrypoint', '/doc-ease/index.php'),
            '/doc-ease/index.php'
        );
    }

    public function entrypointPublicPath(): string
    {
        return public_path(ltrim($this->entrypoint(), '/'));
    }

    public function entrypointExists(): bool
    {
        return is_file($this->entrypointPublicPath());
    }

    public function bridgeEnabled(): bool
    {
        return (bool) config('doc_ease.bridge.enabled', false);
    }

    public function bridgePath(): string
    {
        return $this->normalizeLegacyPath(
            (string) config('doc_ease.bridge.path', '/doc-ease/bridge-login.php'),
            '/doc-ease/bridge-login.php'
        );
    }

    public function bridgePublicPath(): string
    {
        return public_path(ltrim($this->bridgePath(), '/'));
    }

    public function bridgeExists(): bool
    {
        return is_file($this->bridgePublicPath());
    }

    public function bridgeTtlSeconds(): int
    {
        $ttl = (int) config('doc_ease.bridge.ttl_seconds', 90);
        if ($ttl < 30) {
            return 30;
        }
        if ($ttl > 900) {
            return 900;
        }
        return $ttl;
    }

    public function bridgeSecretConfigured(): bool
    {
        return trim((string) config('doc_ease.bridge.secret', '')) !== '';
    }

    public function launchUrlForUser(?Authenticatable $user): string
    {
        if (!$this->bridgeEnabled()) {
            return $this->entrypoint();
        }

        if (!$user) {
            throw new RuntimeException('Authenticated user required for Doc-Ease bridge launch.');
        }

        if (!$this->bridgeExists()) {
            throw new RuntimeException('Doc-Ease bridge endpoint file is missing.');
        }

        $secret = trim((string) config('doc_ease.bridge.secret', ''));
        if ($secret === '') {
            throw new RuntimeException('Doc-Ease bridge secret is not configured.');
        }

        $query = http_build_query([
            'token' => $this->issueBridgeToken($user, $secret),
            'next' => $this->entrypoint(),
        ]);

        if ($query === '' || $query === '0') {
            return $this->bridgePath();
        }

        return $this->bridgePath() . '?' . $query;
    }

    private function issueBridgeToken(Authenticatable $user, string $secret): string
    {
        $roleValue = $this->extractUserRoleValue($user);
        $legacyRole = $this->mapRoleToLegacy($roleValue);
        $now = time();

        $payload = [
            'iss' => 'laravel-doc-ease-gateway',
            'iat' => $now,
            'exp' => $now + $this->bridgeTtlSeconds(),
            'nonce' => bin2hex(random_bytes(8)),
            'uid' => (int) $user->getAuthIdentifier(),
            'sub' => (string) $user->getAuthIdentifier(),
            'username' => $this->extractUserString($user, 'username'),
            'name' => $this->extractPreferredDisplayName($user),
            'email' => $this->extractUserString($user, 'email'),
            'role' => $legacyRole,
            'source_role' => $roleValue,
            'is_active' => $this->extractUserBoolean($user, 'is_active', true) ? 1 : 0,
            'campus_id' => $this->extractUserInt($user, 'campus_id', 0),
            'is_superadmin' => $this->resolveBridgeIsSuperadmin($user, $legacyRole) ? 1 : 0,
            'force_password_change' => $this->extractUserBoolean($user, 'force_password_change', false) ? 1 : 0,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('Unable to encode Doc-Ease bridge token payload.');
        }

        $encodedPayload = $this->base64UrlEncode($json);
        $signature = hash_hmac('sha256', $encodedPayload, $secret, true);

        return $encodedPayload . '.' . $this->base64UrlEncode($signature);
    }

    private function resolveBridgeIsSuperadmin(Authenticatable $user, string $legacyRole): bool
    {
        if ($legacyRole !== 'admin') {
            return false;
        }

        $explicit = $this->readUserProperty($user, 'is_superadmin');
        if (is_bool($explicit)) {
            return $explicit;
        }
        if (is_int($explicit)) {
            return $explicit === 1;
        }
        if (is_string($explicit)) {
            return trim($explicit) === '1';
        }

        return (bool) config('doc_ease.bridge.admin_is_superadmin', true);
    }

    private function mapRoleToLegacy(string $roleValue): string
    {
        $roleValue = strtoupper(trim($roleValue));
        $map = config('doc_ease.bridge.role_map', []);
        if (is_array($map)) {
            $mapped = $map[$roleValue] ?? null;
            if (is_string($mapped)) {
                return $this->normalizeLegacyRoleName($mapped);
            }
        }

        return match ($roleValue) {
            'ADMIN' => 'admin',
            'OWNER', 'WORKER' => 'teacher',
            default => 'student',
        };
    }

    private function normalizeLegacyRoleName(string $role): string
    {
        $role = strtolower(trim($role));
        if ($role === 'user') {
            return 'student';
        }
        if (in_array($role, ['admin', 'teacher', 'student'], true)) {
            return $role;
        }

        return 'student';
    }

    private function normalizeLegacyPath(string $path, string $fallback): string
    {
        $path = trim($path);
        if ($path === '') {
            $path = $fallback;
        }

        $path = '/' . ltrim($path, '/');
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        if (str_contains($path, '..')) {
            return $fallback;
        }

        return $path;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return mixed
     */
    private function readUserProperty(Authenticatable $user, string $property)
    {
        if (property_exists($user, $property) || isset($user->{$property})) {
            return $user->{$property};
        }

        if (method_exists($user, 'getAttribute')) {
            return $user->getAttribute($property);
        }

        return null;
    }

    private function extractUserString(Authenticatable $user, string $property): string
    {
        $value = $this->readUserProperty($user, $property);
        if ($value instanceof BackedEnum) {
            return trim((string) $value->value);
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function extractUserInt(Authenticatable $user, string $property, int $default = 0): int
    {
        $value = $this->readUserProperty($user, $property);
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function extractUserBoolean(Authenticatable $user, string $property, bool $default = false): bool
    {
        $value = $this->readUserProperty($user, $property);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    private function extractPreferredDisplayName(Authenticatable $user): string
    {
        $fullName = $this->extractUserString($user, 'full_name');
        if ($fullName !== '') {
            return $fullName;
        }

        $name = $this->extractUserString($user, 'name');
        if ($name !== '') {
            return $name;
        }

        return $this->extractUserString($user, 'username');
    }

    private function extractUserRoleValue(Authenticatable $user): string
    {
        $role = $this->readUserProperty($user, 'role');

        if ($role instanceof BackedEnum) {
            return strtoupper((string) $role->value);
        }

        if (is_string($role)) {
            return strtoupper(trim($role));
        }

        if (is_object($role) && method_exists($role, 'value')) {
            return strtoupper(trim((string) $role->value));
        }

        return '';
    }
}
