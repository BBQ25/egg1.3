<?php
/**
 * One-off data migration helper.
 *
 * Goal:
 * - For subjects matching "Embedded System(s)", move active assignments from BSIT - 4A to BSIT - 3A and BSIT - 3B.
 * - Respect the rule: assignments are only editable when the source class record has no enrolled students.
 * - Optionally normalize academic_year strings to match `academic_years` reference values.
 *
 * Run (PowerShell):
 *   php tools/migrate_embedded_system_sections.php
 */

require __DIR__ . '/../config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function stdout($s) { fwrite(STDOUT, $s . PHP_EOL); }
function stderr($s) { fwrite(STDERR, $s . PHP_EOL); }

function fetch_all_assoc(mysqli_stmt $stmt) {
    $res = $stmt->get_result();
    $rows = [];
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    return $rows;
}

function class_has_students(mysqli $conn, int $classRecordId): bool {
    $chk = $conn->prepare("SELECT 1 FROM class_enrollments WHERE class_record_id = ? LIMIT 1");
    $chk->bind_param('i', $classRecordId);
    $chk->execute();
    $res = $chk->get_result();
    $has = ($res && $res->num_rows > 0);
    $chk->close();
    return $has;
}

function normalize_academic_year(mysqli $conn, string $ay): string {
    $ay = trim($ay);
    if ($ay === '') return $ay;

    // If it exists in references, keep it.
    $q = $conn->prepare("SELECT 1 FROM academic_years WHERE name = ? LIMIT 1");
    $q->bind_param('s', $ay);
    $q->execute();
    $res = $q->get_result();
    $exists = ($res && $res->num_rows === 1);
    $q->close();
    if ($exists) return $ay;

    // Basic normalization: ensure spaces around dash, e.g. "2025 -2026" -> "2025 - 2026".
    $fixed = preg_replace('/\\s*-\\s*/', ' - ', $ay);
    $fixed = trim(preg_replace('/\\s+/', ' ', (string) $fixed));

    $q2 = $conn->prepare("SELECT 1 FROM academic_years WHERE name = ? LIMIT 1");
    $q2->bind_param('s', $fixed);
    $q2->execute();
    $res2 = $q2->get_result();
    $exists2 = ($res2 && $res2->num_rows === 1);
    $q2->close();
    if ($exists2) return $fixed;

    return $ay;
}

function upsert_class_record(
    mysqli $conn,
    int $subjectId,
    int $teacherId,
    string $section,
    string $academicYear,
    string $semester,
    int $createdBy
): array {
    // Prefer reusing an existing active class_record.
    $find = $conn->prepare(
        "SELECT id, teacher_id, status
         FROM class_records
         WHERE subject_id = ? AND section = ? AND academic_year = ? AND semester = ?
         ORDER BY (status='active') DESC, id DESC
         LIMIT 1"
    );
    $find->bind_param('isss', $subjectId, $section, $academicYear, $semester);
    $find->execute();
    $res = $find->get_result();
    $row = $res && $res->num_rows === 1 ? $res->fetch_assoc() : null;
    $find->close();

    if ($row) {
        $id = (int) ($row['id'] ?? 0);
        $status = (string) ($row['status'] ?? '');
        $currentTeacher = (int) ($row['teacher_id'] ?? 0);

        $hasStudents = class_has_students($conn, $id);
        if ($hasStudents) {
            return ['id' => $id, 'action' => 'exists_locked', 'teacher_id' => $currentTeacher, 'status' => $status];
        }

        // Reactivate/update teacher pointer if safe.
        $upd = $conn->prepare("UPDATE class_records SET teacher_id = ?, record_type = 'assigned', status = 'active' WHERE id = ?");
        $upd->bind_param('ii', $teacherId, $id);
        $upd->execute();
        $upd->close();

        return ['id' => $id, 'action' => ($status === 'active' ? 'reused' : 'reactivated'), 'teacher_id' => $teacherId, 'status' => 'active'];
    }

    $ins = $conn->prepare(
        "INSERT INTO class_records (subject_id, teacher_id, record_type, section, academic_year, semester, created_by, status)
         VALUES (?, ?, 'assigned', ?, ?, ?, ?, 'active')"
    );
    $ins->bind_param('iisssi', $subjectId, $teacherId, $section, $academicYear, $semester, $createdBy);
    $ins->execute();
    $id = (int) $conn->insert_id;
    $ins->close();

    return ['id' => $id, 'action' => 'created', 'teacher_id' => $teacherId, 'status' => 'active'];
}

function upsert_teacher_assignment(
    mysqli $conn,
    int $teacherId,
    int $classRecordId,
    int $assignedBy,
    string $teacherRole = 'primary',
    string $notes = ''
): array {
    $find = $conn->prepare("SELECT id, status FROM teacher_assignments WHERE teacher_id = ? AND class_record_id = ? LIMIT 1");
    $find->bind_param('ii', $teacherId, $classRecordId);
    $find->execute();
    $res = $find->get_result();
    $row = $res && $res->num_rows === 1 ? $res->fetch_assoc() : null;
    $find->close();

    if ($row) {
        $id = (int) ($row['id'] ?? 0);
        $upd = $conn->prepare(
            "UPDATE teacher_assignments
             SET teacher_role = ?, assigned_by = ?, status = 'active', assignment_notes = ?, assigned_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $upd->bind_param('sisi', $teacherRole, $assignedBy, $notes, $id);
        $upd->execute();
        $upd->close();
        return ['id' => $id, 'action' => 'updated'];
    }

    $ins = $conn->prepare(
        "INSERT INTO teacher_assignments (teacher_id, teacher_role, class_record_id, assigned_by, status, assignment_notes)
         VALUES (?, ?, ?, ?, 'active', ?)"
    );
    $ins->bind_param('isiis', $teacherId, $teacherRole, $classRecordId, $assignedBy, $notes);
    $ins->execute();
    $id = (int) $conn->insert_id;
    $ins->close();
    return ['id' => $id, 'action' => 'created'];
}

function clone_grading_configs_for_section(
    mysqli $conn,
    int $subjectId,
    string $course,
    string $year,
    string $fromSection,
    string $toSection,
    string $academicYear,
    string $semester
): array {
    // Copy each term config (midterm/final) if the target doesn't exist yet.
    $src = $conn->prepare(
        "SELECT id, term, total_weight, is_active, created_by
         FROM section_grading_configs
         WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ?"
    );
    $src->bind_param('isssss', $subjectId, $course, $year, $fromSection, $academicYear, $semester);
    $src->execute();
    $srcRows = fetch_all_assoc($src);
    $src->close();

    if (count($srcRows) === 0) return ['copied' => 0, 'skipped' => 0];

    $copied = 0;
    $skipped = 0;

    foreach ($srcRows as $row) {
        $srcConfigId = (int) ($row['id'] ?? 0);
        $term = (string) ($row['term'] ?? 'midterm');
        $totalWeight = (float) ($row['total_weight'] ?? 100.0);
        $isActive = (int) ($row['is_active'] ?? 1);
        $createdBy = (string) ($row['created_by'] ?? '');

        // Target config exists?
        $chk = $conn->prepare(
            "SELECT id
             FROM section_grading_configs
             WHERE subject_id = ? AND course = ? AND year = ? AND section = ? AND academic_year = ? AND semester = ? AND term = ?
             LIMIT 1"
        );
        $chk->bind_param('issssss', $subjectId, $course, $year, $toSection, $academicYear, $semester, $term);
        $chk->execute();
        $chkRes = $chk->get_result();
        $existingTargetId = ($chkRes && $chkRes->num_rows === 1) ? (int) ($chkRes->fetch_assoc()['id'] ?? 0) : 0;
        $chk->close();

        if ($existingTargetId > 0) {
            $skipped++;
            continue;
        }

        $ins = $conn->prepare(
            "INSERT INTO section_grading_configs
                (subject_id, course, year, section, academic_year, semester, term, total_weight, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->bind_param('issssssdis', $subjectId, $course, $year, $toSection, $academicYear, $semester, $term, $totalWeight, $isActive, $createdBy);
        $ins->execute();
        $targetConfigId = (int) $conn->insert_id;
        $ins->close();

        // Copy grading_components under the config.
        $c = $conn->prepare(
            "SELECT subject_id, academic_year, semester, course, year, category_id,
                    component_name, component_code, component_type, weight, is_active, display_order, created_by
             FROM grading_components
             WHERE section_config_id = ?
             ORDER BY display_order ASC, id ASC"
        );
        $c->bind_param('i', $srcConfigId);
        $c->execute();
        $compRows = fetch_all_assoc($c);
        $c->close();

        if (count($compRows) > 0) {
            $insC = $conn->prepare(
                "INSERT INTO grading_components
                    (subject_id, section_config_id, academic_year, semester, course, year, section, category_id,
                     component_name, component_code, component_type, weight, is_active, display_order, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($compRows as $r) {
                $sid = (int) ($r['subject_id'] ?? $subjectId);
                $ay = (string) ($r['academic_year'] ?? $academicYear);
                $sem = (string) ($r['semester'] ?? $semester);
                $crs = (string) ($r['course'] ?? $course);
                $yr = (string) ($r['year'] ?? $year);
                $cat = isset($r['category_id']) ? (int) $r['category_id'] : 0;
                $name = (string) ($r['component_name'] ?? '');
                $code = (string) ($r['component_code'] ?? '');
                $type = (string) ($r['component_type'] ?? 'other');
                $w = (float) ($r['weight'] ?? 0);
                $active = (int) ($r['is_active'] ?? 1);
                $order = (int) ($r['display_order'] ?? 0);
                $by = (string) ($r['created_by'] ?? '');

                $insC->bind_param('iisssssisssdiis', $sid, $targetConfigId, $ay, $sem, $crs, $yr, $toSection, $cat, $name, $code, $type, $w, $active, $order, $by);
                $insC->execute();
            }

            $insC->close();
        }

        $copied++;
    }

    return ['copied' => $copied, 'skipped' => $skipped];
}

$subjectLike = '%Embedded%System%';
$sourceSection = 'BSIT - 4A';
$targetSections = ['BSIT - 3A', 'BSIT - 3B'];

stdout('Migration: Embedded Systems sections');
stdout('From: ' . $sourceSection);
stdout('To:   ' . implode(', ', $targetSections));

// 1) Find active class_records in source section for embedded subjects.
$src = $conn->prepare(
    "SELECT cr.id, cr.subject_id, cr.teacher_id, cr.section, cr.academic_year, cr.semester, cr.status, cr.created_by,
            s.subject_name, s.subject_code,
            EXISTS(SELECT 1 FROM class_enrollments ce WHERE ce.class_record_id = cr.id LIMIT 1) AS has_students
     FROM class_records cr
     JOIN subjects s ON s.id = cr.subject_id
     WHERE cr.status = 'active'
       AND cr.section = ?
       AND (s.subject_name LIKE ? OR s.subject_code LIKE ?)
     ORDER BY cr.id ASC"
);
$src->bind_param('sss', $sourceSection, $subjectLike, $subjectLike);
$src->execute();
$rows = fetch_all_assoc($src);
$src->close();

if (count($rows) === 0) {
    stdout('No active class_records found. Nothing to do.');
    exit(0);
}

// 2) Normalize academic_year strings in-place for these records (and configs/components for the same subject/section/term set).
foreach ($rows as &$r) {
    $ay = (string) ($r['academic_year'] ?? '');
    $normAy = normalize_academic_year($conn, $ay);
    if ($normAy !== $ay && $ay !== '') {
        $crId = (int) ($r['id'] ?? 0);
        $subId = (int) ($r['subject_id'] ?? 0);
        $sem = (string) ($r['semester'] ?? '');

        $updCr = $conn->prepare("UPDATE class_records SET academic_year = ? WHERE id = ?");
        $updCr->bind_param('si', $normAy, $crId);
        $updCr->execute();
        $updCr->close();

        $updCfg = $conn->prepare(
            "UPDATE section_grading_configs
             SET academic_year = ?
             WHERE subject_id = ? AND section = ? AND academic_year = ? AND semester = ?"
        );
        $updCfg->bind_param('sisss', $normAy, $subId, $sourceSection, $ay, $sem);
        $updCfg->execute();
        $updCfg->close();

        $updComp = $conn->prepare(
            "UPDATE grading_components gc
             JOIN section_grading_configs sc ON sc.id = gc.section_config_id
             SET gc.academic_year = ?
             WHERE sc.subject_id = ? AND sc.section = ? AND sc.academic_year = ? AND sc.semester = ?"
        );
        $updComp->bind_param('sisss', $normAy, $subId, $sourceSection, $ay, $sem);
        $updComp->execute();
        $updComp->close();

        $r['academic_year'] = $normAy;
        stdout("Normalized academic_year for CR#{$crId}: '{$ay}' -> '{$normAy}'");
    }
}
unset($r);

// 3) Create/activate target class_records + assignments, clone grading configs, then deactivate source assignment/record (if unlocked).
$conn->begin_transaction();
try {
    foreach ($rows as $r) {
        $crId = (int) ($r['id'] ?? 0);
        $subjectId = (int) ($r['subject_id'] ?? 0);
        $teacherId = (int) ($r['teacher_id'] ?? 0);
        $subjectName = (string) ($r['subject_name'] ?? '');
        $subjectCode = (string) ($r['subject_code'] ?? '');
        $ay = (string) ($r['academic_year'] ?? '');
        $sem = (string) ($r['semester'] ?? '');
        $createdBy = (int) ($r['created_by'] ?? 0);
        $hasStudents = !empty($r['has_students']);

        stdout('');
        stdout("Source CR#{$crId}: {$subjectName} ({$subjectCode}) | AY {$ay} | {$sem} | {$sourceSection} | teacher#{$teacherId} | students=" . ($hasStudents ? 'yes' : 'no'));

        if ($hasStudents) {
            stdout('  SKIP: source class_record has enrolled students; cannot edit/move assignment.');
            continue;
        }

        foreach ($targetSections as $toSection) {
            $cr = upsert_class_record($conn, $subjectId, $teacherId, $toSection, $ay, $sem, $createdBy);
            $targetCrId = (int) ($cr['id'] ?? 0);
            $crAction = (string) ($cr['action'] ?? 'unknown');

            if ($crAction === 'exists_locked') {
                stdout("  Target {$toSection}: CR#{$targetCrId} locked (students enrolled). No changes applied.");
                continue;
            }

            $ta = upsert_teacher_assignment(
                $conn,
                $teacherId,
                $targetCrId,
                $createdBy,
                'primary',
                'Migrated from ' . $sourceSection
            );
            stdout("  Target {$toSection}: class_record {$crAction} (CR#{$targetCrId}); assignment {$ta['action']} (TA#{$ta['id']})");

            // Clone grading configs/components for this subject/AY/semester if present.
            // course/year in section_grading_configs are required keys. We infer them from existing configs for the source section.
            $infer = $conn->prepare(
                "SELECT DISTINCT course, year
                 FROM section_grading_configs
                 WHERE subject_id = ? AND section = ? AND academic_year = ? AND semester = ?
                 LIMIT 1"
            );
            $infer->bind_param('isss', $subjectId, $sourceSection, $ay, $sem);
            $infer->execute();
            $inferRes = $infer->get_result();
            $inferRow = ($inferRes && $inferRes->num_rows === 1) ? $inferRes->fetch_assoc() : null;
            $infer->close();

            if ($inferRow) {
                $course = (string) ($inferRow['course'] ?? 'N/A');
                $year = (string) ($inferRow['year'] ?? 'N/A');
                $clone = clone_grading_configs_for_section($conn, $subjectId, $course, $year, $sourceSection, $toSection, $ay, $sem);
                if (($clone['copied'] ?? 0) > 0) {
                    stdout("    Grading config: copied {$clone['copied']} term(s); skipped {$clone['skipped']} (already exists).");
                }
            }
        }

        // Deactivate source assignment + record (safe because no students).
        $offTa = $conn->prepare("UPDATE teacher_assignments SET status = 'inactive' WHERE class_record_id = ?");
        $offTa->bind_param('i', $crId);
        $offTa->execute();
        $offTa->close();

        // class_records.status is enum('active','archived')
        $offCr = $conn->prepare("UPDATE class_records SET status = 'archived' WHERE id = ?");
        $offCr->bind_param('i', $crId);
        $offCr->execute();
        $offCr->close();

        stdout("  Source deactivated: teacher_assignments inactive; class_record archived.");
    }

    $conn->commit();
    stdout('');
    stdout('DONE.');
} catch (Throwable $e) {
    $conn->rollback();
    stderr('FAILED: ' . $e->getMessage());
    exit(1);
}
