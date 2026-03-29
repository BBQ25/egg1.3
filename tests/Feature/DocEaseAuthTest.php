<?php

namespace Tests\Feature;

use App\Models\DocEaseUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DocEaseAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.doc_ease', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('doc_ease');
        DB::connection('doc_ease')->getPdo();

        Schema::connection('doc_ease')->create('users', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username', 120)->nullable();
            $table->string('useremail', 190)->nullable();
            $table->string('password');
            $table->string('role', 40)->default('student');
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->unsignedTinyInteger('is_superadmin')->default(0);
        });
    }

    public function test_doc_ease_login_page_is_accessible_when_enabled(): void
    {
        config()->set('doc_ease.enabled', true);

        $response = $this->get(route('doc-ease.login'));

        $response->assertOk();
        $response->assertSee('Doc-Ease (Laravel)');
    }

    public function test_doc_ease_user_can_login_with_email_and_access_portal(): void
    {
        config()->set('doc_ease.enabled', true);

        $this->makeDocEaseUser(
            username: 'teacher-one',
            useremail: 'teacher1@example.com',
            password: 'secret123',
            role: 'teacher',
            isActive: true,
        );

        $response = $this->post(route('doc-ease.login.store'), [
            'login' => 'teacher1@example.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('doc-ease.portal'));
        $this->assertAuthenticated('doc_ease');

        $portal = $this->get(route('doc-ease.portal'));
        $portal->assertOk();
        $portal->assertSee('Doc-Ease User Portal (Laravel Auth)');
    }

    public function test_doc_ease_student_can_login_with_username(): void
    {
        config()->set('doc_ease.enabled', true);

        $this->makeDocEaseUser(
            username: '2410001-1',
            useremail: 'student1@example.com',
            password: 'secret123',
            role: 'student',
            isActive: true,
        );

        $response = $this->post(route('doc-ease.login.store'), [
            'login' => '2410001-1',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('doc-ease.portal'));
        $this->assertAuthenticated('doc_ease');
    }

    public function test_doc_ease_inactive_non_admin_user_is_blocked(): void
    {
        config()->set('doc_ease.enabled', true);

        $this->makeDocEaseUser(
            username: 'teacher-two',
            useremail: 'teacher2@example.com',
            password: 'secret123',
            role: 'teacher',
            isActive: false,
        );

        $response = $this->from(route('doc-ease.login'))->post(route('doc-ease.login.store'), [
            'login' => 'teacher2@example.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('doc-ease.login'));
        $response->assertSessionHasErrors(['login']);
        $this->assertGuest('doc_ease');
    }

    public function test_doc_ease_portal_launch_legacy_redirects_to_entrypoint_when_bridge_disabled(): void
    {
        config()->set('doc_ease.enabled', true);
        config()->set('doc_ease.bridge.enabled', false);
        config()->set('doc_ease.entrypoint', '/doc-ease/index.php');

        $user = $this->makeDocEaseUser(
            username: 'admin-one',
            useremail: 'admin1@example.com',
            password: 'secret123',
            role: 'admin',
            isActive: true,
        );

        $response = $this->actingAs($user, 'doc_ease')->post(route('doc-ease.portal.launch-legacy'));

        $response->assertRedirect('/doc-ease/index.php');
    }

    public function test_doc_ease_logout_clears_guard_session(): void
    {
        config()->set('doc_ease.enabled', true);

        $user = $this->makeDocEaseUser(
            username: 'teacher-three',
            useremail: 'teacher3@example.com',
            password: 'secret123',
            role: 'teacher',
            isActive: true,
        );

        $response = $this->actingAs($user, 'doc_ease')->post(route('doc-ease.logout'));

        $response->assertRedirect(route('doc-ease.login'));
        $this->assertGuest('doc_ease');
    }

    private function makeDocEaseUser(
        string $username,
        string $useremail,
        string $password,
        string $role,
        bool $isActive
    ): DocEaseUser {
        $id = DB::connection('doc_ease')->table('users')->insertGetId([
            'username' => $username,
            'useremail' => $useremail,
            'password' => Hash::make($password),
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
            'campus_id' => 1,
            'is_superadmin' => 0,
        ]);

        return DocEaseUser::on('doc_ease')->findOrFail($id);
    }
}

