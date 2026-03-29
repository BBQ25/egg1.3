<?php

namespace App\Support;

use App\Enums\UserRegistrationStatus;
use App\Enums\UserRole;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LoginClickBypass
{
    public const SETTING_ENABLED_KEY = 'login_click_bypass_enabled';

    public static function featureAllowed(): bool
    {
        return (bool) config('app.login_click_bypass.allowed', false);
    }

    public static function isEnabled(): bool
    {
        if (!self::featureAllowed()) {
            return false;
        }

        $value = AppSetting::query()
            ->where('setting_key', self::SETTING_ENABLED_KEY)
            ->value('setting_value');

        if ($value === null) {
            $default = (bool) config('app.login_click_bypass.enabled_default', false);
            self::setEnabled($default);
            return $default;
        }

        return self::boolFromValue($value, false);
    }

    public static function setEnabled(bool $enabled): void
    {
        if (!self::featureAllowed()) {
            $enabled = false;
        }

        AppSetting::query()->updateOrCreate(
            ['setting_key' => self::SETTING_ENABLED_KEY],
            ['setting_value' => $enabled ? '1' : '0']
        );
    }

    public static function ensureSeeded(): void
    {
        if (!self::featureAllowed()) {
            return;
        }

        if (!Schema::hasTable('login_click_bypass_rules')) {
            return;
        }

        self::isEnabled();

        $existing = DB::table('login_click_bypass_rules')->count();
        if ($existing > 0) {
            return;
        }

        $rules = config('app.login_click_bypass.default_rules', []);
        if (!is_array($rules) || $rules === []) {
            return;
        }

        foreach ($rules as $rule) {
            $rule = is_array($rule) ? $rule : [];
            $clickCount = (int) ($rule['click_count'] ?? 0);
            $windowSeconds = (int) ($rule['window_seconds'] ?? 0);
            $username = trim((string) ($rule['username'] ?? ''));

            if ($clickCount < 2 || $windowSeconds < 1 || $username === '') {
                continue;
            }

            $user = User::query()->where('username', $username)->first();
            if (!$user) {
                continue;
            }

            $label = trim((string) ($rule['label'] ?? ''));
            if ($label === '') {
                $label = sprintf('Seed: %d-click quick login', $clickCount);
            }

            DB::table('login_click_bypass_rules')->updateOrInsert(
                [
                    'click_count' => $clickCount,
                    'window_seconds' => $windowSeconds,
                ],
                [
                    'rule_label' => $label,
                    'target_user_id' => $user->id,
                    'is_enabled' => true,
                    'created_by_user_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * @return array<int, array{click_count:int,window_seconds:int}>
     */
    public static function fetchPublicRules(): array
    {
        if (!self::featureAllowed() || !self::isEnabled()) {
            return [];
        }

        if (!Schema::hasTable('login_click_bypass_rules')) {
            return [];
        }

        $rows = DB::table('login_click_bypass_rules as rules')
            ->join('users as users', 'users.id', '=', 'rules.target_user_id')
            ->where('rules.is_enabled', true)
            ->where(function ($query) {
                $query->where('users.role', UserRole::ADMIN->value)
                    ->orWhere(function ($sub) {
                        $sub->where('users.is_active', true)
                            ->where('users.registration_status', UserRegistrationStatus::APPROVED->value);
                    });
            })
            ->orderByDesc('rules.click_count')
            ->orderBy('rules.window_seconds')
            ->get(['rules.click_count', 'rules.window_seconds']);

        $out = [];
        foreach ($rows as $row) {
            $clickCount = (int) ($row->click_count ?? 0);
            $windowSeconds = (int) ($row->window_seconds ?? 0);
            if ($clickCount < 2 || $windowSeconds < 1) {
                continue;
            }
            $out[] = [
                'click_count' => $clickCount,
                'window_seconds' => $windowSeconds,
            ];
        }

        return $out;
    }

    /**
     * @return array{rule:object,user:User}|null
     */
    public static function matchRule(int $clickCount, int $durationMs): ?array
    {
        if (!self::featureAllowed() || !self::isEnabled()) {
            return null;
        }

        if (!Schema::hasTable('login_click_bypass_rules')) {
            return null;
        }

        $clickCount = max(2, min(20, $clickCount));
        $durationMs = max(0, min(30000, $durationMs));

        $row = DB::table('login_click_bypass_rules as rules')
            ->join('users as users', 'users.id', '=', 'rules.target_user_id')
            ->where('rules.is_enabled', true)
            ->where('rules.click_count', $clickCount)
            ->whereRaw('? <= (rules.window_seconds * 1000)', [$durationMs])
            ->where(function ($query) {
                $query->where('users.role', UserRole::ADMIN->value)
                    ->orWhere(function ($sub) {
                        $sub->where('users.is_active', true)
                            ->where('users.registration_status', UserRegistrationStatus::APPROVED->value);
                    });
            })
            ->orderBy('rules.window_seconds')
            ->orderBy('rules.id')
            ->select([
                'rules.id as rule_id',
                'rules.rule_label',
                'rules.click_count',
                'rules.window_seconds',
                'rules.target_user_id',
                'users.id as user_id',
            ])
            ->first();

        if (!$row) {
            return null;
        }

        $user = User::query()->find((int) $row->user_id);
        if (!$user) {
            return null;
        }

        return [
            'rule' => $row,
            'user' => $user,
        ];
    }

    private static function boolFromValue(mixed $value, bool $fallback): bool
    {
        $text = strtolower(trim((string) $value));
        if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($text, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $fallback;
    }
}
