<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CesGradeSheetPlaywrightService;
use App\Services\HrmisEasyLoginPlaywrightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ReportController extends Controller
{
    public function gradeSheet(): View
    {
        $schoolYear = now()->year . '-' . now()->addYear()->year;
        $semester = 'First Semester';
        $subjectCode = 'IT 205L';
        $subjectTitle = 'Object Oriented Programming';
        $scheduleLabel = 'CLab-3 MTh 11:00 AM - 12:30 PM';
        $sectionLabel = 'IF-2-B-6';
        $filename = 'IT-205L-IF-2-B-6.pdf';

        return view('admin.forms.gradesheet', [
            'encryptedScheduleId' => encrypt('gradesheet-demo-1'),
            'encryptedSchoolYear' => encrypt($schoolYear),
            'encryptedSemester' => encrypt($semester),
            'filename' => $filename,
            'courseCode' => $subjectCode,
            'courseTitle' => $subjectTitle,
            'scheduleLabel' => $scheduleLabel,
            'sectionLabel' => $sectionLabel,
            'schoolYear' => $schoolYear,
            'semester' => $semester,
        ]);
    }

    public function easyLogin(): View
    {
        $baseUrl = rtrim((string) config('services.hrmis.base_url', 'https://hrmis.southernleytestateu.edu.ph'), '/');
        $myDtrPath = (string) config('services.hrmis.my_dtr_path', '/my-dtr');
        $myDtrPath = '/' . ltrim($myDtrPath, '/');

        return view('admin.forms.easy-login', [
            'hrmisDtrUrl' => $baseUrl . $myDtrPath,
            'hrmisSlowMoMs' => (int) config('services.hrmis.playwright_slow_mo_ms', 350),
            'hrmisEmail' => (string) config('services.hrmis.email', 'jsumacot@southernleytestateu.edu.ph'),
        ]);
    }

    public function downloadGradeSheet(Request $request): Response
    {
        $validated = $request->validate([
            'id' => ['required', 'string', 'max:2048'],
            'sy' => ['required', 'string', 'max:2048'],
            'sem' => ['required', 'string', 'max:2048'],
            'filename' => ['nullable', 'string', 'max:255'],
            'course_code' => ['nullable', 'string', 'max:120'],
            'course_title' => ['nullable', 'string', 'max:255'],
            'schedule' => ['nullable', 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:120'],
        ]);

        $scheduleId = $this->decodePayload($validated['id']);
        $schoolYear = $this->decodePayload($validated['sy']);
        $semester = $this->decodePayload($validated['sem']);

        $context = [
            'scheduleId' => $scheduleId,
            'schoolYear' => $schoolYear,
            'semester' => $semester,
            'courseCode' => $validated['course_code'] ?? 'IT 205L',
            'courseTitle' => $validated['course_title'] ?? 'Object Oriented Programming',
            'scheduleLabel' => $validated['schedule'] ?? 'CLab-3 MTh 11:00 AM - 12:30 PM',
            'sectionLabel' => $validated['section'] ?? 'IF-2-B-6',
        ];

        $rows = $this->gradeRows();
        $summary = $this->summary($rows);

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('APEWSD');
        $pdf->SetAuthor((string) ($request->user()?->full_name ?? 'APEWSD System'));
        $pdf->SetTitle('Grade Sheet');
        $pdf->SetSubject('Grade Sheet');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8, 8, 8);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AddPage();

        $html = view('admin.forms.partials.grade-sheet-pdf', [
            'context' => $context,
            'rows' => $rows,
            'summary' => $summary,
            'generatedAt' => now(),
        ])->render();

        $pdf->writeHTML($html, true, false, true, false, '');
        $binary = $pdf->Output('grade-sheet.pdf', 'S');

        $filename = $this->safeFilename($validated['filename'] ?? '');

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function runHrmisEasyLoginTimeIn(
        Request $request,
        HrmisEasyLoginPlaywrightService $playwrightService
    ): JsonResponse {
        $validated = $request->validate([
            'hrmis_base_url' => ['nullable', 'url', 'max:255'],
            'hrmis_dtr_url' => ['nullable', 'url', 'max:255'],
            'hrmis_email' => ['nullable', 'email', 'max:255'],
            'hrmis_username' => ['nullable', 'string', 'max:255'],
            'hrmis_password' => ['nullable', 'string', 'max:255'],
            'headless' => ['nullable', 'boolean'],
            'slow_mo_ms' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'timeout_seconds' => ['nullable', 'integer', 'min:60', 'max:600'],
        ]);

        $baseUrl = rtrim((string) ($validated['hrmis_base_url'] ?? config('services.hrmis.base_url')), '/');
        $dtrUrl = trim((string) ($validated['hrmis_dtr_url'] ?? ''));
        $email = trim((string) ($validated['hrmis_email'] ?? config('services.hrmis.email')));
        $username = trim((string) ($validated['hrmis_username'] ?? config('services.hrmis.username')));
        $password = (string) ($validated['hrmis_password'] ?? config('services.hrmis.password'));

        if ($baseUrl === '' && $dtrUrl !== '') {
            $parts = parse_url($dtrUrl) ?: [];
            $scheme = (string) ($parts['scheme'] ?? '');
            $host = (string) ($parts['host'] ?? '');
            $port = (string) ($parts['port'] ?? '');

            if ($scheme !== '' && $host !== '') {
                $baseUrl = $scheme . '://' . $host . ($port !== '' ? ':' . $port : '');
            }
        }

        if ($baseUrl === '') {
            throw ValidationException::withMessages([
                'hrmis_base_url' => 'HRMIS base URL is required.',
            ]);
        }

        $hrmisAllowedHosts = (array) config('services.hrmis.allowed_hosts', []);
        $this->assertAutomationUrlAllowed($baseUrl, 'hrmis_base_url', $hrmisAllowedHosts);
        if ($dtrUrl !== '') {
            $this->assertAutomationUrlAllowed($dtrUrl, 'hrmis_dtr_url', $hrmisAllowedHosts);
        }

        try {
            $result = $playwrightService->timeIn([
                'base_url' => $baseUrl,
                'dtr_url' => $dtrUrl,
                'email' => $email,
                'username' => $username,
                'password' => $password,
                'headless' => (bool) ($validated['headless'] ?? config('services.hrmis.playwright_headless', true)),
                'slow_mo_ms' => (int) ($validated['slow_mo_ms'] ?? config('services.hrmis.playwright_slow_mo_ms', 0)),
                'timeout_seconds' => (int) ($validated['timeout_seconds'] ?? config('services.hrmis.playwright_timeout', 240)),
            ]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'easy_login' => 'HRMIS Easy Login failed: ' . $e->getMessage(),
            ]);
        }

        $message = trim((string) ($result['message'] ?? 'HRMIS Time In request completed.'));
        if ($message === '') {
            $message = 'HRMIS Time In request completed.';
        }

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => $message,
            'meta' => $result['meta'] ?? [],
        ]);
    }

    public function downloadCesGradeSheet(
        Request $request,
        CesGradeSheetPlaywrightService $playwrightService
    ): Response {
        $validated = $request->validate([
            'ces_base_url' => ['nullable', 'url', 'max:255'],
            'campus' => ['nullable', 'string', 'max:120'],
            'ces_username' => ['nullable', 'string', 'max:255'],
            'ces_password' => ['nullable', 'string', 'max:255'],
            'school_year' => ['nullable', 'string', 'max:64'],
            'semester' => ['nullable', 'string', 'max:64'],
            'section_code' => ['nullable', 'string', 'max:64'],
            'filename' => ['nullable', 'string', 'max:255'],
            'headless' => ['nullable', 'boolean'],
            'slow_mo_ms' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'timeout_seconds' => ['nullable', 'integer', 'min:60', 'max:600'],
        ]);

        $baseUrl = rtrim((string) ($validated['ces_base_url'] ?? config('services.ces.base_url')), '/');
        $campus = trim((string) ($validated['campus'] ?? config('services.ces.campus', 'Bontoc')));
        $username = trim((string) ($validated['ces_username'] ?? config('services.ces.username')));
        $password = (string) ($validated['ces_password'] ?? config('services.ces.password'));
        $schoolYear = (string) ($validated['school_year'] ?? '2025-2026');
        $semester = (string) ($validated['semester'] ?? '1st Semester');
        $sectionCode = (string) ($validated['section_code'] ?? 'IF-2-A-6');

        if ($baseUrl === '') {
            throw ValidationException::withMessages([
                'ces_base_url' => 'CES base URL is required.',
            ]);
        }

        $this->assertAutomationUrlAllowed(
            $baseUrl,
            'ces_base_url',
            (array) config('services.ces.allowed_hosts', [])
        );

        if ($username === '') {
            throw ValidationException::withMessages([
                'ces_username' => 'CES username is required (or set CES_USERNAME in .env).',
            ]);
        }

        if ($password === '') {
            throw ValidationException::withMessages([
                'ces_password' => 'CES password is required (or set CES_PASSWORD in .env).',
            ]);
        }

        try {
            $generated = $playwrightService->generate([
                'base_url' => $baseUrl,
                'campus' => $campus === '' ? 'Bontoc' : $campus,
                'username' => $username,
                'password' => $password,
                'school_year' => $schoolYear,
                'semester' => $semester,
                'section_code' => $sectionCode,
                'headless' => (bool) ($validated['headless'] ?? config('services.ces.playwright_headless', true)),
                'slow_mo_ms' => (int) ($validated['slow_mo_ms'] ?? config('services.ces.playwright_slow_mo_ms', 0)),
                'timeout_seconds' => (int) ($validated['timeout_seconds'] ?? config('services.ces.playwright_timeout', 240)),
            ]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'ces_automation' => 'CES Playwright automation failed: ' . $e->getMessage(),
            ]);
        }

        $filename = $this->safeFilename(
            (string) ($validated['filename'] ?? ($generated['filename'] ?? "{$sectionCode}-{$schoolYear}.pdf"))
        );

        return response($generated['binary'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function testCesConnection(
        Request $request,
        CesGradeSheetPlaywrightService $playwrightService
    ): JsonResponse {
        $validated = $request->validate([
            'ces_base_url' => ['nullable', 'url', 'max:255'],
            'campus' => ['nullable', 'string', 'max:120'],
            'ces_username' => ['nullable', 'string', 'max:255'],
            'ces_password' => ['nullable', 'string', 'max:255'],
            'school_year' => ['nullable', 'string', 'max:64'],
            'semester' => ['nullable', 'string', 'max:64'],
            'section_code' => ['nullable', 'string', 'max:64'],
            'headless' => ['nullable', 'boolean'],
            'slow_mo_ms' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'timeout_seconds' => ['nullable', 'integer', 'min:60', 'max:600'],
        ]);

        $baseUrl = rtrim((string) ($validated['ces_base_url'] ?? config('services.ces.base_url')), '/');
        $campus = trim((string) ($validated['campus'] ?? config('services.ces.campus', 'Bontoc')));
        $username = trim((string) ($validated['ces_username'] ?? config('services.ces.username')));
        $password = (string) ($validated['ces_password'] ?? config('services.ces.password'));

        if ($baseUrl === '') {
            throw ValidationException::withMessages([
                'ces_base_url' => 'CES base URL is required.',
            ]);
        }

        $this->assertAutomationUrlAllowed(
            $baseUrl,
            'ces_base_url',
            (array) config('services.ces.allowed_hosts', [])
        );

        if ($username === '') {
            throw ValidationException::withMessages([
                'ces_username' => 'CES username is required (or set CES_USERNAME in .env).',
            ]);
        }

        if ($password === '') {
            throw ValidationException::withMessages([
                'ces_password' => 'CES password is required (or set CES_PASSWORD in .env).',
            ]);
        }

        try {
            $result = $playwrightService->testConnection([
                'base_url' => $baseUrl,
                'campus' => $campus === '' ? 'Bontoc' : $campus,
                'username' => $username,
                'password' => $password,
                'school_year' => (string) ($validated['school_year'] ?? '2025-2026'),
                'semester' => (string) ($validated['semester'] ?? '1st Semester'),
                'section_code' => (string) ($validated['section_code'] ?? 'IF-2-A-6'),
                'headless' => (bool) ($validated['headless'] ?? config('services.ces.playwright_headless', true)),
                'slow_mo_ms' => (int) ($validated['slow_mo_ms'] ?? config('services.ces.playwright_slow_mo_ms', 0)),
                'timeout_seconds' => (int) ($validated['timeout_seconds'] ?? config('services.ces.playwright_timeout', 240)),
            ]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'ces_connection' => 'CES connection test failed: ' . $e->getMessage(),
            ]);
        }

        $ok = (bool) ($result['ok'] ?? false);

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? 'CES login/session verified.'
                : 'CES login/session verification failed.',
            'meta' => $result['meta'] ?? [],
        ]);
    }

    /**
     * @param array<int, string> $allowedHosts
     *
     * @throws ValidationException
     */
    private function assertAutomationUrlAllowed(string $url, string $field, array $allowedHosts): void
    {
        $normalizedAllowedHosts = $this->normalizeAllowedHosts($allowedHosts);

        if ($normalizedAllowedHosts === []) {
            throw ValidationException::withMessages([
                $field => 'Automation host allowlist is not configured.',
            ]);
        }

        $parsed = parse_url($url) ?: [];
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parsed['host'] ?? '')));

        if ($scheme === '' || $host === '') {
            throw ValidationException::withMessages([
                $field => 'A valid automation URL is required.',
            ]);
        }

        if ($scheme !== 'https') {
            throw ValidationException::withMessages([
                $field => 'Only HTTPS automation URLs are allowed.',
            ]);
        }

        if ($this->isLocalOrInternalHost($host) || $this->isNonPublicIpLiteral($host)) {
            throw ValidationException::withMessages([
                $field => 'Local, private, and internal network targets are not allowed.',
            ]);
        }

        if (!$this->hostMatchesAllowlist($host, $normalizedAllowedHosts)) {
            throw ValidationException::withMessages([
                $field => 'Automation target host is not in the allowlist.',
            ]);
        }
    }

    /**
     * @param array<int, string> $allowedHosts
     * @return array<int, string>
     */
    private function normalizeAllowedHosts(array $allowedHosts): array
    {
        $normalized = [];
        foreach ($allowedHosts as $host) {
            $clean = strtolower(trim((string) $host));
            $clean = trim($clean, '.');
            if ($clean === '') {
                continue;
            }
            $normalized[$clean] = $clean;
        }

        return array_values($normalized);
    }

    /**
     * @param array<int, string> $allowlist
     */
    private function hostMatchesAllowlist(string $host, array $allowlist): bool
    {
        foreach ($allowlist as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function isLocalOrInternalHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        return false;
    }

    private function isNonPublicIpLiteral(string $host): bool
    {
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private function decodePayload(string $value): string
    {
        try {
            $decoded = decrypt($value);
            if (is_string($decoded)) {
                return $decoded;
            }

            if (is_scalar($decoded)) {
                return (string) $decoded;
            }
        } catch (Throwable) {
            // Keep raw value when payload is not encrypted.
        }

        return $value;
    }

    private function safeFilename(string $filename): string
    {
        $clean = trim($filename);
        if ($clean === '') {
            return 'grade-sheet.pdf';
        }

        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $clean) ?? 'grade-sheet.pdf';
        $clean = trim($clean, '.-_');
        if ($clean === '') {
            $clean = 'grade-sheet';
        }

        if (!Str::endsWith(Str::lower($clean), '.pdf')) {
            $clean .= '.pdf';
        }

        return $clean;
    }

    /**
     * @return array<int, array{no:int,student_no:string,name:string,mt:string,ft:string,avg:string,inc:string}>
     */
    private function gradeRows(): array
    {
        $users = User::query()
            ->select(['id', 'username', 'full_name'])
            ->orderBy('full_name')
            ->limit(30)
            ->get();

        if ($users->isEmpty()) {
            return [
                ['no' => 1, 'student_no' => '2410220-2', 'name' => 'Albesa, Ma. Patricia', 'mt' => '1.4', 'ft' => '2.0', 'avg' => '1.7', 'inc' => ''],
                ['no' => 2, 'student_no' => '2410113-1', 'name' => 'Araniel, Allen Justine', 'mt' => '1.4', 'ft' => '1.8', 'avg' => '1.6', 'inc' => ''],
                ['no' => 3, 'student_no' => '2410233-1', 'name' => 'Basas, Niel John', 'mt' => '1.4', 'ft' => '1.8', 'avg' => '1.6', 'inc' => ''],
            ];
        }

        $rows = [];
        foreach ($users as $index => $user) {
            $seed = abs(crc32((string) ($user->username . '|' . $user->id)));
            $mtValue = 1 + (($seed % 12) / 10); // 1.0 - 2.1
            $ftValue = 1 + ((intdiv($seed, 13) % 12) / 10); // 1.0 - 2.1
            $avgValue = round(($mtValue + $ftValue) / 2, 1);
            $hasInc = $avgValue > 2.0;

            $rows[] = [
                'no' => $index + 1,
                'student_no' => sprintf('%07d-1', $user->id),
                'name' => $user->full_name,
                'mt' => number_format($mtValue, 1, '.', ''),
                'ft' => number_format($ftValue, 1, '.', ''),
                'avg' => number_format($avgValue, 1, '.', ''),
                'inc' => $hasInc ? 'INC' : '',
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array{no:int,student_no:string,name:string,mt:string,ft:string,avg:string,inc:string}> $rows
     * @return array{total:int,with_grades:int,without_grades:int,passed:int,failed:int,inc:int}
     */
    private function summary(array $rows): array
    {
        $withGrades = 0;
        $passed = 0;
        $failed = 0;
        $inc = 0;

        foreach ($rows as $row) {
            if ($row['avg'] !== '') {
                $withGrades++;
            }

            if ($row['inc'] !== '') {
                $inc++;
                continue;
            }

            $avg = (float) $row['avg'];
            if ($avg > 0 && $avg <= 3.0) {
                $passed++;
            } elseif ($avg > 3.0) {
                $failed++;
            }
        }

        $total = count($rows);

        return [
            'total' => $total,
            'with_grades' => $withGrades,
            'without_grades' => max(0, $total - $withGrades),
            'passed' => $passed,
            'failed' => $failed,
            'inc' => $inc,
        ];
    }
}
