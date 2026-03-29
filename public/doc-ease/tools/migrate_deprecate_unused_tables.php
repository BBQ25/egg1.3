<?php
/**
 * Safe table cleanup helper.
 *
 * Default: plan-only (no writes).
 *
 * Usage:
 *   php tools/migrate_deprecate_unused_tables.php
 *   php tools/migrate_deprecate_unused_tables.php --mode=deprecate --apply
 *   php tools/migrate_deprecate_unused_tables.php --mode=drop --apply
 *   php tools/migrate_deprecate_unused_tables.php --mode=deprecate --apply --tables=assessments,grades,quiz_scores
 *   php tools/migrate_deprecate_unused_tables.php --mode=drop --apply --force
 */

require __DIR__ . '/../config/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function out($msg) { fwrite(STDOUT, $msg . PHP_EOL); }
function err($msg) { fwrite(STDERR, $msg . PHP_EOL); }

function has_flag(array $argv, string $flag): bool {
    return in_array($flag, $argv, true);
}

function get_opt(array $argv, string $prefix, string $default = ''): string {
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) return substr($arg, strlen($prefix));
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

function table_rows_estimate(mysqli $conn, string $table): int {
    $stmt = $conn->prepare(
        "SELECT COALESCE(TABLE_ROWS, 0) AS row_count
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1"
    );
    if (!$stmt) return 0;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return (int) ($row['row_count'] ?? 0);
}

function fk_counts(mysqli $conn, string $table): array {
    $inbound = 0;
    $outbound = 0;

    $stmtIn = $conn->prepare(
        "SELECT COUNT(*) AS c
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME = ?"
    );
    if ($stmtIn) {
        $stmtIn->bind_param('s', $table);
        $stmtIn->execute();
        $res = $stmtIn->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $inbound = (int) ($row['c'] ?? 0);
        $stmtIn->close();
    }

    $stmtOut = $conn->prepare(
        "SELECT COUNT(*) AS c
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND REFERENCED_TABLE_NAME IS NOT NULL"
    );
    if ($stmtOut) {
        $stmtOut->bind_param('s', $table);
        $stmtOut->execute();
        $res = $stmtOut->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $outbound = (int) ($row['c'] ?? 0);
        $stmtOut->close();
    }

    return ['inbound' => $inbound, 'outbound' => $outbound];
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

$defaultCandidates = [
    'admin_users',
    'ai_chat_credits',
    'app_settings',
    'assessments',
    'attendance_attachments',
    'class_attendance',
    'class_grades',
    'class_marks',
    'class_record_assignments',
    'co_teachers',
    'component_categories',
    'grades',
    'quiz_scores',
    'student_activity',
    'student_devices',
    'student_grades',
    'student_import_logs',
    'student_total_grades',
    'term_grades',
    'test_grading_components',
    'uploaded_files',
    'users2',
];

$mode = strtolower(trim(get_opt($argv, '--mode=', 'plan')));
if (!in_array($mode, ['plan', 'deprecate', 'drop'], true)) {
    err('Invalid mode. Use --mode=plan|deprecate|drop');
    exit(1);
}

$apply = has_flag($argv, '--apply');
$force = has_flag($argv, '--force');
$backupDb = trim(get_opt($argv, '--backup-db=', ''));
if ($backupDb === '') {
    $backupDb = 'doc_ease_backup_' . date('Ymd_His');
}

$tablesArg = trim(get_opt($argv, '--tables=', ''));
$tables = $defaultCandidates;
if ($tablesArg !== '') {
    $tables = [];
    foreach (explode(',', $tablesArg) as $raw) {
        $t = trim($raw);
        if ($t !== '') $tables[] = $t;
    }
    $tables = array_values(array_unique($tables));
}

if (has_flag($argv, '--help')) {
    out('Usage: php tools/migrate_deprecate_unused_tables.php [--mode=plan|deprecate|drop] [--apply] [--backup-db=NAME] [--tables=a,b,c] [--force]');
    exit(0);
}

out('Unused Table Cleanup');
out('Mode: ' . $mode);
out('Apply: ' . ($apply ? 'yes' : 'no'));
out('Force FK operations: ' . ($force ? 'yes' : 'no'));
out('Backup DB: ' . $backupDb);
out('');

$results = [];
foreach ($tables as $table) {
    if (!table_exists($conn, $table)) {
        $results[] = ['table' => $table, 'status' => 'missing', 'rows' => 0, 'inbound_fk' => 0, 'outbound_fk' => 0];
        continue;
    }
    $rows = table_rows_estimate($conn, $table);
    $fk = fk_counts($conn, $table);
    $results[] = [
        'table' => $table,
        'status' => 'present',
        'rows' => $rows,
        'inbound_fk' => (int) ($fk['inbound'] ?? 0),
        'outbound_fk' => (int) ($fk['outbound'] ?? 0),
    ];
}

foreach ($results as $r) {
    out(sprintf(
        '%-28s status=%-8s rows=%-6d fk(in=%d,out=%d)',
        $r['table'],
        $r['status'],
        (int) $r['rows'],
        (int) $r['inbound_fk'],
        (int) $r['outbound_fk']
    ));
}

if ($mode === 'plan') {
    out('');
    out('Plan mode complete. No changes were made.');
    exit(0);
}

if (!$apply) {
    out('');
    out('Preview complete. Re-run with --apply to execute backup and ' . $mode . '.');
    exit(0);
}

$conn->query('CREATE DATABASE IF NOT EXISTS ' . qname($backupDb) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$renamed = 0;
$dropped = 0;
$skipped = 0;
$backedUp = 0;
$suffix = date('Ymd_His');

foreach ($results as $r) {
    if ($r['status'] !== 'present') continue;
    $table = (string) $r['table'];
    $inFk = (int) $r['inbound_fk'];
    $outFk = (int) $r['outbound_fk'];

    if (!$force && ($inFk > 0 || $outFk > 0)) {
        out("SKIP {$table}: has FK dependencies (in={$inFk}, out={$outFk}). Use --force to override.");
        $skipped++;
        continue;
    }

    if (backup_table($conn, $backupDb, $table)) {
        $backedUp++;
    } else {
        out("SKIP {$table}: backup failed or table missing.");
        $skipped++;
        continue;
    }

    if ($mode === 'deprecate') {
        $target = $table . '__deprecated_' . $suffix;
        if (strlen($target) > 64) {
            $target = substr($table, 0, 24) . '__dep_' . date('ymdHis');
        }
        $conn->query('RENAME TABLE ' . qname($table) . ' TO ' . qname($target));
        out("RENAMED {$table} -> {$target}");
        $renamed++;
    } elseif ($mode === 'drop') {
        $conn->query('DROP TABLE ' . qname($table));
        out("DROPPED {$table}");
        $dropped++;
    }
}

out('');
out('Summary:');
out('  Backed up: ' . $backedUp);
out('  Renamed: ' . $renamed);
out('  Dropped: ' . $dropped);
out('  Skipped: ' . $skipped);
out('Done.');
