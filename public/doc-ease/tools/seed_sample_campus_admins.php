<?php
declare(strict_types=1);

require __DIR__ . '/../config/db.php';

if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "Database connection is not available.\n");
    exit(1);
}

function slug_code(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');
    return $value !== '' ? $value : 'campus';
}

function unique_username(mysqli $conn, string $base): string
{
    $candidate = $base;
    $suffix = 1;
    while (true) {
        $stmt = $conn->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
        if (!$stmt) return $candidate;
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = ($res && $res->num_rows === 1);
        $stmt->close();
        if (!$exists) return $candidate;
        $suffix++;
        $candidate = $base . $suffix;
    }
}

function unique_email(mysqli $conn, string $base): string
{
    $candidate = $base;
    $suffix = 1;
    while (true) {
        $stmt = $conn->prepare("SELECT 1 FROM users WHERE useremail = ? LIMIT 1");
        if (!$stmt) return $candidate;
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $res = $stmt->get_result();
        $exists = ($res && $res->num_rows === 1);
        $stmt->close();
        if (!$exists) return $candidate;
        $suffix++;
        $candidate = preg_replace('/@/', $suffix . '@', $base, 1) ?? ($base . $suffix);
    }
}

$campuses = [];
$campusRes = $conn->query("SELECT id, campus_code, campus_name FROM campuses ORDER BY id ASC");
if ($campusRes) {
    while ($row = $campusRes->fetch_assoc()) {
        $campuses[] = $row;
    }
}

if (count($campuses) === 0) {
    fwrite(STDOUT, "No campuses found. Nothing to seed.\n");
    exit(0);
}

$defaultPassword = 'Admin123!';
$created = [];
$skipped = [];

foreach ($campuses as $campus) {
    $campusId = (int) ($campus['id'] ?? 0);
    $campusCode = (string) ($campus['campus_code'] ?? '');
    $campusName = (string) ($campus['campus_name'] ?? '');
    if ($campusId <= 0) continue;

    $checkStmt = $conn->prepare(
        "SELECT id, username, useremail
         FROM users
         WHERE role = 'admin'
           AND is_superadmin = 0
           AND campus_id = ?
         ORDER BY id ASC
         LIMIT 1"
    );
    if ($checkStmt) {
        $checkStmt->bind_param('i', $campusId);
        $checkStmt->execute();
        $existing = $checkStmt->get_result();
        if ($existing && $existing->num_rows === 1) {
            $row = $existing->fetch_assoc();
            $skipped[] = [
                'campus_id' => $campusId,
                'campus_code' => $campusCode,
                'campus_name' => $campusName,
                'user_id' => (int) ($row['id'] ?? 0),
                'username' => (string) ($row['username'] ?? ''),
                'useremail' => (string) ($row['useremail'] ?? ''),
            ];
            $checkStmt->close();
            continue;
        }
        $checkStmt->close();
    }

    $base = 'campus_admin_' . slug_code($campusCode !== '' ? $campusCode : (string) $campusId);
    $username = unique_username($conn, $base);
    $emailBase = $username . '@sample.local';
    $useremail = unique_email($conn, $emailBase);
    $firstName = substr(trim($campusCode) !== '' ? strtoupper($campusCode) : 'Campus', 0, 50);
    $lastName = 'Admin';
    $passwordHash = password_hash($defaultPassword, PASSWORD_BCRYPT);

    $insert = $conn->prepare(
        "INSERT INTO users
            (username, useremail, email, password, role, is_active, first_name, last_name, role_id, status, must_change_password, is_superadmin, campus_id)
         VALUES
            (?, ?, ?, ?, 'admin', 1, ?, ?, 1, 'active', 0, 0, ?)"
    );
    if (!$insert) {
        fwrite(STDERR, "Failed to prepare insert for campus {$campusId}.\n");
        continue;
    }

    $insert->bind_param('ssssssi', $username, $useremail, $useremail, $passwordHash, $firstName, $lastName, $campusId);
    $ok = $insert->execute();
    $newId = $ok ? (int) $conn->insert_id : 0;
    $insert->close();

    if ($ok && $newId > 0) {
        $created[] = [
            'campus_id' => $campusId,
            'campus_code' => $campusCode,
            'campus_name' => $campusName,
            'user_id' => $newId,
            'username' => $username,
            'useremail' => $useremail,
            'password' => $defaultPassword,
        ];
    } else {
        fwrite(STDERR, "Failed to create admin for campus {$campusId}.\n");
    }
}

fwrite(STDOUT, "Created campus admins: " . count($created) . "\n");
foreach ($created as $row) {
    fwrite(
        STDOUT,
        implode(
            ' | ',
            [
                'campus=' . $row['campus_code'] . ' (' . $row['campus_name'] . ')',
                'user_id=' . $row['user_id'],
                'username=' . $row['username'],
                'email=' . $row['useremail'],
                'password=' . $row['password'],
            ]
        ) . "\n"
    );
}

fwrite(STDOUT, "Skipped campuses with existing admins: " . count($skipped) . "\n");
foreach ($skipped as $row) {
    fwrite(
        STDOUT,
        implode(
            ' | ',
            [
                'campus=' . $row['campus_code'] . ' (' . $row['campus_name'] . ')',
                'existing_user_id=' . $row['user_id'],
                'username=' . $row['username'],
                'email=' . $row['useremail'],
            ]
        ) . "\n"
    );
}

