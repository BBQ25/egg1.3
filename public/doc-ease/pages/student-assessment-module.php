<?php include '../layouts/session.php'; ?>
<?php require_approved_student(); ?>
<?php include '../layouts/main.php'; ?>

<?php
require_once __DIR__ . '/../includes/grading.php';
ensure_grading_tables($conn);

$flash = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : '';
$flashType = isset($_SESSION['flash_type']) ? (string) $_SESSION['flash_type'] : 'success';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$assessmentId = isset($_GET['assessment_id']) ? (int) $_GET['assessment_id'] : 0;
if ($assessmentId <= 0) {
    $_SESSION['flash_message'] = 'Invalid assessment.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: student-dashboard.php');
    exit;
}

if (!function_exists('sam_h')) {
    function sam_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sam_format_datetime')) {
    function sam_format_datetime($value) {
        $value = trim((string) $value);
        if ($value === '') return '-';
        $ts = strtotime($value);
        if (!$ts) return $value;
        return date('Y-m-d H:i', $ts);
    }
}

if (!function_exists('sam_fmt_bytes')) {
    function sam_fmt_bytes($bytes) {
        $bytes = (int) $bytes;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 2) . ' KB';
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }
}

if (!function_exists('sam_clean_filename')) {
    function sam_clean_filename($name) {
        $name = trim((string) $name);
        if ($name === '') return 'file';
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $name = trim((string) $name, '._-');
        return $name === '' ? 'file' : substr($name, 0, 180);
    }
}

if (!function_exists('sam_root')) {
    function sam_root() {
        return dirname(__DIR__);
    }
}

if (!function_exists('sam_unlink_rel')) {
    function sam_unlink_rel($path) {
        $path = trim(str_replace('\\', '/', (string) $path));
        if ($path === '' || strpos($path, 'uploads/assignments/') !== 0) return;
        $abs = sam_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($abs)) @unlink($abs);
    }
}

if (!function_exists('sam_get_or_create_submission_id')) {
    function sam_get_or_create_submission_id(mysqli $conn, $assessmentId, $studentId) {
        $assessmentId = (int) $assessmentId;
        $studentId = (int) $studentId;
        if ($assessmentId <= 0 || $studentId <= 0) return 0;
        $stmt = $conn->prepare(
            "INSERT INTO grading_assignment_submissions (assessment_id, student_id, status, last_modified_at)
             VALUES (?, ?, 'draft', NOW())
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), last_modified_at = last_modified_at"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('ii', $assessmentId, $studentId);
        $ok = $stmt->execute();
        $id = $ok ? (int) $conn->insert_id : 0;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('sam_count_student_proof_files')) {
    function sam_count_student_proof_files(mysqli $conn, $assessmentId, $studentId) {
        $assessmentId = (int) $assessmentId;
        $studentId = (int) $studentId;
        if ($assessmentId <= 0 || $studentId <= 0) return 0;
        $count = 0;
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS c
             FROM grading_assignment_submissions ss
             JOIN grading_assignment_submission_files sf ON sf.submission_id = ss.id
             WHERE ss.assessment_id = ?
               AND ss.student_id = ?
               AND sf.uploaded_by_role = 'student'"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $assessmentId, $studentId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $count = (int) ($res->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        }
        return $count;
    }
}

if (!function_exists('sam_parse_accepted_file_types')) {
    function sam_parse_accepted_file_types($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') return [];
        $tokens = preg_split('/[,\\s]+/', $raw);
        $exts = [];
        foreach ($tokens as $t) {
            $t = strtolower(trim((string) $t));
            if ($t === '') continue;
            $t = ltrim($t, '.');
            if (!preg_match('/^[a-z0-9]{1,12}$/', $t)) continue;
            $exts[$t] = $t;
        }
        return array_values($exts);
    }
}

if (!function_exists('sam_supported_image_mimes')) {
    function sam_supported_image_mimes() {
        return [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
    }
}

if (!function_exists('sam_detect_image_mime')) {
    function sam_detect_image_mime($path) {
        $path = (string) $path;
        if ($path === '' || !is_file($path)) return '';
        $info = @getimagesize($path);
        if (!is_array($info)) return '';
        $mime = strtolower(trim((string) ($info['mime'] ?? '')));
        $map = sam_supported_image_mimes();
        return isset($map[$mime]) ? $mime : '';
    }
}

if (!function_exists('sam_image_mime_to_ext')) {
    function sam_image_mime_to_ext($mime) {
        $mime = strtolower(trim((string) $mime));
        $map = sam_supported_image_mimes();
        return isset($map[$mime]) ? (string) $map[$mime] : '';
    }
}

if (!function_exists('sam_image_load_resource')) {
    function sam_image_load_resource($path, $mime, &$error = '') {
        $error = '';
        $path = (string) $path;
        $mime = strtolower(trim((string) $mime));
        if ($path === '' || !is_file($path)) {
            $error = 'Image file is missing.';
            return null;
        }

        $img = null;
        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
            $img = @imagecreatefromjpeg($path);
        } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
            $img = @imagecreatefrompng($path);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($path);
        } elseif ($mime === 'image/gif' && function_exists('imagecreatefromgif')) {
            $img = @imagecreatefromgif($path);
        }

        if (!$img) {
            $error = 'Unsupported or invalid image file.';
            return null;
        }
        return $img;
    }
}

if (!function_exists('sam_image_clarity_score')) {
    function sam_image_clarity_score($path, &$error = '') {
        $error = '';
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
            $error = 'Image analysis is unavailable on this server.';
            return -1.0;
        }

        $mime = sam_detect_image_mime($path);
        if ($mime === '') {
            $error = 'Uploaded file is not a supported image.';
            return -1.0;
        }

        $img = sam_image_load_resource($path, $mime, $loadError);
        if (!$img) {
            $error = $loadError !== '' ? $loadError : 'Unable to read uploaded image.';
            return -1.0;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 80 || $h < 80) {
            imagedestroy($img);
            $error = 'Image is too small. Please upload a clearer photo.';
            return -1.0;
        }

        $maxDim = 420;
        $scale = min(1.0, $maxDim / max($w, $h));
        $tw = max(64, (int) floor($w * $scale));
        $th = max(64, (int) floor($h * $scale));

        $sample = imagecreatetruecolor($tw, $th);
        if (!$sample) {
            imagedestroy($img);
            $error = 'Unable to analyze image.';
            return -1.0;
        }

        imagecopyresampled($sample, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($img);

        $gray = [];
        for ($y = 0; $y < $th; $y++) {
            $row = [];
            for ($x = 0; $x < $tw; $x++) {
                $rgb = imagecolorat($sample, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $row[] = (int) round(($r * 0.299) + ($g * 0.587) + ($b * 0.114));
            }
            $gray[] = $row;
        }
        imagedestroy($sample);

        $sum = 0.0;
        $sumSq = 0.0;
        $n = 0;
        for ($y = 1; $y < ($th - 1); $y++) {
            for ($x = 1; $x < ($tw - 1); $x++) {
                $lap = (4 * $gray[$y][$x])
                    - $gray[$y][$x - 1]
                    - $gray[$y][$x + 1]
                    - $gray[$y - 1][$x]
                    - $gray[$y + 1][$x];
                $sum += $lap;
                $sumSq += ($lap * $lap);
                $n++;
            }
        }

        if ($n <= 0) {
            $error = 'Unable to analyze image clarity.';
            return -1.0;
        }

        $mean = $sum / $n;
        $variance = ($sumSq / $n) - ($mean * $mean);
        if ($variance < 0) $variance = 0.0;
        return (float) $variance;
    }
}

if (!function_exists('sam_reencode_image_to_limit')) {
    function sam_reencode_image_to_limit($srcPath, $destPath, $targetBytes, &$error = '') {
        $error = '';
        $targetBytes = (int) $targetBytes;
        if ($targetBytes <= 0) {
            $error = 'Invalid target size for image processing.';
            return null;
        }
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg') || !function_exists('imagecopyresampled')) {
            $error = 'Server image processing is unavailable.';
            return null;
        }

        $mime = sam_detect_image_mime($srcPath);
        if ($mime === '') {
            $error = 'Uploaded file is not a supported image.';
            return null;
        }

        $img = sam_image_load_resource($srcPath, $mime, $loadError);
        if (!$img) {
            $error = $loadError !== '' ? $loadError : 'Unable to process image.';
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= 0 || $h <= 0) {
            imagedestroy($img);
            $error = 'Invalid image size.';
            return null;
        }

        // Flatten onto white background to keep JPEG output consistent.
        $base = imagecreatetruecolor($w, $h);
        if (!$base) {
            imagedestroy($img);
            $error = 'Unable to process image.';
            return null;
        }
        $white = imagecolorallocate($base, 255, 255, 255);
        imagefilledrectangle($base, 0, 0, $w, $h, $white);
        imagecopy($base, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);

        $bestBin = '';
        $work = $base;
        $successBin = '';
        for ($scaleTry = 0; $scaleTry < 8; $scaleTry++) {
            foreach ([90, 84, 78, 72, 66, 60, 54, 48, 42, 36] as $quality) {
                ob_start();
                $okJpg = @imagejpeg($work, null, $quality);
                $bin = ob_get_clean();
                if (!$okJpg || !is_string($bin) || $bin === '') {
                    continue;
                }
                if ($bestBin === '' || strlen($bin) < strlen($bestBin)) {
                    $bestBin = $bin;
                }
                if (strlen($bin) <= $targetBytes) {
                    $successBin = $bin;
                    break 2;
                }
            }

            $cw = imagesx($work);
            $ch = imagesy($work);
            if ($cw <= 900 || $ch <= 900) {
                break;
            }
            $nw = max(900, (int) floor($cw * 0.85));
            $nh = max(900, (int) floor($ch * 0.85));
            if ($nw >= $cw || $nh >= $ch) {
                break;
            }
            $scaled = imagecreatetruecolor($nw, $nh);
            if (!$scaled) break;
            $whiteScaled = imagecolorallocate($scaled, 255, 255, 255);
            imagefilledrectangle($scaled, 0, 0, $nw, $nh, $whiteScaled);
            imagecopyresampled($scaled, $work, 0, 0, 0, 0, $nw, $nh, $cw, $ch);
            if ($work !== $base) imagedestroy($work);
            $work = $scaled;
        }

        if ($work !== $base) imagedestroy($work);
        imagedestroy($base);

        if ($successBin === '') {
            $error = 'Image is too large. Please upload a clearer, smaller photo.';
            return null;
        }

        $written = @file_put_contents($destPath, $successBin);
        if ($written === false || (int) $written <= 0) {
            $error = 'Unable to store processed image.';
            return null;
        }

        return [
            'mime_type' => 'image/jpeg',
            'file_ext' => 'jpg',
            'file_size' => (int) $written,
        ];
    }
}

if (!function_exists('sam_store_submission_file')) {
    function sam_store_submission_file($assessmentId, $studentId, array $upload, $maxSizeMb, array $acceptedExts, &$error = '') {
        $error = '';
        $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            $error = 'Please choose a file to upload.';
            return null;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $error = 'Upload failed (error code: ' . $err . ').';
            return null;
        }

        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $error = 'Invalid upload payload.';
            return null;
        }

        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0) {
            $error = 'Uploaded file is empty.';
            return null;
        }
        $maxBytes = max(1, (int) $maxSizeMb) * 1024 * 1024;
        $autoImageCapBytes = 5 * 1024 * 1024;

        $original = (string) ($upload['name'] ?? 'submission');
        $clean = sam_clean_filename($original);
        $cleanBase = sam_clean_filename((string) pathinfo($clean, PATHINFO_FILENAME));
        if ($cleanBase === '') $cleanBase = 'file';
        $ext = strtolower((string) pathinfo($clean, PATHINFO_EXTENSION));
        $blockedExt = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'com', 'js', 'jar', 'msi', 'vbs', 'sh', 'ps1'];
        if ($ext !== '' && in_array($ext, $blockedExt, true)) {
            $error = 'This file type is blocked.';
            return null;
        }
        if (count($acceptedExts) > 0 && ($ext === '' || !in_array($ext, $acceptedExts, true))) {
            $error = 'File type is not in the accepted file types list.';
            return null;
        }

        $imageMime = sam_detect_image_mime($tmp);
        $isImage = $imageMime !== '';
        $effectiveImageLimitBytes = min($maxBytes, $autoImageCapBytes);
        if ($isImage && $size > (35 * 1024 * 1024)) {
            $error = 'Image is too large. Please upload a photo smaller than 35MB.';
            return null;
        }
        if (!$isImage && $size > $maxBytes) {
            $error = 'File exceeds the maximum allowed size of ' . (int) $maxSizeMb . 'MB.';
            return null;
        }

        try {
            $token = bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            $token = substr(md5(uniqid((string) mt_rand(), true)), 0, 16);
        }
        $storedBase = date('YmdHis') . '-' . $token . '-' . $cleanBase;
        if (strlen($storedBase) > 180) $storedBase = substr($storedBase, 0, 180);
        $relDir = 'uploads/assignments/submissions/a_' . (int) $assessmentId . '/s_' . (int) $studentId;
        $absDir = sam_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            $error = 'Unable to create upload directory.';
            return null;
        }

        $storedExt = $ext;
        $storedMime = (string) ($upload['type'] ?? 'application/octet-stream');
        $storedSize = $size;
        $wasCompressed = 0;
        $clarityScore = 0.0;

        if ($isImage) {
            $score = sam_image_clarity_score($tmp, $clarityError);
            if ($score < 0) {
                $error = $clarityError !== '' ? $clarityError : 'Unable to validate image clarity.';
                return null;
            }
            $clarityScore = $score;
            if ($clarityScore < 60.0) {
                $error = 'Photo looks blurred. Please retake the image in better focus.';
                return null;
            }

            $sourceExt = sam_image_mime_to_ext($imageMime);
            if ($sourceExt !== '') $storedExt = $sourceExt;
            if ($storedExt === '') $storedExt = 'jpg';

            if ($size > $effectiveImageLimitBytes) {
                $storedExt = 'jpg';
                $storedName = $storedBase . '.' . $storedExt;
                $relPath = $relDir . '/' . $storedName;
                $absPath = sam_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
                $processed = sam_reencode_image_to_limit($tmp, $absPath, $effectiveImageLimitBytes, $processError);
                if (!is_array($processed)) {
                    $error = $processError !== '' ? $processError : 'Unable to optimize uploaded image.';
                    return null;
                }
                $storedMime = (string) ($processed['mime_type'] ?? 'image/jpeg');
                $storedSize = (int) ($processed['file_size'] ?? 0);
                if ($storedSize <= 0 || $storedSize > $effectiveImageLimitBytes) {
                    if (is_file($absPath)) @unlink($absPath);
                    $error = 'Unable to reduce image size to the allowed limit.';
                    return null;
                }
                $wasCompressed = 1;
            } else {
                $storedName = $storedBase . '.' . $storedExt;
                $relPath = $relDir . '/' . $storedName;
                $absPath = sam_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
                if (!@move_uploaded_file($tmp, $absPath)) {
                    $error = 'Unable to store uploaded file.';
                    return null;
                }
                $storedSize = (int) @filesize($absPath);
                $storedMime = $imageMime;
            }
        } else {
            if ($storedExt === '') $storedExt = 'bin';
            $storedName = $storedBase . '.' . $storedExt;
            $relPath = $relDir . '/' . $storedName;
            $absPath = sam_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            if (!@move_uploaded_file($tmp, $absPath)) {
                $error = 'Unable to store uploaded file.';
                return null;
            }
            $storedSize = (int) @filesize($absPath);
            if ($storedSize <= 0) $storedSize = $size;
            if (function_exists('finfo_open')) {
                $fi = @finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) {
                    $det = @finfo_file($fi, $absPath);
                    if (is_string($det) && $det !== '') $storedMime = $det;
                    @finfo_close($fi);
                }
            }
        }

        return [
            'original_name' => substr($original, 0, 255),
            'file_name' => substr((string) $storedName, 0, 255),
            'file_path' => substr($relPath, 0, 500),
            'file_size' => $storedSize > 0 ? $storedSize : $size,
            'mime_type' => substr($storedMime, 0, 120),
            'was_compressed' => $wasCompressed,
            'clarity_score' => round($clarityScore, 2),
        ];
    }
}

$student = null;
$stmt = $conn->prepare(
    "SELECT id
     FROM students
     WHERE user_id = ?
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) $student = $res->fetch_assoc();
    $stmt->close();
}
if (!is_array($student)) {
    deny_access(403, 'Student profile is not linked to this account.');
}

$studentId = (int) ($student['id'] ?? 0);
if ($studentId <= 0) {
    deny_access(403, 'Student profile is invalid.');
}

$ctx = null;
$stmt = $conn->prepare(
    "SELECT ga.id AS assessment_id,
            ga.name AS assessment_name,
            ga.max_score,
            ga.assessment_mode,
            ga.module_type,
            ga.instructions,
            ga.module_settings_json,
            ga.require_proof_upload,
            ga.assessment_date,
            ga.open_at,
            ga.close_at,
            gc.component_name,
            gc.component_code,
            COALESCE(c.category_name, 'Uncategorized') AS category_name,
            sgc.term,
            sgc.section,
            sgc.academic_year,
            sgc.semester,
            s.subject_code,
            s.subject_name
     FROM grading_assessments ga
     JOIN grading_components gc ON gc.id = ga.grading_component_id
     JOIN section_grading_configs sgc ON sgc.id = gc.section_config_id
     JOIN class_records cr
        ON cr.subject_id = sgc.subject_id
       AND cr.section = sgc.section
       AND cr.academic_year = sgc.academic_year
       AND cr.semester = sgc.semester
       AND cr.status = 'active'
     JOIN class_enrollments ce
        ON ce.class_record_id = cr.id
       AND ce.student_id = ?
       AND ce.status = 'enrolled'
     JOIN subjects s ON s.id = sgc.subject_id
     LEFT JOIN grading_categories c ON c.id = gc.category_id
     WHERE ga.id = ?
       AND ga.is_active = 1
       AND gc.is_active = 1
     LIMIT 1"
);
if ($stmt) {
    $stmt->bind_param('ii', $studentId, $assessmentId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) $ctx = $res->fetch_assoc();
    $stmt->close();
}
if (!is_array($ctx)) {
    deny_access(403, 'Forbidden: assessment is not available for your enrolled classes.');
}

$assessmentMode = strtolower(trim((string) ($ctx['assessment_mode'] ?? 'manual')));
if (!in_array($assessmentMode, ['manual', 'quiz'], true)) $assessmentMode = 'manual';
$moduleType = grading_normalize_module_type((string) ($ctx['module_type'] ?? 'assessment'));
$moduleInfo = grading_module_info($moduleType);
$moduleLabel = (string) ($moduleInfo['label'] ?? 'Assessment');
$moduleKind = strtolower((string) ($moduleInfo['kind'] ?? 'assessment'));
$moduleDescription = trim((string) ($moduleInfo['description'] ?? ''));
$moduleClass = 'bg-secondary-subtle text-secondary';
if ($moduleKind === 'activity') $moduleClass = 'bg-primary-subtle text-primary';
elseif ($moduleKind === 'resource') $moduleClass = 'bg-info-subtle text-info';
$requireProofUpload = !empty($ctx['require_proof_upload']);

$moduleSettings = grading_decode_json_array((string) ($ctx['module_settings_json'] ?? ''));
$moduleSummary = trim((string) ($moduleSettings['summary'] ?? ''));
$moduleLaunchUrl = trim((string) ($moduleSettings['launch_url'] ?? ''));
$moduleNotes = trim((string) ($moduleSettings['notes'] ?? ''));
$moduleLaunchHref = '';
if ($moduleLaunchUrl !== '' && preg_match('/^https?:\\/\\//i', $moduleLaunchUrl)) {
    $moduleLaunchHref = $moduleLaunchUrl;
}

$term = strtolower(trim((string) ($ctx['term'] ?? 'midterm')));
$termLabel = $term === 'final' ? 'Final Term' : 'Midterm';

$assignmentSettings = [];
$assignmentResources = [];
$assignmentSubmission = null;
$assignmentStudentFiles = [];
$assignmentFeedbackFiles = [];
$assignmentLocked = false;
$assignmentAvailabilityMessage = '';
$assignmentCanSubmit = true;
$assignmentAcceptedExts = [];
$assignmentFileAcceptAttr = 'image/*';
$assessmentProofFiles = [];
$assessmentProofAcceptedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$assessmentProofAcceptAttr = '.jpg,.jpeg,.png,.webp,.gif';
$assessmentProofMaxFiles = 20;
$assessmentProofMaxUploadMb = 35;
$assessmentProofCanUpload = true;
$assessmentProofAvailabilityMessage = '';
$assessmentWindowNowTs = time();
$assessmentOpenAt = trim((string) ($ctx['open_at'] ?? ''));
$assessmentCloseAt = trim((string) ($ctx['close_at'] ?? ''));
if ($assessmentOpenAt !== '') {
    $openTs = strtotime($assessmentOpenAt);
    if ($openTs && $assessmentWindowNowTs < $openTs) {
        $assessmentProofCanUpload = false;
        $assessmentProofAvailabilityMessage = 'Uploads are not open yet (opens ' . date('Y-m-d H:i', $openTs) . ').';
    }
}
if ($assessmentProofCanUpload && $assessmentCloseAt !== '') {
    $closeTs = strtotime($assessmentCloseAt);
    if ($closeTs && $assessmentWindowNowTs > $closeTs) {
        $assessmentProofCanUpload = false;
        $assessmentProofAvailabilityMessage = 'Uploads are closed (cut-off was ' . date('Y-m-d H:i', $closeTs) . ').';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    if (in_array($action, ['upload_assessment_proof', 'delete_assessment_proof'], true)) {
        $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
        if (!csrf_validate($csrf)) {
            $_SESSION['flash_message'] = 'Security check failed (CSRF).';
            $_SESSION['flash_type'] = 'danger';
            header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
            exit;
        }

        if (!$assessmentProofCanUpload) {
            $_SESSION['flash_message'] = $assessmentProofAvailabilityMessage !== '' ? $assessmentProofAvailabilityMessage : 'Uploads are not available at this time.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
            exit;
        }

        $proofSubmissionId = sam_get_or_create_submission_id($conn, $assessmentId, $studentId);
        if ($proofSubmissionId <= 0) {
            $_SESSION['flash_message'] = 'Unable to load submission record.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
            exit;
        }

        if ($action === 'upload_assessment_proof') {
            $countQ = $conn->prepare("SELECT COUNT(*) AS c FROM grading_assignment_submission_files WHERE submission_id = ? AND uploaded_by_role = 'student'");
            $currentFileCount = 0;
            if ($countQ) {
                $countQ->bind_param('i', $proofSubmissionId);
                $countQ->execute();
                $res = $countQ->get_result();
                if ($res && $res->num_rows === 1) $currentFileCount = (int) ($res->fetch_assoc()['c'] ?? 0);
                $countQ->close();
            }

            if ($currentFileCount >= $assessmentProofMaxFiles) {
                $_SESSION['flash_message'] = 'Maximum number of uploaded proof photos reached.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
                exit;
            }

            $upload = isset($_FILES['submission_file']) && is_array($_FILES['submission_file']) ? $_FILES['submission_file'] : null;
            $error = '';
            $stored = $upload ? sam_store_submission_file($assessmentId, $studentId, $upload, $assessmentProofMaxUploadMb, $assessmentProofAcceptedExts, $error) : null;
            if (!is_array($stored)) {
                $_SESSION['flash_message'] = $error !== '' ? $error : 'Unable to upload proof photo.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
                exit;
            }

            $ins = $conn->prepare(
                "INSERT INTO grading_assignment_submission_files
                    (submission_id, original_name, file_name, file_path, file_size, mime_type, uploaded_by_role, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'student', ?)"
            );
            if ($ins) {
                $ins->bind_param('isssisi', $proofSubmissionId, $stored['original_name'], $stored['file_name'], $stored['file_path'], $stored['file_size'], $stored['mime_type'], $userId);
                $ok = $ins->execute();
                $ins->close();
                if ($ok) {
                    $touch = $conn->prepare("UPDATE grading_assignment_submissions SET last_modified_at = NOW() WHERE id = ? LIMIT 1");
                    if ($touch) {
                        $touch->bind_param('i', $proofSubmissionId);
                        $touch->execute();
                        $touch->close();
                    }
                }
                if ($ok) {
                    $msg = 'Proof photo uploaded.';
                    if (!empty($stored['was_compressed'])) {
                        $msg .= ' Image was optimized to 5MB or less.';
                    }
                    $_SESSION['flash_message'] = $msg;
                } else {
                    $_SESSION['flash_message'] = 'Unable to save proof photo record.';
                }
                $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
            }
            header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
            exit;
        }

        if ($action === 'delete_assessment_proof') {
            $fileId = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
            $row = null;
            $find = $conn->prepare(
                "SELECT sf.id, sf.file_path
                 FROM grading_assignment_submission_files sf
                 JOIN grading_assignment_submissions ss ON ss.id = sf.submission_id
                 WHERE sf.id = ? AND sf.uploaded_by_role = 'student' AND ss.assessment_id = ? AND ss.student_id = ?
                 LIMIT 1"
            );
            if ($find) {
                $find->bind_param('iii', $fileId, $assessmentId, $studentId);
                $find->execute();
                $res = $find->get_result();
                if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
                $find->close();
            }

            if (!is_array($row)) {
                $_SESSION['flash_message'] = 'Proof photo not found.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
                exit;
            }

            $del = $conn->prepare("DELETE FROM grading_assignment_submission_files WHERE id = ? LIMIT 1");
            if ($del) {
                $del->bind_param('i', $fileId);
                $ok = $del->execute();
                $del->close();
                if ($ok) sam_unlink_rel((string) ($row['file_path'] ?? ''));
                $_SESSION['flash_message'] = $ok ? 'Proof photo removed.' : 'Unable to remove proof photo.';
                $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
            }
            header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
            exit;
        }
    }
}

$proofListQ = $conn->prepare(
    "SELECT sf.id, sf.original_name, sf.file_path, sf.file_size, sf.created_at
     FROM grading_assignment_submission_files sf
     JOIN grading_assignment_submissions ss ON ss.id = sf.submission_id
     WHERE ss.assessment_id = ?
       AND ss.student_id = ?
       AND sf.uploaded_by_role = 'student'
     ORDER BY sf.id DESC"
);
if ($proofListQ) {
    $proofListQ->bind_param('ii', $assessmentId, $studentId);
    $proofListQ->execute();
    $res = $proofListQ->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $assessmentProofFiles[] = $row;
    }
    $proofListQ->close();
}

if ($moduleType === 'assignment') {
    $assignmentSettings = grading_assignment_settings((string) ($ctx['module_settings_json'] ?? ''));
    $assignmentAcceptedExts = sam_parse_accepted_file_types((string) ($assignmentSettings['accepted_file_types'] ?? ''));
    if (count($assignmentAcceptedExts) > 0) {
        $parts = [];
        foreach ($assignmentAcceptedExts as $e) {
            $parts[] = '.' . ltrim((string) $e, '.');
        }
        $assignmentFileAcceptAttr = implode(',', $parts);
    }

    $nowTs = time();
    $openAt = trim((string) ($ctx['open_at'] ?? ''));
    $closeAt = trim((string) ($ctx['close_at'] ?? ''));
    if ($openAt !== '') {
        $openTs = strtotime($openAt);
        if ($openTs && $nowTs < $openTs) {
            $assignmentCanSubmit = false;
            $assignmentAvailabilityMessage = 'Submissions are not open yet (opens ' . date('Y-m-d H:i', $openTs) . ').';
        }
    }
    if ($assignmentCanSubmit && $closeAt !== '') {
        $closeTs = strtotime($closeAt);
        if ($closeTs && $nowTs > $closeTs) {
            $assignmentCanSubmit = false;
            $assignmentAvailabilityMessage = 'Submissions are closed (cut-off was ' . date('Y-m-d H:i', $closeTs) . ').';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
        if (!csrf_validate($csrf)) {
            $_SESSION['flash_message'] = 'Security check failed (CSRF).';
            $_SESSION['flash_type'] = 'danger';
            header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
            exit;
        }

        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
        $validActions = ['save_draft', 'submit_assignment', 'upload_submission_file', 'delete_submission_file'];
        if (!in_array($action, $validActions, true)) {
            $_SESSION['flash_message'] = 'Invalid request.';
            $_SESSION['flash_type'] = 'warning';
            header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
            exit;
        }

        $submissionId = sam_get_or_create_submission_id($conn, $assessmentId, $studentId);
        if ($submissionId <= 0) {
            $_SESSION['flash_message'] = 'Unable to load submission record.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
            exit;
        }

        $current = null;
        $sq = $conn->prepare("SELECT id, status FROM grading_assignment_submissions WHERE id = ? LIMIT 1");
        if ($sq) {
            $sq->bind_param('i', $submissionId);
            $sq->execute();
            $res = $sq->get_result();
            if ($res && $res->num_rows === 1) $current = $res->fetch_assoc();
            $sq->close();
        }
        $currentStatus = strtolower(trim((string) ($current['status'] ?? 'draft')));
        $attemptsReopened = strtolower(trim((string) ($assignmentSettings['attempts_reopened'] ?? 'never')));
        $assignmentLocked = $currentStatus === 'graded' && in_array($attemptsReopened, ['never', 'manual'], true);

        if (($action === 'save_draft' || $action === 'submit_assignment' || $action === 'upload_submission_file' || $action === 'delete_submission_file')
            && (!$assignmentCanSubmit || $assignmentLocked)) {
            $_SESSION['flash_message'] = $assignmentLocked
                ? 'This assignment is locked after grading.'
                : ($assignmentAvailabilityMessage !== '' ? $assignmentAvailabilityMessage : 'Submissions are currently closed.');
            $_SESSION['flash_type'] = 'warning';
            header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
            exit;
        }

        if ($action === 'save_draft' || $action === 'submit_assignment') {
            $submissionText = trim((string) ($_POST['submission_text'] ?? ''));
            if (empty($assignmentSettings['submission_online_text'])) $submissionText = '';
            if (strlen($submissionText) > 12000) $submissionText = substr($submissionText, 0, 12000);

            if ($action === 'submit_assignment' && !empty($assignmentSettings['require_accept_statement'])) {
                if (!isset($_POST['accept_statement'])) {
                    $_SESSION['flash_message'] = 'You must accept the submission statement before submitting.';
                    $_SESSION['flash_type'] = 'warning';
                    header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
                    exit;
                }
            }
            if ($action === 'submit_assignment' && $requireProofUpload) {
                $proofCount = sam_count_student_proof_files($conn, $assessmentId, $studentId);
                if ($proofCount <= 0) {
                    $_SESSION['flash_message'] = 'Upload at least one proof file/photo before submitting this assessment.';
                    $_SESSION['flash_type'] = 'warning';
                    header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
                    exit;
                }
            }

            $status = $action === 'submit_assignment' ? 'submitted' : 'draft';
            $dueAt = trim((string) ($assignmentSettings['due_at'] ?? ''));
            $isLate = 0;
            if ($action === 'submit_assignment' && $dueAt !== '') {
                $dueTs = strtotime($dueAt);
                if ($dueTs && time() > $dueTs) $isLate = 1;
            }
            $submittedAt = $action === 'submit_assignment' ? date('Y-m-d H:i:s') : '';

            if ($currentStatus === 'graded' && $attemptsReopened === 'until_pass') {
                $upd = $conn->prepare(
                    "UPDATE grading_assignment_submissions
                     SET status = ?,
                         submission_text = NULLIF(?, ''),
                         attempt_no = attempt_no + 1,
                         is_late = ?,
                         submitted_at = NULLIF(?, ''),
                         last_modified_at = NOW(),
                         graded_score = NULL,
                         feedback_comment = NULL,
                         graded_by = NULL,
                         graded_at = NULL
                     WHERE id = ?
                     LIMIT 1"
                );
                if ($upd) {
                    $upd->bind_param('ssisi', $status, $submissionText, $isLate, $submittedAt, $submissionId);
                    $upd->execute();
                    $upd->close();
                }
                grading_upsert_assessment_score($conn, $assessmentId, $studentId, null);
            } else {
                $upd = $conn->prepare(
                    "UPDATE grading_assignment_submissions
                     SET status = ?,
                         submission_text = NULLIF(?, ''),
                         is_late = ?,
                         submitted_at = CASE WHEN ? = '' THEN submitted_at ELSE ? END,
                         last_modified_at = NOW()
                     WHERE id = ?
                     LIMIT 1"
                );
                if ($upd) {
                    $upd->bind_param('ssissi', $status, $submissionText, $isLate, $submittedAt, $submittedAt, $submissionId);
                    $upd->execute();
                    $upd->close();
                }
            }

            $_SESSION['flash_message'] = $action === 'submit_assignment' ? 'Assignment submitted.' : 'Draft saved.';
            $_SESSION['flash_type'] = 'success';
            header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
            exit;
        }

        if ($action === 'upload_submission_file') {
            if (empty($assignmentSettings['submission_file'])) {
                $_SESSION['flash_message'] = 'File submissions are disabled for this assignment.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
                exit;
            }
            $countQ = $conn->prepare("SELECT COUNT(*) AS c FROM grading_assignment_submission_files WHERE submission_id = ? AND uploaded_by_role = 'student'");
            $currentFileCount = 0;
            if ($countQ) {
                $countQ->bind_param('i', $submissionId);
                $countQ->execute();
                $res = $countQ->get_result();
                if ($res && $res->num_rows === 1) $currentFileCount = (int) ($res->fetch_assoc()['c'] ?? 0);
                $countQ->close();
            }
            $maxFiles = (int) ($assignmentSettings['max_uploaded_files'] ?? 20);
            if ($currentFileCount >= $maxFiles) {
                $_SESSION['flash_message'] = 'Maximum number of uploaded files reached.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
                exit;
            }

            $upload = isset($_FILES['submission_file']) && is_array($_FILES['submission_file']) ? $_FILES['submission_file'] : null;
            $error = '';
            $stored = $upload ? sam_store_submission_file($assessmentId, $studentId, $upload, (int) ($assignmentSettings['max_submission_size_mb'] ?? 10), $assignmentAcceptedExts, $error) : null;
            if (!is_array($stored)) {
                $_SESSION['flash_message'] = $error !== '' ? $error : 'Unable to upload submission file.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
                exit;
            }

            $ins = $conn->prepare(
                "INSERT INTO grading_assignment_submission_files
                    (submission_id, original_name, file_name, file_path, file_size, mime_type, uploaded_by_role, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'student', ?)"
            );
            if ($ins) {
                $ins->bind_param('isssisi', $submissionId, $stored['original_name'], $stored['file_name'], $stored['file_path'], $stored['file_size'], $stored['mime_type'], $userId);
                $ok = $ins->execute();
                $ins->close();
                if ($ok) {
                    $touch = $conn->prepare("UPDATE grading_assignment_submissions SET last_modified_at = NOW() WHERE id = ? LIMIT 1");
                    if ($touch) {
                        $touch->bind_param('i', $submissionId);
                        $touch->execute();
                        $touch->close();
                    }
                }
                if ($ok) {
                    $msg = 'Submission file uploaded.';
                    if (!empty($stored['was_compressed'])) {
                        $msg .= ' Image was optimized to 5MB or less.';
                    }
                    $_SESSION['flash_message'] = $msg;
                } else {
                    $_SESSION['flash_message'] = 'Unable to save file record.';
                }
                $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
            }
            header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
            exit;
        }

        if ($action === 'delete_submission_file') {
            $fileId = isset($_POST['file_id']) ? (int) $_POST['file_id'] : 0;
            $row = null;
            $find = $conn->prepare(
                "SELECT sf.id, sf.file_path
                 FROM grading_assignment_submission_files sf
                 JOIN grading_assignment_submissions ss ON ss.id = sf.submission_id
                 WHERE sf.id = ? AND sf.uploaded_by_role = 'student' AND ss.assessment_id = ? AND ss.student_id = ?
                 LIMIT 1"
            );
            if ($find) {
                $find->bind_param('iii', $fileId, $assessmentId, $studentId);
                $find->execute();
                $res = $find->get_result();
                if ($res && $res->num_rows === 1) $row = $res->fetch_assoc();
                $find->close();
            }
            if (!is_array($row)) {
                $_SESSION['flash_message'] = 'Submission file not found.';
                $_SESSION['flash_type'] = 'warning';
                header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
                exit;
            }
            $del = $conn->prepare("DELETE FROM grading_assignment_submission_files WHERE id = ? LIMIT 1");
            if ($del) {
                $del->bind_param('i', $fileId);
                $ok = $del->execute();
                $del->close();
                if ($ok) sam_unlink_rel((string) ($row['file_path'] ?? ''));
                $_SESSION['flash_message'] = $ok ? 'Submission file removed.' : 'Unable to remove file.';
                $_SESSION['flash_type'] = $ok ? 'success' : 'danger';
            }
            header('Location: student-assessment-module.php?assessment_id=' . $assessmentId);
            exit;
        }
    }

    $rr = $conn->prepare("SELECT id, original_name, file_path, file_size, created_at FROM grading_assignment_resources WHERE assessment_id = ? ORDER BY id DESC");
    if ($rr) {
        $rr->bind_param('i', $assessmentId);
        $rr->execute();
        $res = $rr->get_result();
        while ($res && ($row = $res->fetch_assoc())) $assignmentResources[] = $row;
        $rr->close();
    }

    $subQ = $conn->prepare("SELECT * FROM grading_assignment_submissions WHERE assessment_id = ? AND student_id = ? LIMIT 1");
    if ($subQ) {
        $subQ->bind_param('ii', $assessmentId, $studentId);
        $subQ->execute();
        $res = $subQ->get_result();
        if ($res && $res->num_rows === 1) $assignmentSubmission = $res->fetch_assoc();
        $subQ->close();
    }

    if (is_array($assignmentSubmission)) {
        $statusNow = strtolower(trim((string) ($assignmentSubmission['status'] ?? 'draft')));
        $attemptsReopened = strtolower(trim((string) ($assignmentSettings['attempts_reopened'] ?? 'never')));
        $assignmentLocked = $statusNow === 'graded' && in_array($attemptsReopened, ['never', 'manual'], true);
        $submissionId = (int) ($assignmentSubmission['id'] ?? 0);
        if ($submissionId > 0) {
            $fq = $conn->prepare("SELECT id, original_name, file_path, file_size, uploaded_by_role, created_at FROM grading_assignment_submission_files WHERE submission_id = ? ORDER BY id ASC");
            if ($fq) {
                $fq->bind_param('i', $submissionId);
                $fq->execute();
                $res = $fq->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    if (strtolower((string) ($row['uploaded_by_role'] ?? 'student')) === 'teacher') $assignmentFeedbackFiles[] = $row;
                    else $assignmentStudentFiles[] = $row;
                }
                $fq->close();
            }
        }
    }
}
?>

<head>
    <title>Module Details | E-Record</title>
    <?php include '../layouts/title-meta.php'; ?>
    <?php include '../layouts/head-css.php'; ?>
</head>

<body>
    <div class="wrapper">
        <?php include '../layouts/menu.php'; ?>

        <div class="content-page">
            <div class="content">
                <div class="container-fluid">
                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box">
                                <div class="page-title-right">
                                    <ol class="breadcrumb m-0">
                                        <li class="breadcrumb-item"><a href="student-dashboard.php">Student</a></li>
                                        <li class="breadcrumb-item active">Module Details</li>
                                    </ol>
                                </div>
                                <h4 class="page-title">Module Details</h4>
                            </div>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo sam_h($flashType); ?>"><?php echo sam_h($flash); ?></div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                                        <div>
                                            <h5 class="mb-1"><?php echo sam_h((string) ($ctx['assessment_name'] ?? 'Assessment')); ?></h5>
                                            <div class="text-muted small">
                                                <?php echo sam_h((string) ($ctx['subject_name'] ?? '')); ?>
                                                (<?php echo sam_h((string) ($ctx['subject_code'] ?? '')); ?>)
                                                | <?php echo sam_h((string) ($ctx['section'] ?? '')); ?>
                                                | <?php echo sam_h((string) ($ctx['academic_year'] ?? '')); ?>, <?php echo sam_h((string) ($ctx['semester'] ?? '')); ?>
                                                | <?php echo sam_h($termLabel); ?>
                                            </div>
                                            <div class="mt-2 d-flex flex-wrap gap-2">
                                                <span class="badge bg-light text-dark border">
                                                    <?php echo sam_h((string) ($ctx['component_name'] ?? 'Component')); ?>
                                                </span>
                                                <span class="badge bg-secondary-subtle text-secondary">
                                                    <?php echo sam_h((string) ($ctx['category_name'] ?? 'Category')); ?>
                                                </span>
                                                <?php if ($assessmentMode === 'quiz'): ?>
                                                    <span class="badge bg-primary-subtle text-primary">Quiz Mode</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary">Manual Assessment</span>
                                                <?php endif; ?>
                                                <span class="badge <?php echo sam_h($moduleClass); ?>"><?php echo sam_h($moduleLabel); ?></span>
                                            </div>
                                        </div>
                                        <div class="text-end small">
                                            <div>Max Score: <strong><?php echo sam_h((string) ($ctx['max_score'] ?? '0')); ?></strong></div>
                                            <div>Date: <strong><?php echo sam_h((string) ($ctx['assessment_date'] ?? '-')); ?></strong></div>
                                            <div>Open: <strong><?php echo sam_h(sam_format_datetime((string) ($ctx['open_at'] ?? ''))); ?></strong></div>
                                            <div>Close: <strong><?php echo sam_h(sam_format_datetime((string) ($ctx['close_at'] ?? ''))); ?></strong></div>
                                        </div>
                                    </div>

                                    <hr class="my-3">

                                    <?php if ($moduleType === 'assignment'): ?>
                                        <?php
                                        $assignmentDescription = trim((string) ($assignmentSettings['description'] ?? ''));
                                        if ($assignmentDescription === '') $assignmentDescription = trim((string) ($ctx['instructions'] ?? ''));
                                        $submissionRow = is_array($assignmentSubmission) ? $assignmentSubmission : [];
                                        $submissionStatus = strtolower(trim((string) ($submissionRow['status'] ?? '')));
                                        $submissionTextCurrent = (string) ($submissionRow['submission_text'] ?? '');
                                        $gradedScore = $submissionRow['graded_score'] ?? null;
                                        ?>

                                        <?php if ($assignmentAvailabilityMessage !== ''): ?>
                                            <div class="alert alert-warning py-2"><?php echo sam_h($assignmentAvailabilityMessage); ?></div>
                                        <?php endif; ?>
                                        <?php if ($assignmentLocked): ?>
                                            <div class="alert alert-info py-2">This submission is locked after grading. Ask your teacher if reopening is needed.</div>
                                        <?php endif; ?>
                                        <?php if ($assignmentDescription !== ''): ?>
                                            <div class="mb-3">
                                                <h6 class="mb-1">Description</h6>
                                                <div><?php echo nl2br(sam_h($assignmentDescription)); ?></div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($assignmentSettings['activity_instructions'] ?? '')) !== ''): ?>
                                            <div class="mb-3">
                                                <h6 class="mb-1">Activity Instructions</h6>
                                                <div><?php echo nl2br(sam_h((string) ($assignmentSettings['activity_instructions'] ?? ''))); ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="row g-3">
                                            <div class="col-lg-6">
                                                <div class="border rounded p-3 h-100">
                                                    <h6 class="mb-2">Submission</h6>
                                                    <div class="small text-muted mb-2">Status: <strong><?php echo sam_h($submissionStatus !== '' ? ucfirst($submissionStatus) : 'Not submitted'); ?></strong></div>
                                                    <div class="small text-muted mb-2">Submitted at: <strong><?php echo sam_h(sam_format_datetime((string) ($submissionRow['submitted_at'] ?? ''))); ?></strong></div>
                                                    <div class="small text-muted mb-2">Last modified: <strong><?php echo sam_h(sam_format_datetime((string) ($submissionRow['last_modified_at'] ?? ''))); ?></strong></div>
                                                    <div class="small text-muted mb-2">Due date: <strong><?php echo sam_h(sam_format_datetime((string) ($assignmentSettings['due_at'] ?? ''))); ?></strong></div>
                                                    <?php if ($gradedScore !== null && is_numeric($gradedScore)): ?>
                                                        <div class="small text-muted mb-2">Grade: <strong><?php echo sam_h(number_format((float) $gradedScore, 2, '.', '')); ?></strong> / <?php echo sam_h((string) ($ctx['max_score'] ?? '0')); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (trim((string) ($submissionRow['feedback_comment'] ?? '')) !== ''): ?>
                                                        <div class="mt-2">
                                                            <div class="fw-semibold small">Teacher feedback</div>
                                                            <div class="small"><?php echo nl2br(sam_h((string) ($submissionRow['feedback_comment'] ?? ''))); ?></div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-lg-6">
                                                <div class="border rounded p-3 h-100">
                                                    <h6 class="mb-2">Assignment Files</h6>
                                                    <?php if (count($assignmentResources) === 0): ?>
                                                        <div class="text-muted small">No files provided by your teacher.</div>
                                                    <?php else: ?>
                                                        <ul class="list-unstyled mb-0">
                                                            <?php foreach ($assignmentResources as $resFile): ?>
                                                                <?php $href = ltrim((string) ($resFile['file_path'] ?? ''), '/'); ?>
                                                                <li class="mb-1">
                                                                    <a href="<?php echo sam_h($href); ?>" target="_blank" rel="noopener"><?php echo sam_h((string) ($resFile['original_name'] ?? 'file')); ?></a>
                                                                    <span class="text-muted small">(<?php echo sam_h(sam_fmt_bytes((int) ($resFile['file_size'] ?? 0))); ?>)</span>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>

                                                    <h6 class="mb-2 mt-3">Feedback Files</h6>
                                                    <?php if (count($assignmentFeedbackFiles) === 0): ?>
                                                        <div class="text-muted small">No feedback files yet.</div>
                                                    <?php else: ?>
                                                        <ul class="list-unstyled mb-0">
                                                            <?php foreach ($assignmentFeedbackFiles as $feedFile): ?>
                                                                <?php $href = ltrim((string) ($feedFile['file_path'] ?? ''), '/'); ?>
                                                                <li class="mb-1">
                                                                    <a href="<?php echo sam_h($href); ?>" target="_blank" rel="noopener"><?php echo sam_h((string) ($feedFile['original_name'] ?? 'feedback')); ?></a>
                                                                    <span class="text-muted small">(<?php echo sam_h(sam_fmt_bytes((int) ($feedFile['file_size'] ?? 0))); ?>)</span>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row g-3 mt-1">
                                            <div class="col-lg-7">
                                                <div class="border rounded p-3">
                                                    <h6 class="mb-2">Your Response</h6>
                                                    <form method="post">
                                                        <input type="hidden" name="csrf_token" value="<?php echo sam_h(csrf_token()); ?>">
                                                        <textarea class="form-control mb-2" name="submission_text" rows="6" <?php echo empty($assignmentSettings['submission_online_text']) ? 'disabled' : ''; ?>><?php echo sam_h($submissionTextCurrent); ?></textarea>
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input" type="checkbox" id="acceptStatement" name="accept_statement">
                                                            <label class="form-check-label" for="acceptStatement">I confirm this is my own work.</label>
                                                        </div>
                                                        <div class="d-flex flex-wrap gap-2">
                                                            <button class="btn btn-outline-primary btn-sm" type="submit" name="action" value="save_draft" <?php echo (!$assignmentCanSubmit || $assignmentLocked) ? 'disabled' : ''; ?>>Save draft</button>
                                                            <button class="btn btn-primary btn-sm" type="submit" name="action" value="submit_assignment" <?php echo (!$assignmentCanSubmit || $assignmentLocked) ? 'disabled' : ''; ?>>Submit assignment</button>
                                                        </div>
                                                        <?php if ($requireProofUpload): ?>
                                                            <div class="form-text">At least one proof file/photo is required before final submit.</div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($assignmentSettings['require_accept_statement'])): ?>
                                                            <div class="form-text">Submission statement acceptance is required before final submit.</div>
                                                        <?php endif; ?>
                                                    </form>
                                                </div>
                                            </div>
                                            <div class="col-lg-5">
                                                <div class="border rounded p-3">
                                                    <h6 class="mb-2">Submission Files</h6>
                                                    <?php if (!empty($assignmentSettings['submission_file'])): ?>
                                                        <form method="post" enctype="multipart/form-data" class="mb-2">
                                                            <input type="hidden" name="csrf_token" value="<?php echo sam_h(csrf_token()); ?>">
                                                            <input type="hidden" name="action" value="upload_submission_file">
                                                            <input class="form-control mb-2" type="file" name="submission_file" accept="<?php echo sam_h($assignmentFileAcceptAttr); ?>" <?php echo (!$assignmentCanSubmit || $assignmentLocked) ? 'disabled' : ''; ?>>
                                                            <div class="form-text mb-2">Photos are checked for clarity. Images larger than 5MB are auto-optimized to 5MB or less.</div>
                                                            <button class="btn btn-outline-primary btn-sm" type="submit" <?php echo (!$assignmentCanSubmit || $assignmentLocked) ? 'disabled' : ''; ?>>Upload</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <div class="text-muted small mb-2">File submissions are disabled.</div>
                                                    <?php endif; ?>
                                                    <?php if (count($assignmentStudentFiles) === 0): ?>
                                                        <div class="text-muted small">No submission files uploaded.</div>
                                                    <?php else: ?>
                                                        <ul class="list-unstyled mb-0">
                                                            <?php foreach ($assignmentStudentFiles as $subFile): ?>
                                                                <?php $href = ltrim((string) ($subFile['file_path'] ?? ''), '/'); ?>
                                                                <li class="mb-1 d-flex justify-content-between align-items-center gap-2">
                                                                    <div>
                                                                        <a href="<?php echo sam_h($href); ?>" target="_blank" rel="noopener"><?php echo sam_h((string) ($subFile['original_name'] ?? 'file')); ?></a>
                                                                        <span class="text-muted small">(<?php echo sam_h(sam_fmt_bytes((int) ($subFile['file_size'] ?? 0))); ?>)</span>
                                                                    </div>
                                                                    <form method="post" class="m-0">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo sam_h(csrf_token()); ?>">
                                                                        <input type="hidden" name="action" value="delete_submission_file">
                                                                        <input type="hidden" name="file_id" value="<?php echo (int) ($subFile['id'] ?? 0); ?>">
                                                                        <button class="btn btn-sm btn-outline-danger" type="submit" <?php echo (!$assignmentCanSubmit || $assignmentLocked) ? 'disabled' : ''; ?>>
                                                                            <i class="ri-delete-bin-6-line" aria-hidden="true"></i>
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="mb-2">
                                            <h6 class="mb-1">Module Description</h6>
                                            <div class="text-muted">
                                                <?php echo $moduleDescription !== '' ? sam_h($moduleDescription) : 'No module description available.'; ?>
                                            </div>
                                        </div>
                                        <?php if ($moduleSummary !== ''): ?>
                                            <div class="mb-2">
                                                <h6 class="mb-1">Module Summary</h6>
                                                <div><?php echo nl2br(sam_h($moduleSummary)); ?></div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($ctx['instructions'] ?? '')) !== ''): ?>
                                            <div class="mb-2">
                                                <h6 class="mb-1">Assessment Instructions</h6>
                                                <div><?php echo nl2br(sam_h((string) ($ctx['instructions'] ?? ''))); ?></div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($moduleNotes !== ''): ?>
                                            <div class="mb-2">
                                                <h6 class="mb-1">Module Notes</h6>
                                                <div><?php echo nl2br(sam_h($moduleNotes)); ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($assessmentProofAvailabilityMessage !== '' && !$assessmentProofCanUpload): ?>
                                            <div class="alert alert-warning py-2 mt-3"><?php echo sam_h($assessmentProofAvailabilityMessage); ?></div>
                                        <?php endif; ?>

                                        <div class="row g-3 mt-1">
                                            <div class="col-lg-5">
                                                <div class="border rounded p-3">
                                                    <h6 class="mb-2">Assessment Proof Upload</h6>
                                                    <p class="text-muted small mb-2">Upload clear photos of your pen-and-paper quiz, activity outputs, or other assessment proof.</p>
                                                    <?php if ($requireProofUpload): ?>
                                                        <div class="small text-warning mb-2">At least one proof upload is required for this assessment.</div>
                                                    <?php endif; ?>
                                                    <form method="post" enctype="multipart/form-data" class="mb-2">
                                                        <input type="hidden" name="csrf_token" value="<?php echo sam_h(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="upload_assessment_proof">
                                                        <input class="form-control mb-2" type="file" name="submission_file" accept="<?php echo sam_h($assessmentProofAcceptAttr); ?>" <?php echo $assessmentProofCanUpload ? '' : 'disabled'; ?>>
                                                        <div class="form-text mb-2">Photos are checked for clarity. Images larger than 5MB are auto-optimized to 5MB or less.</div>
                                                        <button class="btn btn-outline-primary btn-sm" type="submit" <?php echo $assessmentProofCanUpload ? '' : 'disabled'; ?>>Upload Proof</button>
                                                    </form>
                                                    <div class="text-muted small">Maximum files: <?php echo (int) $assessmentProofMaxFiles; ?>.</div>
                                                </div>
                                            </div>
                                            <div class="col-lg-7">
                                                <div class="border rounded p-3">
                                                    <h6 class="mb-2">Your Uploaded Proofs</h6>
                                                    <?php if (count($assessmentProofFiles) === 0): ?>
                                                        <div class="text-muted small">No proof photos uploaded yet.</div>
                                                    <?php else: ?>
                                                        <ul class="list-unstyled mb-0">
                                                            <?php foreach ($assessmentProofFiles as $proofFile): ?>
                                                                <?php $href = ltrim((string) ($proofFile['file_path'] ?? ''), '/'); ?>
                                                                <li class="mb-1 d-flex justify-content-between align-items-center gap-2">
                                                                    <div>
                                                                        <a href="<?php echo sam_h($href); ?>" target="_blank" rel="noopener"><?php echo sam_h((string) ($proofFile['original_name'] ?? 'proof')); ?></a>
                                                                        <span class="text-muted small">(<?php echo sam_h(sam_fmt_bytes((int) ($proofFile['file_size'] ?? 0))); ?>, <?php echo sam_h(sam_format_datetime((string) ($proofFile['created_at'] ?? ''))); ?>)</span>
                                                                    </div>
                                                                    <form method="post" class="m-0">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo sam_h(csrf_token()); ?>">
                                                                        <input type="hidden" name="action" value="delete_assessment_proof">
                                                                        <input type="hidden" name="file_id" value="<?php echo (int) ($proofFile['id'] ?? 0); ?>">
                                                                        <button class="btn btn-sm btn-outline-danger" type="submit" <?php echo $assessmentProofCanUpload ? '' : 'disabled'; ?>>
                                                                            <i class="ri-delete-bin-6-line" aria-hidden="true"></i>
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="mt-3 d-flex flex-wrap gap-2">
                                        <a class="btn btn-outline-secondary" href="student-dashboard.php">
                                            <i class="ri-arrow-left-line me-1" aria-hidden="true"></i>
                                            Back to Dashboard
                                        </a>
                                        <?php if ($assessmentMode === 'quiz'): ?>
                                            <a class="btn btn-primary" href="student-quiz-attempt.php?assessment_id=<?php echo (int) $assessmentId; ?>">
                                                <i class="ri-quiz-line me-1" aria-hidden="true"></i>
                                                Open Quiz
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($moduleLaunchHref !== ''): ?>
                                            <a class="btn btn-info" href="<?php echo sam_h($moduleLaunchHref); ?>" target="_blank" rel="noopener noreferrer">
                                                <i class="ri-external-link-line me-1" aria-hidden="true"></i>
                                                Open Module Link
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php include '../layouts/footer.php'; ?>
        </div>
    </div>

    <?php include '../layouts/right-sidebar.php'; ?>
    <?php include '../layouts/footer-scripts.php'; ?>
    <script src="assets/js/app.min.js"></script>
</body>
</html>
