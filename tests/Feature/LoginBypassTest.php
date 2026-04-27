<?php

namespace Tests\Feature;

use App\Enums\UserRegistrationStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\LoginClickBypass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoginBypassTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_rules_hide_inactive_or_unapproved_accounts(): void
    {
        config()->set('app.login_click_bypass.allowed', true);

        $activeOwner = User::factory()->create([
            'role' => UserRole::OWNER,
            'registration_status' => UserRegistrationStatus::APPROVED,
            'is_active' => true,
        ]);

        $inactiveAdmin = User::factory()->admin()->create([
            'is_active' => false,
            'deactivated_at' => now(),
        ]);

        DB::table('login_click_bypass_rules')->insert([
            [
                'rule_label' => 'Active owner',
                'click_count' => 4,
                'window_seconds' => 3,
                'target_user_id' => $activeOwner->id,
                'is_enabled' => true,
                'created_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'rule_label' => 'Inactive admin',
                'click_count' => 9,
                'window_seconds' => 3,
                'target_user_id' => $inactiveAdmin->id,
                'is_enabled' => true,
                'created_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        LoginClickBypass::setEnabled(true);

        $this->assertSame([
            [
                'click_count' => 4,
                'window_seconds' => 3,
            ],
        ], LoginClickBypass::fetchPublicRules());
    }

    public function test_match_rule_rejects_inactive_accounts(): void
    {
        config()->set('app.login_click_bypass.allowed', true);

        $inactiveAdmin = User::factory()->admin()->create([
            'is_active' => false,
            'deactivated_at' => now(),
        ]);

        DB::table('login_click_bypass_rules')->insert([
            'rule_label' => 'Inactive admin',
            'click_count' => 3,
            'window_seconds' => 3,
            'target_user_id' => $inactiveAdmin->id,
            'is_enabled' => true,
            'created_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        LoginClickBypass::setEnabled(true);

        $this->assertNull(LoginClickBypass::matchRule(3, 1200));
    }
}
