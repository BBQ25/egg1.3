<?php
/**
 * Split legacy `sections` usage into:
 * - profile_sections / profile_section_subjects
 * - class_sections / class_section_subjects
 *
 * Safe by default (dry-run).
 *
 * Usage:
 *   php tools/migrate_split_sections_catalog.php
 *   php tools/migrate_split_sections_catalog.php --apply
 *   php tools/migrate_split_sections_catalog.php --apply --backup-db=doc_ease_backup_manual
 *   php tools/migrate_split_sections_catalog.php --apply --deprecate-legacy
 *   php tools/migrate_split_sections_catalog.php --apply --drop-legacy
 */

require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/reference.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function out($msg) { fwrite(STDOUT, $msg . PHP_EOL); }
function err($msg) { fwrite(STDERR, $msg . PHP_EOL); }

function has_flag(array $argv, string $flag): bool {
    return in_array($flag, $argv, true);
}

function get_opt(array $argv, string $prefix, string $default = ''): string {
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function qname(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
}

function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare(
        "SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1"
    );
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows === 1;
    $stmt->close();
    return $ok;
}

function backup_table(mysqli $conn, string $backupDb, string $table): bool {
    if (!table_exists($conn, $table)) return false;
    $dbQ = qname($backupDb);
    $tblQ = qname($table);
    $conn->query("CREATE TABLE IF NOT EXISTS {$dbQ}.{$tblQ} LIKE {$tblQ}");
    $conn->query("TRUNCATE TABLE {$dbQ}.{$tblQ}");
    $conn->query("INSERT INTO {$dbQ}.{$tblQ} SELECT * FROM {$tblQ}");
    return true;
}

$dryRun = !has_flag($argv, '--apply');
$deprecateLegacy = has_flag($argv, '--deprecate-legacy');
$dropLegacy = has_flag($argv, '--drop-legacy');
if ($dropLegacy) $deprecateLegacy = true;

$backupDb = trim(get_opt($argv, '--backup-db=', ''));
if ($backupDb === '') {
    $backupDb = 'doc_ease_backup_' . date('Ymd_His');
}

if (has_flag($argv, '--help')) {
    out('Usage:');
    out('  php tools/migrate_split_sections_catalog.php [--apply] [--backup-db=NAME] [--deprecate-legacy] [--drop-legacy]');
    exit(0);
}

out('Section Split Migration');
out('Mode: ' . ($dryRun ? 'DRY-RUN' : 'APPLY'));
out('Backup DB: ' . $backupDb);
out('Deprecate legacy tables: ' . ($deprecateLegacy ? 'yes' : 'no'));
out('Drop legacy tables: ' . ($dropLegacy ? 'yes' : 'no'));

$tablesToBackup = [
    'sections',
    'section_subjects',
    'students',
    'class_records',
    'profile_sections',
    'class_sections',
    'profile_section_subjects',
    'class_section_subjects',
];

if (!$dryRun) {
    $conn->query('CREATE DATABASE IF NOT EXISTS ' . qname($backupDb) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach ($tablesToBackup as $tbl) {
        if (backup_table($conn, $backupDb, $tbl)) {
            out("Backed up table: {$tbl}");
        }
    }
}

if ($dryRun) {
    out('DRY-RUN only. No schema/data changes were executed.');
    out('Re-run with --apply to execute migration.');
    exit(0);
}

ref_ensure_section_reference_tables($conn);

$summary = [
    'profile_from_students_new' => 0,
    'profile_from_students_updated' => 0,
    'class_from_records_new' => 0,
    'class_from_records_updated' => 0,
    'legacy_profile_mapped' => 0,
    'legacy_class_mapped' => 0,
    'legacy_unresolved' => 0,
    'class_subject_map_new' => 0,
    'class_subject_map_existing' => 0,
    'profile_subject_map_new' => 0,
    'profile_subject_map_existing' => 0,
    'subject_map_unresolved' => 0,
];

// 1) sync profile_sections from students
$syncProfile = ref_sync_profile_sections_from_students($conn);
$summary['profile_from_students_new'] += (int) ($syncProfile['inserted'] ?? 0);
$summary['profile_from_students_updated'] += (int) ($syncProfile['updated'] ?? 0);

// 2) sync class_sections from class_records/legacy IF codes
$syncClass = ref_sync_class_sections_from_records($conn);
$summary['class_from_records_new'] += (int) ($syncClass['inserted'] ?? 0);
$summary['class_from_records_updated'] += (int) ($syncClass['updated'] ?? 0);

// helpers for id lookup
$getClassIdStmt = $conn->prepare("SELECT id FROM class_sections WHERE code = ? LIMIT 1");
$getProfileIdStmt = $conn->prepare("SELECT id FROM profile_sections WHERE course = ? AND year_level = ? AND section_code = ? LIMIT 1");

$upsertClassStmt = $conn->prepare(
    "INSERT INTO class_sections (code, description, status, source)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        description = CASE
            WHEN VALUES(description) <> '' THEN VALUES(description)
            ELSE class_sections.description
        END,
        status = VALUES(status),
        source = VALUES(source),
        updated_at = CURRENT_TIMESTAMP"
);
$upsertProfileStmt = $conn->prepare(
    "INSERT INTO profile_sections (course, year_level, section_code, label, status, source)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        label = VALUES(label),
        status = VALUES(status),
        source = VALUES(source),
        updated_at = CURRENT_TIMESTAMP"
);

if (!$getClassIdStmt || !$getProfileIdStmt || !$upsertClassStmt || !$upsertProfileStmt) {
    throw new RuntimeException('Unable to prepare section migration statements.');
}

$legacySectionRows = [];
if (table_exists($conn, 'sections')) {
    $legacyRes = $conn->query(
        "SELECT id, name, COALESCE(description, '') AS description, status
         FROM sections
         ORDER BY id ASC"
    );
    if ($legacyRes) {
        while ($r = $legacyRes->fetch_assoc()) $legacySectionRows[] = $r;
    }
}

foreach ($legacySectionRows as $row) {
    $name = trim((string) ($row['name'] ?? ''));
    $description = trim((string) ($row['description'] ?? ''));
    $status = strtolower(trim((string) ($row['status'] ?? 'active'))) === 'inactive' ? 'inactive' : 'active';
    if ($name === '') continue;

    if (ref_is_if_section($name)) {
        $code = strtoupper($name);
        $source = 'legacy_sections';
        $upsertClassStmt->bind_param('ssss', $code, $description, $status, $source);
        $upsertClassStmt->execute();
        $affected = (int) $upsertClassStmt->affected_rows;
        if ($affected === 1) $summary['legacy_class_mapped']++;
        if ($affected === 2) $summary['legacy_class_mapped']++;
        continue;
    }

    $parsed = ref_parse_profile_section_label($name);
    if (!is_array($parsed)) {
        $summary['legacy_unresolved']++;
        continue;
    }

    $course = trim((string) ($parsed['course'] ?? ''));
    $year = trim((string) ($parsed['year'] ?? ''));
    $sectionCode = trim((string) ($parsed['section'] ?? ''));
    if ($course === '' || $year === '' || $sectionCode === '') {
        $summary['legacy_unresolved']++;
        continue;
    }

    $label = $course . ' - ' . $year . ' - ' . $sectionCode;
    $source = 'legacy_sections';
    $upsertProfileStmt->bind_param('ssssss', $course, $year, $sectionCode, $label, $status, $source);
    $upsertProfileStmt->execute();
    $affected = (int) $upsertProfileStmt->affected_rows;
    if ($affected === 1) $summary['legacy_profile_mapped']++;
    if ($affected === 2) $summary['legacy_profile_mapped']++;
}

$upsertClassStmt->close();
$upsertProfileStmt->close();

// 3) migrate section_subjects into split mapping tables
$insClassSubject = $conn->prepare(
    "INSERT INTO class_section_subjects (class_section_id, subject_id)
     VALUES (?, ?)
     ON DUPLICATE KEY UPDATE id = id"
);
$insProfileSubject = $conn->prepare(
    "INSERT INTO profile_section_subjects (profile_section_id, subject_id)
     VALUES (?, ?)
     ON DUPLICATE KEY UPDATE id = id"
);
if (!$insClassSubject || !$insProfileSubject) {
    throw new RuntimeException('Unable to prepare section-subject migration statements.');
}

if (table_exists($conn, 'section_subjects') && table_exists($conn, 'sections')) {
    $mapRes = $conn->query(
        "SELECT ss.section_id, ss.subject_id, sec.name
         FROM section_subjects ss
         INNER JOIN sections sec ON sec.id = ss.section_id
         ORDER BY ss.id ASC"
    );
    while ($mapRes && ($row = $mapRes->fetch_assoc())) {
        $subjectId = (int) ($row['subject_id'] ?? 0);
        $name = trim((string) ($row['name'] ?? ''));
        if ($subjectId <= 0 || $name === '') {
            $summary['subject_map_unresolved']++;
            continue;
        }

        if (ref_is_if_section($name)) {
            $code = strtoupper($name);
            $getClassIdStmt->bind_param('s', $code);
            $getClassIdStmt->execute();
            $classRes = $getClassIdStmt->get_result();
            $classId = ($classRes && $classRes->num_rows === 1) ? (int) ($classRes->fetch_assoc()['id'] ?? 0) : 0;
            if ($classId <= 0) {
                $summary['subject_map_unresolved']++;
                continue;
            }
            $insClassSubject->bind_param('ii', $classId, $subjectId);
            $insClassSubject->execute();
            $summary[((int) $insClassSubject->affected_rows === 1) ? 'class_subject_map_new' : 'class_subject_map_existing']++;
            continue;
        }

        $parsed = ref_parse_profile_section_label($name);
        if (!is_array($parsed)) {
            $summary['subject_map_unresolved']++;
            continue;
        }

        $course = trim((string) ($parsed['course'] ?? ''));
        $year = trim((string) ($parsed['year'] ?? ''));
        $sectionCode = trim((string) ($parsed['section'] ?? ''));
        if ($course === '' || $year === '' || $sectionCode === '') {
            $summary['subject_map_unresolved']++;
            continue;
        }
        $getProfileIdStmt->bind_param('sss', $course, $year, $sectionCode);
        $getProfileIdStmt->execute();
        $profileRes = $getProfileIdStmt->get_result();
        $profileId = ($profileRes && $profileRes->num_rows === 1) ? (int) ($profileRes->fetch_assoc()['id'] ?? 0) : 0;
        if ($profileId <= 0) {
            $summary['subject_map_unresolved']++;
            continue;
        }

        $insProfileSubject->bind_param('ii', $profileId, $subjectId);
        $insProfileSubject->execute();
        $summary[((int) $insProfileSubject->affected_rows === 1) ? 'profile_subject_map_new' : 'profile_subject_map_existing']++;
    }
}

$getClassIdStmt->close();
$getProfileIdStmt->close();
$insClassSubject->close();
$insProfileSubject->close();

if ($deprecateLegacy) {
    $suffix = date('Ymd_His');
    foreach (['section_subjects', 'sections'] as $legacyTable) {
        if (!table_exists($conn, $legacyTable)) continue;
        if ($dropLegacy) {
            $conn->query('DROP TABLE ' . qname($legacyTable));
            out("Dropped legacy table: {$legacyTable}");
            continue;
        }

        $target = $legacyTable . '__legacy_' . $suffix;
        if (strlen($target) > 64) {
            $target = substr($legacyTable, 0, 20) . '__lg_' . date('ymdHis');
        }
        $conn->query('RENAME TABLE ' . qname($legacyTable) . ' TO ' . qname($target));
        out("Deprecated legacy table: {$legacyTable} -> {$target}");
    }
}

out('');
out('Migration summary:');
foreach ($summary as $k => $v) {
    out('  ' . $k . ': ' . $v);
}
out('');
out('Done.');
