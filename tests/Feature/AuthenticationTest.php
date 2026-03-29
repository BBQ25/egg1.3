<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
    }

    public function test_user_can_authenticate_with_valid_credentials(): void
    {
        User::factory()->create([
            'username' => 'demo_user',
            'password_hash' => 'password123',
            'role' => UserRole::CUSTOMER,
        ]);

        $response = $this->post('/login', [
            'username' => 'demo_user',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_user_can_authenticate_with_remember_me_enabled(): void
    {
        User::factory()->create([
            'username' => 'remember_user',
            'password_hash' => 'password123',
            'role' => UserRole::CUSTOMER,
        ]);

        $response = $this->post('/login', [
            'username' => 'remember_user',
            'password' => 'password123',
            'remember' => '1',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_user_cannot_authenticate_with_invalid_password(): void
    {
        User::factory()->create([
            'username' => 'demo_user',
            'password_hash' => 'password123',
        ]);

        $response = $this->from('/login')->post('/login', [
            'username' => 'demo_user',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_authenticate(): void
    {
        User::factory()->create([
            'username' => 'inactive_user',
            'password_hash' => 'password123',
            'is_active' => false,
            'deactivated_at' => now(),
        ]);

        $response = $this->from('/login')->post('/login', [
            'username' => 'inactive_user',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_forced_rehash_updates_password_hash_column(): void
    {
        $user = User::factory()->create([
            'password_hash' => 'old-password-123',
            'is_active' => true,
        ]);

        $oldHash = $user->password_hash;
        $provider = app('auth')->createUserProvider('users');

        $provider->rehashPasswordIfRequired($user, ['password' => 'new-password-456'], force: true);

        $user->refresh();
        $this->assertNotSame($oldHash, $user->password_hash);
        $this->assertTrue(Hash::check('new-password-456', $user->password_hash));
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        User::factory()->create([
            'username' => 'rate_limited_user',
            'password_hash' => 'correct-password',
            'role' => UserRole::CUSTOMER,
        ]);

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $response = $this->from('/login')->post('/login', [
                'username' => 'rate_limited_user',
                'password' => 'wrong-password',
            ]);

            $response->assertRedirect('/login');
            $response->assertSessionHasErrors('username');
        }

        $lockedResponse = $this->from('/login')->post('/login', [
            'username' => 'rate_limited_user',
            'password' => 'correct-password',
        ]);

        $lockedResponse->assertRedirect('/login');
        $lockedResponse->assertSessionHasErrors('username');
        $this->assertGuest();
    }
}
