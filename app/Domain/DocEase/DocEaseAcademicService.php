<?php

namespace App\Domain\DocEase;

use App\Models\DocEaseClassEnrollment;
use App\Models\DocEaseClassRecord;
use App\Models\DocEaseSection;
use App\Models\DocEaseSubject;
use App\Models\DocEaseTeacherAssignment;
use App\Models\DocEaseUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DocEaseAcademicService
{
    /**
     * @return array<int, DocEaseUser>
     */
    public function activeTeachers(): array
    {
        return DocEaseUser::query()
            ->where('role', 'teacher')
            ->where('is_active', 1)
            ->orderBy('username')
            ->get()
            ->all();
    }

    /**
     * @return array<int, DocEaseSubject>
     */
    public function activeSubjects(): array
    {
        return DocEaseSubject::query()
            ->where('status', 'active')
            ->orderBy('subject_name')
            ->get()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function academicYearOptions(): array
    {
        $values = [];
        $push = static function (&$list, $value): void {
            $value = trim((string) $value);
            if ($value === '' || in_array($value, $list, true)) {
                return;
            }
            $list[] = $value;
        };

        if ($this->tableExists('academic_years')) {
            try {
                foreach (DB::connection('doc_ease')->table('academic_years')
                    ->where('status', 'active')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->pluck('name') as $name) {
                    $push($values, $name);
                }
            } catch (\Throwable) {
                // Fallback below.
            }
        }

        if ($values === []) {
            foreach ($this->distinctNonEmptyValues('enrollments', 'academic_year', true) as $v) {
                $push($values, $v);
            }
            foreach ($this->distinctNonEmptyValues('subjects', 'academic_year', true) as $v) {
                $push($values, $v);
            }
            foreach ($this->distinctNonEmptyValues('class_records', 'academic_year', true) as $v) {
                $push($values, $v);
            }
        }

        rsort($values);

        return array_values($values);
    }

    /**
     * @return list<string>
     */
    public function semesterOptions(): array
    {
        $values = [];
        $push = static function (&$list, $value): void {
            $value = trim((string) $value);
            if ($value === '' || in_array($value, $list, true)) {
                return;
            }
            $list[] = $value;
        };

        if ($this->tableExists('semesters')) {
            try {
                foreach (DB::connection('doc_ease')->table('semesters')
                    ->where('status', 'active')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->pluck('name') as $name) {
                    $push($values, $name);
                }
            } catch (\Throwable) {
                // Fallback below.
            }
        }

        if ($values === []) {
            foreach ($this->distinctNonEmptyValues('enrollments', 'semester', false) as $v) {
                $push($values, $v);
            }
            foreach ($this->distinctNonEmptyValues('subjects', 'semester', false) as $v) {
                $push($values, $v);
            }
            foreach ($this->distinctNonEmptyValues('class_records', 'semester', false) as $v) {
                $push($values, $v);
            }
        }

        sort($values);

        return array_values($values);
    }

    /**
     * @return list<string>
     */
    public function sectionOptions(): array
    {
        $values = [];
        $push = static function (&$list, $value): void {
            $value = strtoupper(trim((string) $value));
            if ($value === '' || in_array($value, $list, true)) {
                return;
            }
            $list[] = $value;
        };

        if ($this->tableExists('sections')) {
            try {
                foreach (DocEaseSection::query()
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->pluck('name') as $name) {
                    $push($values, $name);
                }
            } catch (\Throwable) {
                // Fallback below.
            }
        }

        foreach ($this->distinctNonEmptyValues('class_records', 'section', false) as $value) {
            $push($values, $value);
        }

        sort($values);

        return array_values($values);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function assignments(int $limit = 250): array
    {
        if (!$this->tableExists('teacher_assignments') || !$this->tableExists('class_records') || !$this->tableExists('subjects') || !$this->tableExists('users')) {
            return [];
        }

        $limit = max(1, min(1000, $limit));

        try {
            $rows = DB::connection('doc_ease')
                ->table('teacher_assignments as ta')
                ->join('users as u', 'u.id', '=', 'ta.teacher_id')
                ->join('class_records as cr', 'cr.id', '=', 'ta.class_record_id')
                ->join('subjects as s', 's.id', '=', 'cr.subject_id')
                ->where('cr.status', 'active')
                ->select([
                    'ta.id as assignment_id',
                    'ta.teacher_role',
                    'ta.status',
                    'ta.assigned_at',
                    'ta.assignment_notes',
                    'u.username as teacher_name',
                    'u.useremail as teacher_email',
                    's.subject_code',
                    's.subject_name',
                    'cr.id as class_record_id',
                    'cr.section',
                    'cr.academic_year',
                    'cr.semester',
                    DB::raw('EXISTS(SELECT 1 FROM class_enrollments ce WHERE ce.class_record_id = cr.id LIMIT 1) as has_students'),
                ])
                ->orderByRaw('COALESCE(ta.assigned_at, ta.created_at) DESC')
                ->orderByDesc('ta.id')
                ->limit($limit)
                ->get()
                ->all();

            return array_map(static function ($row): array {
                $arr = (array) $row;
                $arr['has_students'] = (int) ($arr['has_students'] ?? 0);
                return $arr;
            }, $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array{
     *   teacher_id:int,
     *   subject_id:int,
     *   academic_year:string,
     *   semester:string,
     *   section:string,
     *   teacher_role:string,
     *   assignment_notes:string
     * }  $data
     */
    public function assignTeacher(DocEaseUser $actor, array $data): DocEaseTeacherAssignment
    {
        return DB::connection('doc_ease')->transaction(function () use ($actor, $data): DocEaseTeacherAssignment {
            $teacherId = (int) ($data['teacher_id'] ?? 0);
            $subjectId = (int) ($data['subject_id'] ?? 0);
            $academicYear = trim((string) ($data['academic_year'] ?? ''));
            $semester = trim((string) ($data['semester'] ?? ''));
            $section = strtoupper(trim((string) ($data['section'] ?? '')));
            $teacherRole = trim((string) ($data['teacher_role'] ?? 'primary'));
            $notes = trim((string) ($data['assignment_notes'] ?? ''));

            if (!in_array($teacherRole, ['primary', 'co_teacher'], true)) {
                $teacherRole = 'primary';
            }

            if ($teacherId <= 0 || $subjectId <= 0 || $academicYear === '' || $semester === '' || $section === '') {
                throw new RuntimeException('Teacher, Subject, Academic Year, Semester, and Section are required.');
            }

            $teacher = DocEaseUser::query()
                ->whereKey($teacherId)
                ->where('role', 'teacher')
                ->where('is_active', 1)
                ->first();
            if (!$teacher) {
                throw new RuntimeException('Teacher not found or not active.');
            }

            $subject = DocEaseSubject::query()
                ->whereKey($subjectId)
                ->where('status', 'active')
                ->first();
            if (!$subject) {
                throw new RuntimeException('Subject not found or inactive.');
            }

            $classRecord = DocEaseClassRecord::query()
                ->where('subject_id', $subjectId)
                ->where('section', $section)
                ->where('academic_year', $academicYear)
                ->where('semester', $semester)
                ->where('status', 'active')
                ->first();

            if ($classRecord) {
                $hasRoster = DocEaseClassEnrollment::query()
                    ->where('class_record_id', $classRecord->id)
                    ->exists();

                if ($hasRoster) {
                    throw new RuntimeException('Assignment locked: students are already enrolled in this class record.');
                }
            } else {
                $classRecord = new DocEaseClassRecord([
                    'subject_id' => $subjectId,
                    'teacher_id' => $teacherId,
                    'record_type' => 'assigned',
                    'section' => $section,
                    'academic_year' => $academicYear,
                    'semester' => $semester,
                    'created_by' => (int) $actor->id,
                    'status' => 'active',
                ]);
                $classRecord->save();
            }

            if ($teacherRole === 'primary' && (int) ($classRecord->teacher_id ?? 0) !== $teacherId) {
                $classRecord->teacher_id = $teacherId;
                $classRecord->record_type = 'assigned';
                $classRecord->save();
            }

            $assignment = DocEaseTeacherAssignment::query()
                ->where('teacher_id', $teacherId)
                ->where('class_record_id', $classRecord->id)
                ->first();

            if ($assignment) {
                $assignment->teacher_role = $teacherRole;
                $assignment->assigned_by = (int) $actor->id;
                $assignment->status = 'active';
                $assignment->assignment_notes = $notes;
                $assignment->assigned_at = now();
                $assignment->save();
                return $assignment;
            }

            $assignment = new DocEaseTeacherAssignment([
                'teacher_id' => $teacherId,
                'teacher_role' => $teacherRole,
                'class_record_id' => (int) $classRecord->id,
                'assigned_by' => (int) $actor->id,
                'status' => 'active',
                'assignment_notes' => $notes,
                'assigned_at' => now(),
            ]);
            $assignment->save();

            return $assignment;
        });
    }

    public function revokeAssignment(DocEaseUser $actor, int $assignmentId): void
    {
        $assignmentId = (int) $assignmentId;
        if ($assignmentId <= 0) {
            throw new RuntimeException('Invalid assignment.');
        }

        DB::connection('doc_ease')->transaction(function () use ($assignmentId): void {
            $assignment = DocEaseTeacherAssignment::query()->find($assignmentId);
            if (!$assignment) {
                throw new RuntimeException('Assignment not found.');
            }

            $classRecordId = (int) ($assignment->class_record_id ?? 0);
            if ($classRecordId > 0) {
                $hasRoster = DocEaseClassEnrollment::query()
                    ->where('class_record_id', $classRecordId)
                    ->exists();
                if ($hasRoster) {
                    throw new RuntimeException('This assignment is locked because students are already enrolled.');
                }
            }

            $assignment->status = 'inactive';
            $assignment->save();
        });
    }

    /**
     * @return list<string>
     */
    private function distinctNonEmptyValues(string $table, string $column, bool $orderDesc = false): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        try {
            $query = DB::connection('doc_ease')
                ->table($table)
                ->whereNotNull($column)
                ->where($column, '<>', '')
                ->distinct();

            $query = $orderDesc ? $query->orderByDesc($column) : $query->orderBy($column);

            return array_values(array_map(
                static fn ($value): string => trim((string) $value),
                $query->pluck($column)->all()
            ));
        } catch (\Throwable) {
            return [];
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::connection('doc_ease')->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}

