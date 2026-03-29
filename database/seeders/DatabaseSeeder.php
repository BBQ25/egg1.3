<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'full_name' => 'System Administrator',
            'username' => 'admin',
            'password_hash' => 'password',
            'role' => UserRole::ADMIN,
            'is_active' => true,
            'deactivated_at' => null,
        ]);
    }
}
