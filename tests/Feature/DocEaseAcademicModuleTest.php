<?php

namespace Tests\Feature;

use App\Models\DocEaseSubject;
use App\Models\DocEaseUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocEaseAcademicModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('doc_ease.enabled', true);

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

        Schema::connection('doc_ease')->create('subjects', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('subject_code', 20)->unique();
            $table->string('subject_name', 200);
            $table->text('description')->nullable();
            $table->string('course', 100)->nullable();
            $table->string('major', 100)->nullable();
            $table->string('academic_year', 50)->nullable();
            $table->string('semester', 50)->nullable();
            $table->decimal('units', 4, 1)->nullable();
            $table->string('type', 20)->default('Lecture');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('created_by')->default(1);
            $table->timestamps();
        });

        Schema::connection('doc_ease')->create('sections', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::connection('doc_ease')->create('class_records', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('subject_id');
            $table->unsignedInteger('teacher_id')->nullable();
            $table->string('record_type', 30)->default('assigned');
            $table->string('section', 50);
            $table->string('room_number', 50)->nullable();
            $table->string('schedule', 120)->nullable();
            $table->unsignedInteger('max_students')->nullable();
            $table->text('description')->nullable();
            $table->string('academic_year', 50);
            $table->string('semester', 50);
            $table->string('year_level', 20)->nullable();
            $table->unsignedInteger('created_by')->default(1);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::connection('doc_ease')->create('teacher_assignments', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('teacher_id');
            $table->string('teacher_role', 20)->default('primary');
            $table->unsignedInteger('class_record_id');
            $table->unsignedInteger('assigned_by')->default(1);
            $table->dateTime('assigned_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('assignment_notes')->nullable();
            $table->timestamps();
        });

        Schema::connection('doc_ease')->create('class_enrollments', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('class_record_id');
            $table->unsignedInteger('student_id');
            $table->date('enrollment_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->decimal('grade', 5, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
            $table->unsignedInteger('class_id')->nullable();
        });

        Schema::connection('doc_ease')->create('enrollments', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('student_no', 60)->nullable();
            $table->unsignedInteger('subject_id')->nullable();
            $table->string('academic_year', 50)->nullable();
            $table->string('semester', 50)->nullable();
            $table->string('section', 50)->nullable();
            $table->dateTime('enrollment_date')->nullable();
            $table->string('status', 20)->default('enrolled');
            $table->unsignedInteger('created_by')->nullable();
        });
    }

    public function test_doc_ease_admin_can_access_academic_pages(): void
    {
        $admin = $this->makeDocEaseUser(
            username: 'doc-admin',
            useremail: 'doc-admin@example.com',
            role: 'admin',
            isActive: true,
        );

        $subjectsResponse = $this->actingAs($admin, 'doc_ease')
            ->get(route('doc-ease.academic.subjects.index'));
        $subjectsResponse->assertOk();
        $subjectsResponse->assertSee('Doc-Ease Subjects (Laravel)');

        $assignmentsResponse = $this->actingAs($admin, 'doc_ease')
            ->get(route('doc-ease.academic.assignments.index'));
        $assignmentsResponse->assertOk();
        $assignmentsResponse->assertSee('Doc-Ease Teacher Assignments (Laravel)');
    }

    public function test_non_admin_doc_ease_user_is_forbidden_for_academic_module(): void
    {
        $teacher = $this->makeDocEaseUser(
            username: 'doc-teacher',
            useremail: 'doc-teacher@example.com',
            role: 'teacher',
            isActive: true,
        );

        $response = $this->actingAs($teacher, 'doc_ease')
            ->get(route('doc-ease.academic.subjects.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_create_update_toggle_and_delete_subject(): void
    {
        $admin = $this->makeDocEaseUser(
            username: 'subject-admin',
            useremail: 'subject-admin@example.com',
            role: 'admin',
            isActive: true,
        );

        $storeResponse = $this->actingAs($admin, 'doc_ease')
            ->post(route('doc-ease.academic.subjects.store'), [
                'subject_code' => 'MATH101',
                'subject_name' => 'College Algebra',
                'description' => 'Intro algebra for first year',
                'course' => 'BSIT',
                'major' => 'Software Development',
                'academic_year' => '2025-2026',
                'semester' => '1st',
                'units' => '3.0',
                'type' => 'Lecture',
                'status' => 'active',
            ]);

        $storeResponse->assertRedirect(route('doc-ease.academic.subjects.index'));
        $this->assertDatabaseHas('subjects', [
            'subject_code' => 'MATH101',
            'subject_name' => 'College Algebra',
            'status' => 'active',
        ], 'doc_ease');

        $subject = DocEaseSubject::on('doc_ease')->where('subject_code', 'MATH101')->firstOrFail();

        $updateResponse = $this->actingAs($admin, 'doc_ease')
            ->put(route('doc-ease.academic.subjects.update', $subject), [
                'subject_code' => 'MATH101',
                'subject_name' => 'Advanced College Algebra',
                'description' => 'Updated description',
                'course' => 'BSIT',
                'major' => 'Software Development',
                'academic_year' => '2025-2026',
                'semester' => '1st',
                'units' => '4.0',
                'type' => 'Lec&Lab',
                'status' => 'active',
            ]);

        $updateResponse->assertRedirect(route('doc-ease.academic.subjects.index'));
        $this->assertDatabaseHas('subjects', [
            'id' => $subject->id,
            'subject_name' => 'Advanced College Algebra',
            'type' => 'Lec&Lab',
        ], 'doc_ease');

        $statusResponse = $this->actingAs($admin, 'doc_ease')
            ->patch(route('doc-ease.academic.subjects.status', $subject), [
                'status' => 'inactive',
            ]);

        $statusResponse->assertRedirect(route('doc-ease.academic.subjects.index'));
        $this->assertDatabaseHas('subjects', [
            'id' => $subject->id,
            'status' => 'inactive',
        ], 'doc_ease');

        $deleteResponse = $this->actingAs($admin, 'doc_ease')
            ->delete(route('doc-ease.academic.subjects.destroy', $subject));

        $deleteResponse->assertRedirect(route('doc-ease.academic.subjects.index'));
        $this->assertDatabaseMissing('subjects', [
            'id' => $subject->id,
        ], 'doc_ease');
    }

    public function test_admin_can_assign_teacher_and_revoke_when_not_roster_locked(): void
    {
        $admin = $this->makeDocEaseUser(
            username: 'assignment-admin',
            useremail: 'assignment-admin@example.com',
            role: 'admin',
            isActive: true,
        );
        $teacher = $this->makeDocEaseUser(
            username: 'assignment-teacher',
            useremail: 'assignment-teacher@example.com',
            role: 'teacher',
            isActive: true,
        );
        $subject = $this->makeSubject('ENG201', 'Technical Writing');

        $storeResponse = $this->actingAs($admin, 'doc_ease')
            ->post(route('doc-ease.academic.assignments.store'), [
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'academic_year' => '2025-2026',
                'semester' => '2nd',
                'section' => 'a1',
                'teacher_role' => 'primary',
                'assignment_notes' => 'Core subject assignment',
            ]);

        $storeResponse->assertRedirect(route('doc-ease.academic.assignments.index'));

        $classRecord = DB::connection('doc_ease')->table('class_records')
            ->where('subject_id', $subject->id)
            ->where('academic_year', '2025-2026')
            ->where('semester', '2nd')
            ->first();

        $this->assertNotNull($classRecord);
        $this->assertSame('A1', $classRecord->section);

        $assignment = DB::connection('doc_ease')->table('teacher_assignments')
            ->where('teacher_id', $teacher->id)
            ->where('class_record_id', $classRecord->id)
            ->first();

        $this->assertNotNull($assignment);
        $this->assertSame('active', $assignment->status);

        $revokeResponse = $this->actingAs($admin, 'doc_ease')
            ->post(route('doc-ease.academic.assignments.revoke', ['assignmentId' => $assignment->id]));

        $revokeResponse->assertRedirect(route('doc-ease.academic.assignments.index'));
        $this->assertDatabaseHas('teacher_assignments', [
            'id' => $assignment->id,
            'status' => 'inactive',
        ], 'doc_ease');
    }

    public function test_assignment_revoke_is_blocked_when_class_has_enrolled_students(): void
    {
        $admin = $this->makeDocEaseUser(
            username: 'roster-admin',
            useremail: 'roster-admin@example.com',
            role: 'admin',
            isActive: true,
        );
        $teacher = $this->makeDocEaseUser(
            username: 'roster-teacher',
            useremail: 'roster-teacher@example.com',
            role: 'teacher',
            isActive: true,
        );
        $subject = $this->makeSubject('SCI301', 'Earth Science');

        $classRecordId = DB::connection('doc_ease')->table('class_records')->insertGetId([
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'record_type' => 'assigned',
            'section' => 'B2',
            'academic_year' => '2025-2026',
            'semester' => '1st',
            'created_by' => $admin->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = DB::connection('doc_ease')->table('teacher_assignments')->insertGetId([
            'teacher_id' => $teacher->id,
            'teacher_role' => 'primary',
            'class_record_id' => $classRecordId,
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'status' => 'active',
            'assignment_notes' => 'Initial assignment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('doc_ease')->table('class_enrollments')->insert([
            'class_record_id' => $classRecordId,
            'student_id' => 9001,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'doc_ease')
            ->post(route('doc-ease.academic.assignments.revoke', ['assignmentId' => $assignmentId]));

        $response->assertRedirect(route('doc-ease.academic.assignments.index'));
        $response->assertSessionHas('status_type', 'warning');
        $this->assertDatabaseHas('teacher_assignments', [
            'id' => $assignmentId,
            'status' => 'active',
        ], 'doc_ease');
    }

    public function test_assignment_store_is_blocked_when_matching_class_record_is_roster_locked(): void
    {
        $admin = $this->makeDocEaseUser(
            username: 'store-lock-admin',
            useremail: 'store-lock-admin@example.com',
            role: 'admin',
            isActive: true,
        );
        $teacher = $this->makeDocEaseUser(
            username: 'store-lock-teacher',
            useremail: 'store-lock-teacher@example.com',
            role: 'teacher',
            isActive: true,
        );
        $subject = $this->makeSubject('HIST101', 'World History');

        $classRecordId = DB::connection('doc_ease')->table('class_records')->insertGetId([
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'record_type' => 'assigned',
            'section' => 'C3',
            'academic_year' => '2025-2026',
            'semester' => '1st',
            'created_by' => $admin->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('doc_ease')->table('class_enrollments')->insert([
            'class_record_id' => $classRecordId,
            'student_id' => 9002,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'doc_ease')
            ->post(route('doc-ease.academic.assignments.store'), [
                'teacher_id' => $teacher->id,
                'subject_id' => $subject->id,
                'academic_year' => '2025-2026',
                'semester' => '1st',
                'section' => 'C3',
                'teacher_role' => 'co_teacher',
                'assignment_notes' => 'Should fail due to lock',
            ]);

        $response->assertRedirect(route('doc-ease.academic.assignments.index'));
        $response->assertSessionHas('status_type', 'warning');
        $this->assertSame(
            0,
            DB::connection('doc_ease')->table('teacher_assignments')->count()
        );
    }

    private function makeSubject(string $code, string $name): DocEaseSubject
    {
        $id = DB::connection('doc_ease')->table('subjects')->insertGetId([
            'subject_code' => $code,
            'subject_name' => $name,
            'type' => 'Lecture',
            'status' => 'active',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DocEaseSubject::on('doc_ease')->findOrFail($id);
    }

    private function makeDocEaseUser(
        string $username,
        string $useremail,
        string $role,
        bool $isActive
    ): DocEaseUser {
        $id = DB::connection('doc_ease')->table('users')->insertGetId([
            'username' => $username,
            'useremail' => $useremail,
            'password' => Hash::make('secret123'),
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
            'campus_id' => 1,
            'is_superadmin' => 0,
        ]);

        return DocEaseUser::on('doc_ease')->findOrFail($id);
    }
}
