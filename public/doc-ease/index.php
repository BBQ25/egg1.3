<?php include 'layouts/session.php'; ?>
<?php require_any_role(['admin', 'teacher', 'student']); ?>
<?php include 'layouts/main.php'; ?>

<?php
if (!function_exists('idx_query_row')) {
    function idx_query_row(mysqli $conn, $sql) {
        try {
            $res = $conn->query($sql);
        } catch (Throwable $e) {
            return [];
        }
        if (!$res || $res->num_rows < 1) return [];
        $row = $res->fetch_assoc();
        return is_array($row) ? $row : [];
    }
}

if (!function_exists('idx_query_rows')) {
    function idx_query_rows(mysqli $conn, $sql) {
        try {
            $res = $conn->query($sql);
        } catch (Throwable $e) {
            return [];
        }
        if (!$res) return [];
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        return $rows;
    }
}

if (!function_exists('idx_format_bytes')) {
    function idx_format_bytes($bytes, $precision = 2) {
        $bytes = (float) $bytes;
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        if ($power < 0) $power = 0;
        if ($power >= count($units)) $power = count($units) - 1;
        return number_format($bytes / pow(1024, $power), $precision) . ' ' . $units[$power];
    }
}

if (!function_exists('idx_delta_meta')) {
    function idx_delta_meta($delta) {
        $delta = (float) $delta;
        if ($delta > 0) return ['class' => 'bg-success', 'icon' => 'ri-arrow-up-line'];
        if ($delta < 0) return ['class' => 'bg-danger', 'icon' => 'ri-arrow-down-line'];
        return ['class' => 'bg-secondary', 'icon' => 'ri-subtract-line'];
    }
}

if (!function_exists('idx_percent')) {
    function idx_percent($part, $whole, $precision = 0) {
        $whole = (float) $whole;
        if ($whole <= 0) return 0.0;
        return round((((float) $part) / $whole) * 100, $precision);
    }
}

if (!function_exists('idx_donut_series')) {
    function idx_donut_series($part, $whole) {
        $part = max(0.0, (float) $part);
        $whole = max(0.0, (float) $whole);
        if ($whole <= 0.0) return [0, 100];
        $rest = max(0.0, $whole - $part);
        if ($part <= 0.0 && $rest <= 0.0) return [0, 100];
        return [round($part, 2), round($rest, 2)];
    }
}

if (!function_exists('idx_file_href')) {
    function idx_file_href($path) {
        $path = trim((string) $path);
        if ($path === '') return '';
        $path = str_replace('\\', '/', $path);
        while (strpos($path, '../') === 0) {
            $path = substr($path, 3);
        }
        if (preg_match('/^[a-zA-Z]+:\/\//', $path)) return $path;
        if (preg_match('/^[a-zA-Z]:\//', $path)) return '';
        return $path;
    }
}

$dashboardSources = [];
$sourceTotals = [];
$recentFiles = [];
$dailyCounts = [];
$dailyBytes = [];
$mimeTotals = [];
$mimeLast30 = [];
$mimePrev30 = [];
$uploadsLast30 = 0;
$uploadsPrev30 = 0;
$bytesLast30 = 0;
$bytesPrev30 = 0;
$uploadsLast7 = 0;
$uploadsPrev7 = 0;
$bytesLast7 = 0;
$bytesPrev7 = 0;
$totalBytes = 0;
$downloadsTotal = 0;
$downloadsLast30 = 0;
$downloadsPrev30 = 0;
$mapMarkers = [];
$dashboardDateNow = date('Y-m-d H:i:s');

if (session_table_exists($conn, 'uploaded_files')) {
    $dashboardSources[] = [
        'key' => 'uploaded_files',
        'label' => 'Uploaded Files',
        'metrics_sql' =>
            "SELECT
                COUNT(*) AS total_count,
                COALESCE(SUM(file_size), 0) AS total_bytes,
                COALESCE(SUM(CASE WHEN COALESCE(upload_date, created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS last30_count,
                COALESCE(SUM(CASE WHEN COALESCE(upload_date, created_at) >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND COALESCE(upload_date, created_at) < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS prev30_count,
                COALESCE(SUM(CASE WHEN COALESCE(upload_date, created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN file_size ELSE 0 END), 0) AS last30_bytes,
                COALESCE(SUM(CASE WHEN COALESCE(upload_date, created_at) >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND COALESCE(upload_date, created_at) < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN file_size ELSE 0 END), 0) AS prev30_bytes,
                COALESCE(SUM(CASE WHEN COALESCE(upload_date, created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS last7_count,
                COALESCE(SUM(CASE WHEN COALESCE(upload_date, created_at) >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND COALESCE(upload_date, created_at) < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS prev7_count,
                COALESCE(SUM(CASE WHEN COALESCE(upload_date, created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN file_size ELSE 0 END), 0) AS last7_bytes,
                COALESCE(SUM(CASE WHEN COALESCE(upload_date, created_at) >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND COALESCE(upload_date, created_at) < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN file_size ELSE 0 END), 0) AS prev7_bytes
             FROM uploaded_files
             WHERE (is_deleted = 0 OR is_deleted IS NULL)",
        'daily_sql' =>
            "SELECT DATE(COALESCE(upload_date, created_at)) AS day_key, COUNT(*) AS day_count, COALESCE(SUM(file_size), 0) AS day_bytes
             FROM uploaded_files
             WHERE (is_deleted = 0 OR is_deleted IS NULL)
               AND COALESCE(upload_date, created_at) >= DATE_SUB(CURDATE(), INTERVAL 11 DAY)
             GROUP BY DATE(COALESCE(upload_date, created_at))",
        'types_sql' =>
            "SELECT LOWER(TRIM(file_type)) AS mime_type,
                    COUNT(*) AS total_count,
                    COALESCE(SUM(CASE WHEN COALESCE(upload_date, created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS last30_count,
                    COALESCE(SUM(CASE WHEN COALESCE(upload_date, created_at) >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND COALESCE(upload_date, created_at) < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS prev30_count
             FROM uploaded_files
             WHERE (is_deleted = 0 OR is_deleted IS NULL)
               AND file_type IS NOT NULL
               AND TRIM(file_type) <> ''
             GROUP BY LOWER(TRIM(file_type))",
        'recent_sql' =>
            "SELECT id AS item_id,
                    COALESCE(NULLIF(original_name, ''), file_name) AS file_name,
                    COALESCE(NULLIF(file_type, ''), 'unknown') AS mime_type,
                    COALESCE(file_size, 0) AS file_size,
                    COALESCE(upload_date, created_at) AS created_at,
                    COALESCE(NULLIF(StudentNo, ''), CONCAT('Record #', id)) AS owner_label,
                    file_path,
                    location_latitude,
                    location_longitude
             FROM uploaded_files
             WHERE (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY COALESCE(upload_date, created_at) DESC
             LIMIT 20",
    ];

    $downloadRow = idx_query_row(
        $conn,
        "SELECT
            COALESCE(SUM(download_count), 0) AS total_downloads,
            COALESCE(SUM(CASE WHEN last_download_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN download_count ELSE 0 END), 0) AS last30_downloads,
            COALESCE(SUM(CASE WHEN last_download_date >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND last_download_date < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN download_count ELSE 0 END), 0) AS prev30_downloads
         FROM uploaded_files
         WHERE (is_deleted = 0 OR is_deleted IS NULL)"
    );
    $downloadsTotal = (int) ($downloadRow['total_downloads'] ?? 0);
    $downloadsLast30 = (int) ($downloadRow['last30_downloads'] ?? 0);
    $downloadsPrev30 = (int) ($downloadRow['prev30_downloads'] ?? 0);
}

if (session_table_exists($conn, 'attendance_attachments')) {
    $dashboardSources[] = [
        'key' => 'attendance_attachments',
        'label' => 'Attendance Attachments',
        'metrics_sql' =>
            "SELECT
                COUNT(*) AS total_count,
                COALESCE(SUM(file_size), 0) AS total_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS last30_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS prev30_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN file_size ELSE 0 END), 0) AS last30_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN file_size ELSE 0 END), 0) AS prev30_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS last7_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS prev7_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN file_size ELSE 0 END), 0) AS last7_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN file_size ELSE 0 END), 0) AS prev7_bytes
             FROM attendance_attachments",
        'daily_sql' =>
            "SELECT DATE(created_at) AS day_key, COUNT(*) AS day_count, COALESCE(SUM(file_size), 0) AS day_bytes
             FROM attendance_attachments
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 11 DAY)
             GROUP BY DATE(created_at)",
        'types_sql' =>
            "SELECT LOWER(TRIM(mime_type)) AS mime_type,
                    COUNT(*) AS total_count,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS last30_count,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS prev30_count
             FROM attendance_attachments
             WHERE mime_type IS NOT NULL
               AND TRIM(mime_type) <> ''
             GROUP BY LOWER(TRIM(mime_type))",
        'recent_sql' =>
            "SELECT aa.id AS item_id,
                    COALESCE(NULLIF(aa.original_name, ''), aa.file_name) AS file_name,
                    COALESCE(NULLIF(aa.mime_type, ''), 'unknown') AS mime_type,
                    COALESCE(aa.file_size, 0) AS file_size,
                    aa.created_at,
                    COALESCE(NULLIF(TRIM(CONCAT(st.Surname, ', ', st.FirstName)), ', '), CONCAT('Student #', aa.student_id)) AS owner_label,
                    aa.file_path,
                    NULL AS location_latitude,
                    NULL AS location_longitude
             FROM attendance_attachments aa
             LEFT JOIN students st ON st.id = aa.student_id
             ORDER BY aa.created_at DESC
             LIMIT 20",
    ];
}

if (session_table_exists($conn, 'learning_material_files')) {
    $dashboardSources[] = [
        'key' => 'learning_material_files',
        'label' => 'Learning Material Files',
        'metrics_sql' =>
            "SELECT
                COUNT(*) AS total_count,
                COALESCE(SUM(file_size), 0) AS total_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS last30_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS prev30_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN file_size ELSE 0 END), 0) AS last30_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN file_size ELSE 0 END), 0) AS prev30_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS last7_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS prev7_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN file_size ELSE 0 END), 0) AS last7_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN file_size ELSE 0 END), 0) AS prev7_bytes
             FROM learning_material_files",
        'daily_sql' =>
            "SELECT DATE(created_at) AS day_key, COUNT(*) AS day_count, COALESCE(SUM(file_size), 0) AS day_bytes
             FROM learning_material_files
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 11 DAY)
             GROUP BY DATE(created_at)",
        'types_sql' =>
            "SELECT LOWER(TRIM(mime_type)) AS mime_type,
                    COUNT(*) AS total_count,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS last30_count,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS prev30_count
             FROM learning_material_files
             WHERE mime_type IS NOT NULL
               AND TRIM(mime_type) <> ''
             GROUP BY LOWER(TRIM(mime_type))",
        'recent_sql' =>
            "SELECT lmf.id AS item_id,
                    COALESCE(NULLIF(lmf.original_name, ''), lmf.file_name) AS file_name,
                    COALESCE(NULLIF(lmf.mime_type, ''), 'unknown') AS mime_type,
                    COALESCE(lmf.file_size, 0) AS file_size,
                    lmf.created_at,
                    COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.username, CONCAT('User #', lmf.uploaded_by)) AS owner_label,
                    lmf.file_path,
                    NULL AS location_latitude,
                    NULL AS location_longitude
             FROM learning_material_files lmf
             LEFT JOIN users u ON u.id = lmf.uploaded_by
             ORDER BY lmf.created_at DESC
             LIMIT 20",
    ];
}

if (session_table_exists($conn, 'grading_assignment_submission_files')) {
    $dashboardSources[] = [
        'key' => 'grading_assignment_submission_files',
        'label' => 'Assignment Submission Files',
        'metrics_sql' =>
            "SELECT
                COUNT(*) AS total_count,
                COALESCE(SUM(file_size), 0) AS total_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS last30_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS prev30_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN file_size ELSE 0 END), 0) AS last30_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN file_size ELSE 0 END), 0) AS prev30_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS last7_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS prev7_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN file_size ELSE 0 END), 0) AS last7_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN file_size ELSE 0 END), 0) AS prev7_bytes
             FROM grading_assignment_submission_files",
        'daily_sql' =>
            "SELECT DATE(created_at) AS day_key, COUNT(*) AS day_count, COALESCE(SUM(file_size), 0) AS day_bytes
             FROM grading_assignment_submission_files
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 11 DAY)
             GROUP BY DATE(created_at)",
        'types_sql' =>
            "SELECT LOWER(TRIM(mime_type)) AS mime_type,
                    COUNT(*) AS total_count,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS last30_count,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS prev30_count
             FROM grading_assignment_submission_files
             WHERE mime_type IS NOT NULL
               AND TRIM(mime_type) <> ''
             GROUP BY LOWER(TRIM(mime_type))",
        'recent_sql' =>
            "SELECT gaf.id AS item_id,
                    COALESCE(NULLIF(gaf.original_name, ''), gaf.file_name) AS file_name,
                    COALESCE(NULLIF(gaf.mime_type, ''), 'unknown') AS mime_type,
                    COALESCE(gaf.file_size, 0) AS file_size,
                    gaf.created_at,
                    COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.username, CONCAT(UCASE(gaf.uploaded_by_role), ' #', gaf.uploaded_by)) AS owner_label,
                    gaf.file_path,
                    NULL AS location_latitude,
                    NULL AS location_longitude
             FROM grading_assignment_submission_files gaf
             LEFT JOIN users u ON u.id = gaf.uploaded_by
             ORDER BY gaf.created_at DESC
             LIMIT 20",
    ];
}

if (session_table_exists($conn, 'accomplishment_proofs') && session_table_exists($conn, 'accomplishment_entries')) {
    $dashboardSources[] = [
        'key' => 'accomplishment_proofs',
        'label' => 'Accomplishment Proofs',
        'metrics_sql' =>
            "SELECT
                COUNT(*) AS total_count,
                COALESCE(SUM(file_size), 0) AS total_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS last30_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS prev30_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN file_size ELSE 0 END), 0) AS last30_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN file_size ELSE 0 END), 0) AS prev30_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS last7_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS prev7_count,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN file_size ELSE 0 END), 0) AS last7_bytes,
                COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN file_size ELSE 0 END), 0) AS prev7_bytes
             FROM accomplishment_proofs",
        'daily_sql' =>
            "SELECT DATE(created_at) AS day_key, COUNT(*) AS day_count, COALESCE(SUM(file_size), 0) AS day_bytes
             FROM accomplishment_proofs
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 11 DAY)
             GROUP BY DATE(created_at)",
        'types_sql' =>
            "SELECT LOWER(TRIM(mime_type)) AS mime_type,
                    COUNT(*) AS total_count,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS last30_count,
                    COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS prev30_count
             FROM accomplishment_proofs
             WHERE mime_type IS NOT NULL
               AND TRIM(mime_type) <> ''
             GROUP BY LOWER(TRIM(mime_type))",
        'recent_sql' =>
            "SELECT ap.id AS item_id,
                    COALESCE(NULLIF(ap.original_name, ''), ap.file_name) AS file_name,
                    COALESCE(NULLIF(ap.mime_type, ''), 'unknown') AS mime_type,
                    COALESCE(ap.file_size, 0) AS file_size,
                    ap.created_at,
                    COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.username, CONCAT('Entry #', ap.entry_id)) AS owner_label,
                    ap.file_path,
                    NULL AS location_latitude,
                    NULL AS location_longitude
             FROM accomplishment_proofs ap
             LEFT JOIN accomplishment_entries ae ON ae.id = ap.entry_id
             LEFT JOIN users u ON u.id = ae.user_id
             ORDER BY ap.created_at DESC
             LIMIT 20",
    ];
}

foreach ($dashboardSources as $source) {
    $sourceLabel = (string) ($source['label'] ?? 'Source');
    $sourceTotals[$sourceLabel] = 0;

    $metrics = idx_query_row($conn, (string) $source['metrics_sql']);
    $sourceTotals[$sourceLabel] = (int) ($metrics['total_count'] ?? 0);
    $totalBytes += (int) ($metrics['total_bytes'] ?? 0);
    $uploadsLast30 += (int) ($metrics['last30_count'] ?? 0);
    $uploadsPrev30 += (int) ($metrics['prev30_count'] ?? 0);
    $bytesLast30 += (int) ($metrics['last30_bytes'] ?? 0);
    $bytesPrev30 += (int) ($metrics['prev30_bytes'] ?? 0);
    $uploadsLast7 += (int) ($metrics['last7_count'] ?? 0);
    $uploadsPrev7 += (int) ($metrics['prev7_count'] ?? 0);
    $bytesLast7 += (int) ($metrics['last7_bytes'] ?? 0);
    $bytesPrev7 += (int) ($metrics['prev7_bytes'] ?? 0);

    $dayRows = idx_query_rows($conn, (string) $source['daily_sql']);
    foreach ($dayRows as $row) {
        $dayKey = trim((string) ($row['day_key'] ?? ''));
        if ($dayKey === '') continue;
        if (!isset($dailyCounts[$dayKey])) $dailyCounts[$dayKey] = 0;
        if (!isset($dailyBytes[$dayKey])) $dailyBytes[$dayKey] = 0;
        $dailyCounts[$dayKey] += (int) ($row['day_count'] ?? 0);
        $dailyBytes[$dayKey] += (int) ($row['day_bytes'] ?? 0);
    }

    $mimeRows = idx_query_rows($conn, (string) $source['types_sql']);
    foreach ($mimeRows as $row) {
        $mimeType = strtolower(trim((string) ($row['mime_type'] ?? 'unknown')));
        if ($mimeType === '') $mimeType = 'unknown';
        if (!isset($mimeTotals[$mimeType])) $mimeTotals[$mimeType] = 0;
        if (!isset($mimeLast30[$mimeType])) $mimeLast30[$mimeType] = 0;
        if (!isset($mimePrev30[$mimeType])) $mimePrev30[$mimeType] = 0;
        $mimeTotals[$mimeType] += (int) ($row['total_count'] ?? 0);
        $mimeLast30[$mimeType] += (int) ($row['last30_count'] ?? 0);
        $mimePrev30[$mimeType] += (int) ($row['prev30_count'] ?? 0);
    }

    $recentRows = idx_query_rows($conn, (string) $source['recent_sql']);
    foreach ($recentRows as $row) {
        $createdAt = trim((string) ($row['created_at'] ?? ''));
        $recentFiles[] = [
            'source_label' => $sourceLabel,
            'item_id' => (int) ($row['item_id'] ?? 0),
            'file_name' => trim((string) ($row['file_name'] ?? '')),
            'mime_type' => trim((string) ($row['mime_type'] ?? 'unknown')),
            'file_size' => (int) ($row['file_size'] ?? 0),
            'created_at' => $createdAt,
            'created_ts' => ($createdAt !== '' && strtotime($createdAt) !== false) ? (int) strtotime($createdAt) : 0,
            'owner_label' => trim((string) ($row['owner_label'] ?? '')),
            'file_path' => trim((string) ($row['file_path'] ?? '')),
            'location_latitude' => $row['location_latitude'] ?? null,
            'location_longitude' => $row['location_longitude'] ?? null,
        ];
    }
}

$totalFiles = array_sum($sourceTotals);

usort($recentFiles, function ($a, $b) {
    return ((int) ($b['created_ts'] ?? 0)) <=> ((int) ($a['created_ts'] ?? 0));
});
$recentFiles = array_slice($recentFiles, 0, 8);

$fileTypesTotal = count($mimeTotals);
$fileTypesLast30 = 0;
$fileTypesPrev30 = 0;
foreach ($mimeLast30 as $v) { if ((int) $v > 0) $fileTypesLast30++; }
foreach ($mimePrev30 as $v) { if ((int) $v > 0) $fileTypesPrev30++; }

$contributorsLast30 = 0;
$contributorsPrev30 = 0;
foreach ($recentFiles as $fileRow) {
    $ts = (int) ($fileRow['created_ts'] ?? 0);
    if ($ts <= 0) continue;
    if ($ts >= strtotime('-30 days')) $contributorsLast30++;
    if ($ts < strtotime('-30 days') && $ts >= strtotime('-60 days')) $contributorsPrev30++;
}
$contributorsDelta = $contributorsLast30 - $contributorsPrev30;

$totalFilesDelta = $uploadsLast30 - $uploadsPrev30;
$recentUploadsDelta = $uploadsLast30 - $uploadsPrev30;
$storageDeltaBytes = $bytesLast30 - $bytesPrev30;
$fileTypesDelta = $fileTypesLast30 - $fileTypesPrev30;
$downloadsDelta = $downloadsLast30 - $downloadsPrev30;
$recentSharePercent = idx_percent($uploadsLast30, max(1, $totalFiles), 0);
$currentWeekGrowthPercent = ($uploadsPrev7 > 0) ? round((($uploadsLast7 - $uploadsPrev7) / $uploadsPrev7) * 100, 2) : (($uploadsLast7 > 0) ? 100 : 0);
$storageWeekGrowthPercent = ($bytesPrev7 > 0) ? round((($bytesLast7 - $bytesPrev7) / $bytesPrev7) * 100, 2) : (($bytesLast7 > 0) ? 100 : 0);

$chartLabels = [];
$chartUploadCounts = [];
$chartUploadSizesMb = [];
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime('-' . $i . ' days');
    $dayKey = date('Y-m-d', $ts);
    $chartLabels[] = $dayKey;
    $chartUploadCounts[] = (int) ($dailyCounts[$dayKey] ?? 0);
    $chartUploadSizesMb[] = round(((int) ($dailyBytes[$dayKey] ?? 0)) / 1048576, 2);
}

$topMimeRows = [];
foreach ($mimeTotals as $mime => $count) {
    $topMimeRows[] = ['label' => ($mime === 'unknown' ? 'Unknown Type' : $mime), 'count' => (int) $count];
}
usort($topMimeRows, function ($a, $b) { return ((int) $b['count']) <=> ((int) $a['count']); });
$topMimeRows = array_slice($topMimeRows, 0, 3);

$sourceDistributionRows = [];
foreach ($sourceTotals as $label => $count) {
    if ((int) $count <= 0) continue;
    $sourceDistributionRows[] = [
        'label' => (string) $label,
        'count' => (int) $count,
        'percent' => idx_percent((int) $count, max(1, $totalFiles), 0),
    ];
}
usort($sourceDistributionRows, function ($a, $b) { return ((int) $b['count']) <=> ((int) $a['count']); });
$topSourceRows = array_slice($sourceDistributionRows, 0, 3);
$countryChartRows = array_slice($sourceDistributionRows, 0, 10);

if (session_table_exists($conn, 'campus_attendance_geofence_settings') && session_table_exists($conn, 'campuses')) {
    $geoRows = idx_query_rows(
        $conn,
        "SELECT c.campus_name, g.center_latitude, g.center_longitude, g.radius_meters
         FROM campus_attendance_geofence_settings g
         JOIN campuses c ON c.id = g.campus_id
         WHERE g.center_latitude IS NOT NULL
           AND g.center_longitude IS NOT NULL
         ORDER BY c.campus_name ASC"
    );
    foreach ($geoRows as $row) {
        $lat = isset($row['center_latitude']) ? (float) $row['center_latitude'] : 0.0;
        $lng = isset($row['center_longitude']) ? (float) $row['center_longitude'] : 0.0;
        if (abs($lat) < 0.000001 && abs($lng) < 0.000001) continue;
        $name = trim((string) ($row['campus_name'] ?? 'Campus'));
        $radius = (int) ($row['radius_meters'] ?? 0);
        if ($radius > 0) $name .= ' (' . number_format($radius) . 'm)';
        $mapMarkers[] = ['latLng' => [$lat, $lng], 'name' => $name];
    }
}

if (count($mapMarkers) === 0 && session_table_exists($conn, 'uploaded_files')) {
    $locationRows = idx_query_rows(
        $conn,
        "SELECT location_latitude, location_longitude, StudentNo
         FROM uploaded_files
         WHERE (is_deleted = 0 OR is_deleted IS NULL)
           AND location_latitude IS NOT NULL
           AND location_longitude IS NOT NULL
         ORDER BY COALESCE(upload_date, created_at) DESC
         LIMIT 10"
    );
    foreach ($locationRows as $row) {
        $lat = isset($row['location_latitude']) ? (float) $row['location_latitude'] : 0.0;
        $lng = isset($row['location_longitude']) ? (float) $row['location_longitude'] : 0.0;
        if (abs($lat) < 0.000001 && abs($lng) < 0.000001) continue;
        $name = trim((string) ($row['StudentNo'] ?? 'Upload'));
        if ($name === '') $name = 'Upload';
        $mapMarkers[] = ['latLng' => [$lat, $lng], 'name' => $name];
    }
}

$hasMapMarkers = count($mapMarkers) > 0;

$dashboardJsData = [
    'widgets' => [
        'totalFiles' => idx_donut_series($uploadsLast30, max(1, $totalFiles)),
        'recentUploads' => idx_donut_series($uploadsLast30, max(1, $uploadsLast30 + $uploadsPrev30)),
        'storage' => idx_donut_series($bytesLast30, max(1, $totalBytes)),
        'fileTypes' => idx_donut_series($fileTypesLast30, max(1, $fileTypesTotal)),
        'downloads' => idx_donut_series($downloadsLast30, max(1, $downloadsTotal)),
    ],
    'timeline' => [
        'labels' => $chartLabels,
        'uploads' => $chartUploadCounts,
        'size_mb' => $chartUploadSizesMb,
    ],
    'recentUploadSharePercent' => (float) $recentSharePercent,
    'countryChart' => [
        'categories' => array_values(array_map(function ($row) { return (string) ($row['label'] ?? ''); }, $countryChartRows)),
        'values' => array_values(array_map(function ($row) { return (float) ($row['percent'] ?? 0); }, $countryChartRows)),
    ],
    'mapMarkers' => $mapMarkers,
];

$totalFilesDeltaMeta = idx_delta_meta($totalFilesDelta);
$recentUploadsDeltaMeta = idx_delta_meta($recentUploadsDelta);
$storageDeltaMeta = idx_delta_meta($storageDeltaBytes);
$fileTypesDeltaMeta = idx_delta_meta($fileTypesDelta);
$downloadsDeltaMeta = idx_delta_meta($downloadsDelta);
$weekUploadsDeltaMeta = idx_delta_meta($uploadsLast7 - $uploadsPrev7);
$weekStorageDeltaMeta = idx_delta_meta($bytesLast7 - $bytesPrev7);
$contributorsDeltaMeta = idx_delta_meta($contributorsDelta);
?>

<head>

    <title>Dashboard | E-Record</title>
    <?php include 'layouts/title-meta.php'; ?>

    <!-- Daterangepicker css -->
    <link rel="stylesheet" href="assets/vendor/daterangepicker/daterangepicker.css">

    <!-- Vector Map css -->
    <link rel="stylesheet" href="assets/vendor/admin-resources/jquery.vectormap/jquery-jvectormap-1.2.2.css">

    <?php include 'layouts/head-css.php'; ?>
</head>

<body>
    <!-- Begin page -->
    <div class="wrapper">

        <?php include 'layouts/menu.php';?>

        <!-- ============================================================== -->
        <!-- Start Page Content here -->
        <!-- ============================================================== -->

        <div class="content-page">
            <div class="content">

                <!-- Start Content-->
                <div class="container-fluid">

                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box">
                                <div class="page-title-right">
                                    <form class="d-flex">
                                        <div class="input-group">
                                            <input type="text" class="form-control shadow border-0" id="dash-daterange">
                                            <span class="input-group-text bg-primary border-primary text-white">
                                                <i class="ri-calendar-todo-fill fs-13"></i>
                                            </span>
                                        </div>
                                        <a href="index.php" class="btn btn-primary ms-2">
                                            <i class="ri-refresh-line"></i>
                                        </a>
                                        <a href="apps-file-manager.php" class="btn btn-secondary ms-2">
                                            <i class="ri-folder-line"></i> File Manager
                                        </a>
                                    </form>
                                </div>
                                <h4 class="page-title">File Management Dashboard</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row row-cols-1 row-cols-xxl-5 row-cols-lg-3 row-cols-md-2">
                        <div class="col">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h5 class="text-muted fw-normal mt-0" title="Total Files">Total Files</h5>
                                            <h3 class="my-3"><?php echo number_format((int) $totalFiles); ?></h3>
                                            <p class="mb-0 text-muted text-truncate">
                                                <span class="badge <?php echo htmlspecialchars((string) $totalFilesDeltaMeta['class']); ?> me-1">
                                                    <i class="<?php echo htmlspecialchars((string) $totalFilesDeltaMeta['icon']); ?>"></i>
                                                    <?php echo number_format(abs((int) $totalFilesDelta)); ?>
                                                </span>
                                                <span>New files vs previous 30 days</span>
                                            </p>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <div id="widget-customers" class="apex-charts" data-colors="#47ad77,#e3e9ee"></div>
                                        </div>
                                    </div>
                                </div> <!-- end card-body-->
                            </div> <!-- end card-->
                        </div> <!-- end col-->

                        <div class="col">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h5 class="text-muted fw-normal mt-0" title="Recent Uploads">Recent Uploads</h5>
                                            <h3 class="my-3"><?php echo number_format((int) $uploadsLast30); ?></h3>
                                            <p class="mb-0 text-muted text-truncate">
                                                <span class="badge <?php echo htmlspecialchars((string) $recentUploadsDeltaMeta['class']); ?> me-1">
                                                    <i class="<?php echo htmlspecialchars((string) $recentUploadsDeltaMeta['icon']); ?>"></i>
                                                    <?php echo number_format(abs((int) $recentUploadsDelta)); ?>
                                                </span>
                                                <span>Compared with previous 30 days</span>
                                            </p>
                                        </div>
                                        <div id="widget-orders" class="apex-charts" data-colors="#3e60d5,#e3e9ee"></div>
                                    </div>
                                </div> <!-- end card-body-->
                            </div> <!-- end card-->
                        </div> <!-- end col-->

                        <div class="col">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h5 class="text-muted fw-normal mt-0" title="Storage Used">Storage Used</h5>
                                            <h3 class="my-3"><?php echo htmlspecialchars(idx_format_bytes((int) $totalBytes)); ?></h3>
                                            <p class="mb-0 text-muted text-truncate">
                                                <span class="badge <?php echo htmlspecialchars((string) $storageDeltaMeta['class']); ?> me-1">
                                                    <i class="<?php echo htmlspecialchars((string) $storageDeltaMeta['icon']); ?>"></i>
                                                    <?php echo htmlspecialchars(idx_format_bytes(abs((int) $storageDeltaBytes))); ?>
                                                </span>
                                                <span>Storage delta in last 30 days</span>
                                            </p>
                                        </div>
                                        <div id="widget-revenue" class="apex-charts" data-colors="#16a7e9,#e3e9ee"></div>
                                    </div>

                                </div> <!-- end card-body-->
                            </div> <!-- end card-->
                        </div> <!-- end col-->

                        <div class="col col-lg-6">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h5 class="text-muted fw-normal mt-0" title="File Types">File Types</h5>
                                            <h3 class="my-3"><?php echo number_format((int) $fileTypesTotal); ?></h3>
                                            <p class="mb-0 text-muted text-truncate">
                                                <span class="badge <?php echo htmlspecialchars((string) $fileTypesDeltaMeta['class']); ?> me-1">
                                                    <i class="<?php echo htmlspecialchars((string) $fileTypesDeltaMeta['icon']); ?>"></i>
                                                    <?php echo number_format(abs((int) $fileTypesDelta)); ?>
                                                </span>
                                                <span>Active MIME types vs previous 30 days</span>
                                            </p>
                                        </div>
                                        <div id="widget-growth" class="apex-charts" data-colors="#ffc35a,#e3e9ee"></div>
                                    </div>

                                </div> <!-- end card-body-->
                            </div> <!-- end card-->
                        </div> <!-- end col-->
                        <div class="col col-lg-6 col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div class="flex-grow-1 overflow-hidden">
                                            <h5 class="text-muted fw-normal mt-0" title="Downloads">Downloads</h5>
                                            <h3 class="my-3"><?php echo number_format((int) $downloadsTotal); ?></h3>
                                            <p class="mb-0 text-muted text-truncate">
                                                <span class="badge <?php echo htmlspecialchars((string) $downloadsDeltaMeta['class']); ?> me-1">
                                                    <i class="<?php echo htmlspecialchars((string) $downloadsDeltaMeta['icon']); ?>"></i>
                                                    <?php echo number_format(abs((int) $downloadsDelta)); ?>
                                                </span>
                                                <span>Tracked by file download history</span>
                                            </p>
                                        </div>
                                        <div id="widget-conversation" class="apex-charts" data-colors="#f15776,#e3e9ee"></div>
                                    </div>

                                </div> <!-- end card-body-->
                            </div> <!-- end card-->
                        </div> <!-- end col-->
                    </div> <!-- end row -->

                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="d-flex card-header justify-content-between align-items-center">
                                    <h4 class="header-title">File Uploads Over Time</h4>
                                    <div class="dropdown">
                                        <a href="#" class="dropdown-toggle arrow-none card-drop" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="ri-more-2-fill"></i>
                                        </a>
                                        <div class="dropdown-menu dropdown-menu-end">
                                            <!-- item-->
                                            <a href="javascript:void(0);" class="dropdown-item">Sales Report</a>
                                            <!-- item-->
                                            <a href="javascript:void(0);" class="dropdown-item">Export Report</a>
                                            <!-- item-->
                                            <a href="javascript:void(0);" class="dropdown-item">Profit</a>
                                            <!-- item-->
                                            <a href="javascript:void(0);" class="dropdown-item">Action</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <div class="bg-light-subtle border-top border-bottom border-light">
                                        <div class="row text-center">
                                            <div class="col">
                                                <p class="text-muted mt-3"><i class="ri-donut-chart-fill"></i> Current Week</p>
                                                <h3 class="fw-normal mb-3">
                                                    <span><?php echo number_format((int) $uploadsLast7); ?> uploads</span>
                                                </h3>
                                            </div>
                                            <div class="col">
                                                <p class="text-muted mt-3"><i class="ri-donut-chart-fill"></i> Previous Week</p>
                                                <h3 class="fw-normal mb-3">
                                                    <span>
                                                        <?php echo number_format((int) $uploadsPrev7); ?> uploads
                                                        <i class="<?php echo htmlspecialchars((string) $weekUploadsDeltaMeta['icon']); ?> <?php echo (($uploadsLast7 - $uploadsPrev7) >= 0) ? 'text-success' : 'text-danger'; ?>"></i>
                                                    </span>
                                                </h3>
                                            </div>
                                            <div class="col">
                                                <p class="text-muted mt-3"><i class="ri-donut-chart-fill"></i> Storage Growth</p>
                                                <h3 class="fw-normal mb-3">
                                                    <span>
                                                        <?php echo number_format((float) $storageWeekGrowthPercent, 2); ?>%
                                                        <i class="<?php echo htmlspecialchars((string) $weekStorageDeltaMeta['icon']); ?> <?php echo (($bytesLast7 - $bytesPrev7) >= 0) ? 'text-success' : 'text-danger'; ?>"></i>
                                                    </span>
                                                </h3>
                                            </div>
                                            <div class="col">
                                                <p class="text-muted mt-3"><i class="ri-donut-chart-fill"></i> Contributors</p>
                                                <h3 class="fw-normal mb-3">
                                                    <span>
                                                        <?php echo number_format((int) $contributorsLast30); ?>
                                                        <i class="<?php echo htmlspecialchars((string) $contributorsDeltaMeta['icon']); ?> <?php echo ($contributorsDelta >= 0) ? 'text-success' : 'text-danger'; ?>"></i>
                                                    </span>
                                                </h3>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body pt-0">
                                    <div dir="ltr">
                                        <div id="revenue-chart" class="apex-charts mt-3" data-colors="#3e60d5,#47ad77"></div>
                                    </div>
                                </div> <!-- end card-body-->
                            </div> <!-- end card-->
                        </div> <!-- end col-->
                        <div class="col-lg-4">
                            <div class="card">
                                <div class="d-flex card-header justify-content-between align-items-center">
                                    <h4 class="header-title">File Statistics</h4>
                                    <div class="dropdown">
                                        <a href="#" class="dropdown-toggle arrow-none card-drop" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="ri-more-2-fill"></i>
                                        </a>
                                        <div class="dropdown-menu dropdown-menu-end">
                                            <!-- item-->
                                            <a href="javascript:void(0);" class="dropdown-item">Sales Report</a>
                                            <!-- item-->
                                            <a href="javascript:void(0);" class="dropdown-item">Export Report</a>
                                            <!-- item-->
                                            <a href="javascript:void(0);" class="dropdown-item">Profit</a>
                                            <!-- item-->
                                            <a href="javascript:void(0);" class="dropdown-item">Action</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body p-0">
                                    <?php if ((int) $totalFiles === 0): ?>
                                        <div class="alert alert-warning rounded-0 mb-0 border-end-0 border-start-0" role="alert">
                                            No file records found yet in tracked tables.
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-success rounded-0 mb-0 border-end-0 border-start-0" role="alert">
                                            Live database snapshot updated: <?php echo htmlspecialchars($dashboardDateNow); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="card-body pt-0">
                                    <div id="average-sales" class="apex-charts mb-3" data-colors="#3e60d5,#47ad77,#fa5c7c,#16a7e9"></div>

                                    <?php if (count($topSourceRows) === 0): ?>
                                        <div class="text-muted small">Upload source breakdown will appear after files are stored.</div>
                                    <?php else: ?>
                                        <?php foreach ($topSourceRows as $idx => $sourceRow): ?>
                                            <?php
                                            $sourceLabel = (string) ($sourceRow['label'] ?? 'Source');
                                            $sourceCount = (int) ($sourceRow['count'] ?? 0);
                                            $sourcePercent = max(0, min(100, (float) ($sourceRow['percent'] ?? 0)));
                                            $isLast = ($idx === (count($topSourceRows) - 1));
                                            ?>
                                            <h5 class="mb-1 mt-0 fw-normal"><?php echo htmlspecialchars($sourceLabel); ?></h5>
                                            <div class="progress-w-percent<?php echo $isLast ? ' mb-0' : ''; ?>">
                                                <span class="progress-value fw-bold"><?php echo number_format($sourceCount); ?> files</span>
                                                <div class="progress progress-sm">
                                                    <div
                                                        class="progress-bar"
                                                        role="progressbar"
                                                        style="width: <?php echo htmlspecialchars((string) $sourcePercent); ?>%;"
                                                        aria-valuenow="<?php echo htmlspecialchars((string) $sourcePercent); ?>"
                                                        aria-valuemin="0"
                                                        aria-valuemax="100"
                                                    ></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div> <!-- end card-body-->
                            </div> <!-- end card-->
                        </div> <!-- end col-->

                    </div>
                    <!-- end row -->

                    <div class="row">
                        <div class="col-xl-5">
                            <div class="card">
                                <div class="d-flex card-header justify-content-between align-items-center">
                                    <h4 class="header-title">Recent Files</h4>
                                    <a href="javascript:void(0);" class="btn btn-sm btn-info">Export <i class="ri-download-line ms-1"></i></a>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-borderless table-hover table-nowrap table-centered m-0">
                                            <thead class="border-top border-bottom bg-light-subtle border-light">
                                                <tr>
                                                    <th class="py-1">File</th>
                                                    <th class="py-1">Source</th>
                                                    <th class="py-1">Owner</th>
                                                    <th class="py-1">Size</th>
                                                    <th class="py-1">Uploaded</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($recentFiles) === 0): ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center text-muted py-3">No recent files available from tracked tables.</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($recentFiles as $fileRow): ?>
                                                        <?php
                                                        $fileName = trim((string) ($fileRow['file_name'] ?? 'Unnamed File'));
                                                        if ($fileName === '') $fileName = 'Unnamed File';
                                                        $ownerLabel = trim((string) ($fileRow['owner_label'] ?? 'Unknown'));
                                                        if ($ownerLabel === '') $ownerLabel = 'Unknown';
                                                        $sourceLabel = trim((string) ($fileRow['source_label'] ?? 'Source'));
                                                        $uploadedAt = trim((string) ($fileRow['created_at'] ?? ''));
                                                        $uploadedDisplay = ($uploadedAt !== '' && strtotime($uploadedAt) !== false)
                                                            ? date('M d, Y h:i A', strtotime($uploadedAt))
                                                            : 'N/A';
                                                        $fileHref = idx_file_href($fileRow['file_path'] ?? '');
                                                        ?>
                                                        <tr>
                                                            <td class="text-truncate" style="max-width: 220px;">
                                                                <?php if ($fileHref !== ''): ?>
                                                                    <a href="<?php echo htmlspecialchars($fileHref); ?>" target="_blank" rel="noopener noreferrer">
                                                                        <?php echo htmlspecialchars($fileName); ?>
                                                                    </a>
                                                                <?php else: ?>
                                                                    <?php echo htmlspecialchars($fileName); ?>
                                                                <?php endif; ?>
                                                                <div class="text-muted small"><?php echo htmlspecialchars((string) ($fileRow['mime_type'] ?? 'unknown')); ?></div>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($sourceLabel); ?></td>
                                                            <td class="text-truncate" style="max-width: 160px;"><?php echo htmlspecialchars($ownerLabel); ?></td>
                                                            <td><?php echo htmlspecialchars(idx_format_bytes((int) ($fileRow['file_size'] ?? 0))); ?></td>
                                                            <td><?php echo htmlspecialchars($uploadedDisplay); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text-center">
                                        <a href="apps-file-manager.php" class="text-primary text-decoration-underline fw-bold btn mb-2">View All</a>
                                    </div>
                                </div>
                            </div>
                        </div> <!-- end col -->

                        <div class="col-xl-7">
                            <div class="card">
                                <div class="d-flex card-header justify-content-between align-items-center">
                                    <h4 class="header-title">File Distribution</h4>
                                    <div class="dropdown">
                                        <a href="#" class="dropdown-toggle arrow-none card-drop" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="ri-more-2-fill"></i>
                                        </a>
                                        <div class="dropdown-menu dropdown-menu-end">
                                            <!-- item-->
                                            <a href="javascript:void(0);" class="dropdown-item">Sales Report</a>
                                            <!-- item-->
                                            <a href="javascript:void(0);" class="dropdown-item">Export Report</a>
                                            <!-- item-->
                                            <a href="javascript:void(0);" class="dropdown-item">Profit</a>
                                            <!-- item-->
                                            <a href="javascript:void(0);" class="dropdown-item">Action</a>
                                        </div>
                                    </div>
                                </div>

                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-lg-8">
                                            <div id="world-map-markers" class="mt-3 mb-3" style="height: 298px">
                                            </div>
                                            <?php if (!$hasMapMarkers): ?>
                                                <div class="text-muted small">No geotagged markers found yet. Configure campus boundaries or upload files with location data.</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-lg-4" dir="ltr">
                                            <div id="country-chart" class="apex-charts" data-colors="#47ad77"></div>
                                            <?php if (count($countryChartRows) === 0): ?>
                                                <div class="text-muted small">Source distribution is empty until files are uploaded.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div> <!-- end card-->
                        </div> <!-- end col -->
                    </div>
                    <!-- end row -->

                </div>
                <!-- container -->

            </div>
            <!-- content -->

            <?php include 'layouts/footer.php'; ?>

        </div>

        <!-- ============================================================== -->
        <!-- End Page content -->
        <!-- ============================================================== -->

    </div>
    <!-- END wrapper -->

    <?php include 'layouts/right-sidebar.php'; ?>

    <?php include 'layouts/footer-scripts.php'; ?>

    <!-- Daterangepicker js -->
    <script src="assets/vendor/daterangepicker/moment.min.js"></script>
    <script src="assets/vendor/daterangepicker/daterangepicker.js"></script>

    <!-- Apex Charts js -->
    <script src="assets/vendor/apexcharts/apexcharts.min.js"></script>

    <!-- Vector Map js -->
    <script src="assets/vendor/admin-resources/jquery.vectormap/jquery-jvectormap-1.2.2.min.js"></script>
    <script src="assets/vendor/admin-resources/jquery.vectormap/maps/jquery-jvectormap-world-mill-en.js"></script>

    <script>
    (function ($) {
        'use strict';

        var dashboardData = <?php echo json_encode($dashboardJsData, JSON_UNESCAPED_SLASHES); ?>;

        function readColors(selector, fallback) {
            var el = document.querySelector(selector);
            if (!el) return fallback;
            var colors = el.getAttribute('data-colors');
            if (!colors) return fallback;
            return colors.split(',').map(function (c) { return c.trim(); });
        }

        function renderWidgetDonut(selector, series, fallbackColors) {
            var el = document.querySelector(selector);
            if (!el || !window.ApexCharts) return;
            var options = {
                chart: { height: 72, width: 72, type: 'donut' },
                legend: { show: false },
                stroke: { colors: ['transparent'] },
                plotOptions: { pie: { donut: { size: '80%' } } },
                series: Array.isArray(series) ? series : [0, 100],
                dataLabels: { enabled: false },
                colors: readColors(selector, fallbackColors)
            };
            new ApexCharts(el, options).render();
        }

        $(function () {
            if ($('#dash-daterange').length) {
                $('#dash-daterange').daterangepicker({ singleDatePicker: true });
            }

            renderWidgetDonut('#widget-customers', dashboardData.widgets.totalFiles, ['#47ad77', '#e3e9ee']);
            renderWidgetDonut('#widget-orders', dashboardData.widgets.recentUploads, ['#3e60d5', '#e3e9ee']);
            renderWidgetDonut('#widget-revenue', dashboardData.widgets.storage, ['#16a7e9', '#e3e9ee']);
            renderWidgetDonut('#widget-growth', dashboardData.widgets.fileTypes, ['#ffc35a', '#e3e9ee']);
            renderWidgetDonut('#widget-conversation', dashboardData.widgets.downloads, ['#f15776', '#e3e9ee']);

            if (window.ApexCharts && document.querySelector('#revenue-chart')) {
                new ApexCharts(document.querySelector('#revenue-chart'), {
                    series: [
                        { name: 'Uploads', type: 'column', data: dashboardData.timeline.uploads || [] },
                        { name: 'Storage (MB)', type: 'line', data: dashboardData.timeline.size_mb || [] }
                    ],
                    chart: { height: 374, type: 'line', offsetY: 10, toolbar: { show: false } },
                    stroke: { width: [2, 3] },
                    plotOptions: { bar: { columnWidth: '50%' } },
                    colors: readColors('#revenue-chart', ['#3e60d5', '#47ad77']),
                    dataLabels: { enabled: true, enabledOnSeries: [1] },
                    labels: dashboardData.timeline.labels || [],
                    xaxis: { type: 'datetime' },
                    legend: { offsetY: 7 },
                    yaxis: [
                        { title: { text: 'Uploads' } },
                        { opposite: true, title: { text: 'Storage (MB)' } }
                    ]
                }).render();
            }

            if (window.ApexCharts && document.querySelector('#average-sales')) {
                new ApexCharts(document.querySelector('#average-sales'), {
                    chart: { height: 286, type: 'radialBar' },
                    plotOptions: {
                        radialBar: {
                            startAngle: -135,
                            endAngle: 135,
                            dataLabels: {
                                name: { fontSize: '14px', offsetY: 100 },
                                value: {
                                    offsetY: 55,
                                    fontSize: '24px',
                                    formatter: function (val) { return val + '%'; }
                                }
                            },
                            track: { background: 'rgba(170,184,197,0.2)', margin: 0 }
                        }
                    },
                    stroke: { dashArray: 4 },
                    colors: readColors('#average-sales', ['#3e60d5', '#47ad77', '#fa5c7c', '#16a7e9']),
                    series: [dashboardData.recentUploadSharePercent || 0],
                    labels: ['Recent Upload Share']
                }).render();
            }

            if (window.ApexCharts && document.querySelector('#country-chart')) {
                new ApexCharts(document.querySelector('#country-chart'), {
                    chart: { height: 320, type: 'bar' },
                    plotOptions: { bar: { horizontal: true } },
                    colors: readColors('#country-chart', ['#47ad77']),
                    dataLabels: { enabled: false },
                    series: [{ name: 'Share', data: (dashboardData.countryChart && dashboardData.countryChart.values) ? dashboardData.countryChart.values : [] }],
                    xaxis: {
                        categories: (dashboardData.countryChart && dashboardData.countryChart.categories) ? dashboardData.countryChart.categories : [],
                        labels: { formatter: function (val) { return val + '%'; } }
                    },
                    grid: { strokeDashArray: [5] }
                }).render();
            }

            if ($.fn.vectorMap && $('#world-map-markers').length) {
                $('#world-map-markers').vectorMap({
                    map: 'world_mill_en',
                    normalizeFunction: 'polynomial',
                    hoverOpacity: 0.7,
                    hoverColor: false,
                    regionStyle: { initial: { fill: 'rgba(145,166,189,.25)' } },
                    markerStyle: {
                        initial: {
                            r: 9,
                            fill: '#3e60d5',
                            'fill-opacity': 0.9,
                            stroke: '#fff',
                            'stroke-width': 7,
                            'stroke-opacity': 0.4
                        },
                        hover: { stroke: '#fff', 'fill-opacity': 1, 'stroke-width': 1.5 }
                    },
                    backgroundColor: 'transparent',
                    markers: dashboardData.mapMarkers || [],
                    zoomOnScroll: false
                });
            }
        });
    })(window.jQuery);
    </script>

    <!-- App js -->
    <script src="assets/js/app.min.js"></script>

</body>

</html>

