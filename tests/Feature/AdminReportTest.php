<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\CesGradeSheetPlaywrightService;
use App\Services\HrmisEasyLoginPlaywrightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_grade_sheet_report_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.forms.gradesheet'));

        $response->assertOk();
        $response->assertSee('Grade Sheet PDF Generator');
        $response->assertSee('id="gradesheet"', false);
        $response->assertSee(route('forms.gradesheet.download'), false);
    }

    public function test_admin_can_access_easy_login_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.forms.easy-login'));

        $response->assertOk();
        $response->assertSee('Easy Login - HRMIS My DTR');
        $response->assertSee(route('forms.easy-login.hrmis.time-in'), false);
    }

    public function test_admin_can_download_tcpdf_grade_sheet(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('forms.gradesheet.download'), [
            'id' => encrypt('gradesheet-demo-1'),
            'sy' => encrypt('2025-2026'),
            'sem' => encrypt('First Semester'),
            'filename' => 'IT-205L-IF-2-B-6.pdf',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'attachment; filename="IT-205L-IF-2-B-6.pdf"');

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('%PDF', $content);
        $this->assertStringContainsString('TCPDF', $content);
    }

    public function test_admin_can_download_ces_grade_sheet_via_server_playwright_service(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(CesGradeSheetPlaywrightService::class, function ($mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->withArgs(function (array $payload): bool {
                    return ($payload['campus'] ?? null) === 'Bontoc';
                })
                ->andReturn([
                    'binary' => '%PDF-1.4 mock-ces-pdf',
                    'filename' => 'IF-2-A-6-2025-2026.pdf',
                    'meta' => ['ok' => true],
                ]);
        });

        $response = $this->actingAs($admin)->post(route('forms.gradesheet.ces.download'), [
            'ces_base_url' => 'https://ces.southernleytestateu.edu.ph',
            'ces_username' => 'teacher@example.com',
            'ces_password' => 'secret-password',
            'school_year' => '2025-2026',
            'semester' => '1st Semester',
            'section_code' => 'IF-2-A-6',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Disposition', 'attachment; filename="IF-2-A-6-2025-2026.pdf"');
        $response->assertSee('%PDF-1.4 mock-ces-pdf', false);
    }

    public function test_admin_can_test_ces_connection_via_server_playwright_service(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(CesGradeSheetPlaywrightService::class, function ($mock): void {
            $mock->shouldReceive('testConnection')
                ->once()
                ->withArgs(function (array $payload): bool {
                    return ($payload['campus'] ?? null) === 'Main';
                })
                ->andReturn([
                    'ok' => true,
                    'meta' => [
                        'authenticated' => true,
                        'currentUrl' => 'https://ces.southernleytestateu.edu.ph/teacher/encode-grades',
                    ],
                ]);
        });

        $response = $this->actingAs($admin)->post(route('forms.gradesheet.ces.test'), [
            'ces_base_url' => 'https://ces.southernleytestateu.edu.ph',
            'campus' => 'Main',
            'ces_username' => 'teacher@example.com',
            'ces_password' => 'secret-password',
            'school_year' => '2025-2026',
            'semester' => '1st Semester',
            'section_code' => 'IF-2-A-6',
            'headless' => false,
            'slow_mo_ms' => 350,
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'message' => 'CES login/session verified.',
        ]);
        $response->assertJsonPath('meta.authenticated', true);
    }

    public function test_ces_connection_returns_failed_ok_flag_when_service_reports_failure(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(CesGradeSheetPlaywrightService::class, function ($mock): void {
            $mock->shouldReceive('testConnection')
                ->once()
                ->andReturn([
                    'ok' => false,
                    'meta' => ['authenticated' => false],
                ]);
        });

        $response = $this->actingAs($admin)->post(route('forms.gradesheet.ces.test'), [
            'ces_base_url' => 'https://ces.southernleytestateu.edu.ph',
            'campus' => 'Main',
            'ces_username' => 'teacher@example.com',
            'ces_password' => 'secret-password',
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok' => false,
            'message' => 'CES login/session verification failed.',
        ]);
        $response->assertJsonPath('meta.authenticated', false);
    }

    public function test_admin_can_run_hrmis_easy_login_time_in_via_server_playwright_service(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(HrmisEasyLoginPlaywrightService::class, function ($mock): void {
            $mock->shouldReceive('timeIn')
                ->once()
                ->withArgs(function (array $payload): bool {
                    return ($payload['base_url'] ?? null) === 'https://hrmis.southernleytestateu.edu.ph'
                        && ($payload['email'] ?? null) === 'jsumacot@southernleytestateu.edu.ph';
                })
                ->andReturn([
                    'ok' => true,
                    'message' => 'Time In saved successfully.',
                    'meta' => [
                        'currentUrl' => 'https://hrmis.southernleytestateu.edu.ph/my-dtr',
                    ],
                ]);
        });

        $response = $this->actingAs($admin)->post(route('forms.easy-login.hrmis.time-in'), [
            'hrmis_base_url' => 'https://hrmis.southernleytestateu.edu.ph',
            'hrmis_dtr_url' => 'https://hrmis.southernleytestateu.edu.ph/my-dtr',
            'hrmis_email' => 'jsumacot@southernleytestateu.edu.ph',
            'headless' => false,
            'slow_mo_ms' => 350,
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'message' => 'Time In saved successfully.',
        ]);
        $response->assertJsonPath('meta.currentUrl', 'https://hrmis.southernleytestateu.edu.ph/my-dtr');
    }

    public function test_ces_report_endpoints_reject_disallowed_base_url_host(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(CesGradeSheetPlaywrightService::class, function ($mock): void {
            $mock->shouldNotReceive('testConnection');
        });

        $response = $this
            ->actingAs($admin)
            ->from(route('admin.forms.gradesheet'))
            ->post(route('forms.gradesheet.ces.test'), [
                'ces_base_url' => 'https://example.com',
                'ces_username' => 'teacher@example.com',
                'ces_password' => 'secret-password',
            ]);

        $response->assertRedirect(route('admin.forms.gradesheet'));
        $response->assertSessionHasErrors('ces_base_url');
    }

    public function test_hrmis_easy_login_rejects_disallowed_base_url_host(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(HrmisEasyLoginPlaywrightService::class, function ($mock): void {
            $mock->shouldNotReceive('timeIn');
        });

        $response = $this
            ->actingAs($admin)
            ->from(route('admin.forms.easy-login'))
            ->post(route('forms.easy-login.hrmis.time-in'), [
                'hrmis_base_url' => 'https://example.com',
                'hrmis_dtr_url' => 'https://example.com/my-dtr',
                'hrmis_email' => 'admin@example.com',
            ]);

        $response->assertRedirect(route('admin.forms.easy-login'));
        $response->assertSessionHasErrors('hrmis_base_url');
    }

    public function test_non_admin_cannot_access_grade_sheet_report_endpoints(): void
    {
        $customer = User::factory()->create(['role' => UserRole::CUSTOMER]);

        $pageResponse = $this->actingAs($customer)->get(route('admin.forms.gradesheet'));
        $pageResponse->assertForbidden();

        $easyLoginPageResponse = $this->actingAs($customer)->get(route('admin.forms.easy-login'));
        $easyLoginPageResponse->assertForbidden();

        $downloadResponse = $this->actingAs($customer)->post(route('forms.gradesheet.download'), [
            'id' => encrypt('gradesheet-demo-1'),
            'sy' => encrypt('2025-2026'),
            'sem' => encrypt('First Semester'),
        ]);
        $downloadResponse->assertForbidden();

        $cesDownloadResponse = $this->actingAs($customer)->post(route('forms.gradesheet.ces.download'), [
            'ces_base_url' => 'https://ces.southernleytestateu.edu.ph',
            'ces_username' => 'teacher@example.com',
            'ces_password' => 'secret-password',
            'school_year' => '2025-2026',
            'semester' => '1st Semester',
            'section_code' => 'IF-2-A-6',
        ]);
        $cesDownloadResponse->assertForbidden();

        $cesConnectionTestResponse = $this->actingAs($customer)->post(route('forms.gradesheet.ces.test'), [
            'ces_base_url' => 'https://ces.southernleytestateu.edu.ph',
            'ces_username' => 'teacher@example.com',
            'ces_password' => 'secret-password',
        ]);
        $cesConnectionTestResponse->assertForbidden();

        $easyLoginResponse = $this->actingAs($customer)->post(route('forms.easy-login.hrmis.time-in'), [
            'hrmis_base_url' => 'https://hrmis.southernleytestateu.edu.ph',
            'hrmis_dtr_url' => 'https://hrmis.southernleytestateu.edu.ph/my-dtr',
            'hrmis_email' => 'jsumacot@southernleytestateu.edu.ph',
        ]);
        $easyLoginResponse->assertForbidden();
    }

    public function test_legacy_grade_sheet_page_url_redirects_to_new_forms_url(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/reports/gradesheet');

        $response->assertStatus(301);
        $response->assertRedirect('/admin/forms/gradesheet');
    }

    public function test_legacy_grade_sheet_download_url_redirects_with_post_method_preserved(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/report/gradesheet', [
            'id' => encrypt('gradesheet-demo-1'),
            'sy' => encrypt('2025-2026'),
            'sem' => encrypt('First Semester'),
        ]);

        $response->assertStatus(308);
        $response->assertRedirect('/forms/gradesheet');
    }

    public function test_legacy_ces_grade_sheet_download_url_redirects_with_post_method_preserved(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/report/gradesheet/ces', [
            'school_year' => '2025-2026',
            'semester' => '1st Semester',
            'section_code' => 'IF-2-A-6',
        ]);

        $response->assertStatus(308);
        $response->assertRedirect('/forms/gradesheet/ces');
    }

    public function test_legacy_ces_connection_test_url_redirects_with_post_method_preserved(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/report/gradesheet/ces/test-connection', [
            'school_year' => '2025-2026',
            'semester' => '1st Semester',
            'section_code' => 'IF-2-A-6',
        ]);

        $response->assertStatus(308);
        $response->assertRedirect('/forms/gradesheet/ces/test-connection');
    }
}
