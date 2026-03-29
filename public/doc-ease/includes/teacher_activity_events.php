<?php
// Teacher activity -> optional accomplishment entry bridge.

if (!function_exists('teacher_activity_ensure_tables')) {
    function teacher_activity_ensure_tables(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS teacher_activity_events (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                teacher_id INT NOT NULL,
                class_record_id INT NULL,
                event_type VARCHAR(64) NOT NULL,
                event_date DATE NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                payload_json TEXT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                accomplishment_entry_id BIGINT NULL,
                handled_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_teacher_date (teacher_id, event_date),
                KEY idx_teacher_status (teacher_id, status),
                KEY idx_class_date (class_record_id, event_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Backward-compatible adds for existing installs.
        $cols = [
            'status' => "ALTER TABLE teacher_activity_events ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER payload_json",
            'accomplishment_entry_id' => "ALTER TABLE teacher_activity_events ADD COLUMN accomplishment_entry_id BIGINT NULL AFTER status",
            'handled_at' => "ALTER TABLE teacher_activity_events ADD COLUMN handled_at TIMESTAMP NULL DEFAULT NULL AFTER accomplishment_entry_id",
        ];
        foreach ($cols as $name => $ddl) {
            $res = $conn->query("SHOW COLUMNS FROM teacher_activity_events LIKE '" . $conn->real_escape_string($name) . "'");
            $has = ($res instanceof mysqli_result) ? ($res->num_rows > 0) : false;
            if ($res instanceof mysqli_result) $res->close();
            if (!$has) {
                try { $conn->query($ddl); } catch (Throwable $e) { /* ignore */ }
            }
        }
    }
}

if (!function_exists('teacher_activity_create_event')) {
    function teacher_activity_create_event(
        mysqli $conn,
        $teacherId,
        $classRecordId,
        $eventType,
        $eventDate,
        $title,
        array $payload = []
    ) {
        teacher_activity_ensure_tables($conn);
        $teacherId = (int) $teacherId;
        $classRecordId = (int) $classRecordId;
        $eventType = trim((string) $eventType);
        $eventDate = trim((string) $eventDate);
        $title = trim((string) $title);

        if ($teacherId <= 0) return 0;
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $eventDate)) $eventDate = date('Y-m-d');
        if ($eventType === '') $eventType = 'teacher_activity';
        if (strlen($eventType) > 64) $eventType = substr($eventType, 0, 64);
        if (strlen($title) > 255) $title = substr($title, 0, 255);

        $payloadJson = '';
        if (!empty($payload)) {
            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!is_string($payloadJson)) $payloadJson = '';
            if (strlen($payloadJson) > 65000) $payloadJson = substr($payloadJson, 0, 65000);
        }

        $stmt = $conn->prepare(
            "INSERT INTO teacher_activity_events (teacher_id, class_record_id, event_type, event_date, title, payload_json, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('iissss', $teacherId, $classRecordId, $eventType, $eventDate, $title, $payloadJson);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $id = $ok ? (int) $conn->insert_id : 0;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('teacher_activity_get_event_for_teacher')) {
    function teacher_activity_get_event_for_teacher(mysqli $conn, $eventId, $teacherId) {
        teacher_activity_ensure_tables($conn);
        $eventId = (int) $eventId;
        $teacherId = (int) $teacherId;
        if ($eventId <= 0 || $teacherId <= 0) return null;

        $stmt = $conn->prepare(
            "SELECT id, teacher_id, class_record_id, event_type, event_date, title, payload_json, status, accomplishment_entry_id, handled_at, created_at
             FROM teacher_activity_events
             WHERE id = ? AND teacher_id = ?
             LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('ii', $eventId, $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('teacher_activity_mark_event_handled')) {
    function teacher_activity_mark_event_handled(mysqli $conn, $eventId, $teacherId, $status, $accomplishmentEntryId = null) {
        teacher_activity_ensure_tables($conn);
        $eventId = (int) $eventId;
        $teacherId = (int) $teacherId;
        $status = strtolower(trim((string) $status));
        if (!in_array($status, ['accepted', 'dismissed'], true)) $status = 'dismissed';
        $accomplishmentEntryId = is_null($accomplishmentEntryId) ? null : (int) $accomplishmentEntryId;
        if ($eventId <= 0 || $teacherId <= 0) return false;

        $stmt = $conn->prepare(
            "UPDATE teacher_activity_events
             SET status = ?, accomplishment_entry_id = ?, handled_at = NOW()
             WHERE id = ? AND teacher_id = ?
             LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('siii', $status, $accomplishmentEntryId, $eventId, $teacherId);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('teacher_activity_class_subject_label')) {
    function teacher_activity_class_subject_label(mysqli $conn, $classRecordId) {
        $classRecordId = (int) $classRecordId;
        if ($classRecordId <= 0) return 'Monthly Accomplishment';

        $stmt = $conn->prepare(
            "SELECT TRIM(COALESCE(s.subject_code,'')) AS subject_code,
                    TRIM(COALESCE(s.subject_name,'')) AS subject_name
             FROM class_records cr
             JOIN subjects s ON s.id = cr.subject_id
             WHERE cr.id = ?
             LIMIT 1"
        );
        if (!$stmt) return 'Monthly Accomplishment';
        $stmt->bind_param('i', $classRecordId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!is_array($row)) return 'Monthly Accomplishment';

        $code = trim((string) ($row['subject_code'] ?? ''));
        $name = trim((string) ($row['subject_name'] ?? ''));
        if ($code !== '' && $name !== '') return $code . ' - ' . $name;
        if ($name !== '') return $name;
        if ($code !== '') return $code;
        return 'Monthly Accomplishment';
    }
}

if (!function_exists('teacher_activity_attendance_phase')) {
    /**
     * Determine effective attendance phase using live time windows.
     * Supports explicit phase hints (e.g. synthetic summary sessions).
     */
    function teacher_activity_attendance_phase(array $session, $nowTs = null) {
        $hint = strtolower(trim((string) ($session['_phase'] ?? ($session['phase'] ?? ''))));
        if (in_array($hint, ['upcoming', 'present_window', 'late_window', 'closed'], true)) {
            return $hint;
        }

        $nowTs = is_int($nowTs) ? $nowTs : time();

        if (function_exists('attendance_checkin_phase')) {
            $phase = (string) attendance_checkin_phase($session, $nowTs);
            if (in_array($phase, ['upcoming', 'present_window', 'late_window', 'closed'], true)) {
                return $phase;
            }
        }

        $startTs = strtotime((string) ($session['starts_at'] ?? ''));
        $presentUntilTs = strtotime((string) ($session['present_until'] ?? ''));
        $lateUntilTs = strtotime((string) ($session['late_until'] ?? ''));
        $isClosed = ((int) ($session['is_closed'] ?? 0) === 1);

        if ($startTs <= 0 || $presentUntilTs <= 0 || $lateUntilTs <= 0) {
            return $isClosed ? 'closed' : 'present_window';
        }
        if ($isClosed) return 'closed';
        if ($nowTs < $startTs) return 'upcoming';
        if ($nowTs <= $presentUntilTs) return 'present_window';
        if ($nowTs <= $lateUntilTs) return 'late_window';
        return 'closed';
    }
}

if (!function_exists('teacher_activity_gd_make_attendance_chart_png')) {
    function teacher_activity_gd_make_attendance_chart_png(array $session, array $roster) {
        // Prefer GD if present, but provide a no-GD PNG fallback (Laragon installs sometimes omit GD).
        if (!function_exists('imagecreatetruecolor')) {
            if (!function_exists('gzcompress') || !function_exists('crc32')) return '';

            $submittedPresent = 0;
            $submittedLate = 0;
            $missing = 0;
            foreach ($roster as $r) {
                if (!is_array($r)) continue;
                $st = strtolower(trim((string) ($r['submitted_status'] ?? '')));
                if ($st === 'present') $submittedPresent++;
                elseif ($st === 'late') $submittedLate++;
                else $missing++;
            }

            $phase = teacher_activity_attendance_phase($session);
            $isClosed = ($phase === 'closed');
            $absent = $isClosed ? $missing : 0;
            $pending = $isClosed ? 0 : $missing;

            $values = [$submittedPresent, $submittedLate, $isClosed ? $absent : $pending];
            $max = 1;
            foreach ($values as $v) $max = max($max, (int) $v);

            $w = 980;
            $h = 520;
            $padL = 90; $padR = 70; $padT = 90; $padB = 90;
            $chartX = $padL;
            $chartY = $padT;
            $chartW = $w - $padL - $padR;
            $chartH = $h - $padT - $padB;

            $bg = [255, 255, 255];
            $axis = [200, 200, 200];
            $border = [210, 210, 210];
            $blue = [54, 162, 235];
            $orange = [255, 159, 64];
            $red = [255, 99, 132];
            $gray = [120, 120, 120];
            $colors = [$blue, $orange, $isClosed ? $red : $gray];

            $barW = (int) floor($chartW / 6);
            $gap = (int) floor($barW / 2);
            $barXs = [];
            $x = $chartX + $gap;
            for ($i = 0; $i < 3; $i++) {
                $barXs[] = [$x, $x + $barW];
                $x += $barW + $gap;
            }

            $barRects = [];
            for ($i = 0; $i < 3; $i++) {
                $v = (int) $values[$i];
                $bh = (int) round(($v / $max) * max(1, ($chartH - 20)));
                $bx1 = $barXs[$i][0];
                $bx2 = $barXs[$i][1];
                $by2 = $chartY + $chartH;
                $by1 = $by2 - $bh;
                $barRects[] = [$bx1, $by1, $bx2, $by2, $colors[$i]];
            }

            $png_chunk = function ($type, $data) {
                $type = (string) $type;
                $data = (string) $data;
                $len = strlen($data);
                $crc = crc32($type . $data);
                // crc32 can be signed; ensure unsigned 32-bit.
                $crc = $crc & 0xFFFFFFFF;
                return pack('N', $len) . $type . $data . pack('N', $crc);
            };

            $raw = '';
            for ($yy = 0; $yy < $h; $yy++) {
                $raw .= "\x00"; // filter type 0
                $row = '';
                for ($xx = 0; $xx < $w; $xx++) {
                    $rgb = $bg;

                    // outer border
                    if ($xx === 0 || $yy === 0 || $xx === ($w - 1) || $yy === ($h - 1)) $rgb = $border;

                    // axes
                    $axisY = $chartY + $chartH;
                    if ($yy === $axisY && $xx >= $chartX && $xx <= ($chartX + $chartW)) $rgb = $axis;
                    if ($xx === $chartX && $yy >= $chartY && $yy <= ($chartY + $chartH)) $rgb = $axis;

                    // bars
                    foreach ($barRects as $br) {
                        [$bx1, $by1, $bx2, $by2, $c] = $br;
                        if ($xx >= $bx1 && $xx <= $bx2 && $yy >= $by1 && $yy <= $by2) {
                            $rgb = $c;
                            break;
    }
}

if (!function_exists('teacher_activity_queue_key')) {
    function teacher_activity_queue_key() {
        return 'pending_accomplishment_events';
    }
}

if (!function_exists('teacher_activity_queue_add')) {
    function teacher_activity_queue_add($eventId, $title, $date) {
        $eventId = (int) $eventId;
        if ($eventId <= 0) return false;
        $title = trim((string) $title);
        $date = trim((string) $date);
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) $date = date('Y-m-d');
        if ($title === '') $title = 'Class activity';
        if (strlen($title) > 255) $title = substr($title, 0, 255);

        $key = teacher_activity_queue_key();
        $q = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];

        // Deduplicate by event id.
        foreach ($q as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) === $eventId) return true;
        }

        $q[] = ['id' => $eventId, 'title' => $title, 'date' => $date];
        $_SESSION[$key] = $q;

        // Backward compatibility (single item).
        $_SESSION['pending_accomplishment_event'] = ['id' => $eventId, 'title' => $title, 'date' => $date];
        return true;
    }
}

if (!function_exists('teacher_activity_queue_peek')) {
    function teacher_activity_queue_peek() {
        $key = teacher_activity_queue_key();
        $q = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
        foreach ($q as $row) {
            if (!is_array($row)) continue;
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) continue;
            $title = trim((string) ($row['title'] ?? ''));
            $date = trim((string) ($row['date'] ?? ''));
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) $date = '';
            if ($title === '') $title = 'Class activity';
            return ['id' => $id, 'title' => $title, 'date' => $date];
        }
        return null;
    }
}

if (!function_exists('teacher_activity_queue_remove')) {
    function teacher_activity_queue_remove($eventId) {
        $eventId = (int) $eventId;
        if ($eventId <= 0) return;
        $key = teacher_activity_queue_key();
        $q = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
        $out = [];
        foreach ($q as $row) {
            if (!is_array($row)) continue;
            if ((int) ($row['id'] ?? 0) === $eventId) continue;
            $out[] = $row;
        }
        if (count($out) > 0) $_SESSION[$key] = $out;
        else unset($_SESSION[$key]);

        if (isset($_SESSION['pending_accomplishment_event']) && is_array($_SESSION['pending_accomplishment_event'])) {
            if ((int) ($_SESSION['pending_accomplishment_event']['id'] ?? 0) === $eventId) {
                unset($_SESSION['pending_accomplishment_event']);
            }
        }
    }
}

                    $row .= chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]);
                }
                $raw .= $row;
            }

            $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
            $idat = gzcompress($raw, 6);
            if (!is_string($idat) || $idat === '') return '';

            $png = "\x89PNG\r\n\x1a\n";
            $png .= $png_chunk('IHDR', $ihdr);
            $png .= $png_chunk('IDAT', $idat);
            $png .= $png_chunk('IEND', '');
            return $png;
        }

        $submittedPresent = 0;
        $submittedLate = 0;
        $missing = 0;
        foreach ($roster as $r) {
            if (!is_array($r)) continue;
            $st = strtolower(trim((string) ($r['submitted_status'] ?? '')));
            if ($st === 'present') $submittedPresent++;
            elseif ($st === 'late') $submittedLate++;
            else $missing++;
        }

        $phase = teacher_activity_attendance_phase($session);
        $isClosed = ($phase === 'closed');
        $absent = $isClosed ? $missing : 0;
        $pending = $isClosed ? 0 : $missing;

        $w = 980;
        $h = 520;
        $im = @imagecreatetruecolor($w, $h);
        if (!$im) return '';

        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 30, 30, 30);
        $gray = imagecolorallocate($im, 120, 120, 120);
        $blue = imagecolorallocate($im, 54, 162, 235);
        $orange = imagecolorallocate($im, 255, 159, 64);
        $red = imagecolorallocate($im, 255, 99, 132);
        $muted = imagecolorallocate($im, 200, 200, 200);

        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $white);
        imagerectangle($im, 0, 0, $w - 1, $h - 1, $muted);

        $sessionDate = trim((string) ($session['session_date'] ?? ''));
        $sessionLabel = trim((string) ($session['session_label'] ?? 'Attendance'));
        $method = strtoupper(trim((string) ($session['checkin_method'] ?? 'code')));
        if ($method === '') $method = 'CODE';

        $header = $sessionLabel . ' (' . $method . ')';
        $sub = 'Date: ' . ($sessionDate !== '' ? $sessionDate : date('Y-m-d')) . ' | Class size: ' . (int) count($roster) . ' | Generated: ' . date('Y-m-d H:i');
        imagestring($im, 5, 18, 14, $header, $black);
        imagestring($im, 3, 18, 40, $sub, $gray);

        $labels = ['Present', 'Late', $isClosed ? 'Absent' : 'Pending'];
        $values = [$submittedPresent, $submittedLate, $isClosed ? $absent : $pending];
        $colors = [$blue, $orange, $isClosed ? $red : $gray];

        $max = 1;
        foreach ($values as $v) $max = max($max, (int) $v);

        $chartX = 80;
        $chartY = 100;
        $chartW = $w - 140;
        $chartH = 320;

        imageline($im, $chartX, $chartY + $chartH, $chartX + $chartW, $chartY + $chartH, $muted);
        imageline($im, $chartX, $chartY, $chartX, $chartY + $chartH, $muted);

        $barW = (int) floor($chartW / 6);
        $gap = (int) floor($barW / 2);
        $x = $chartX + $gap;

        for ($i = 0; $i < count($values); $i++) {
            $v = (int) $values[$i];
            $bh = (int) round(($v / $max) * ($chartH - 30));
            $bx1 = $x;
            $bx2 = $x + $barW;
            $by2 = $chartY + $chartH;
            $by1 = $by2 - $bh;

            imagefilledrectangle($im, $bx1, $by1, $bx2, $by2, $colors[$i]);
            imagerectangle($im, $bx1, $by1, $bx2, $by2, $muted);
            imagestring($im, 5, $bx1 + 6, max($chartY + 4, $by1 - 18), (string) $v, $black);
            imagestring($im, 3, $bx1 + 2, $by2 + 8, $labels[$i], $black);
            $x += $barW + $gap;
        }

        if ($phase === 'upcoming') {
            $foot = 'Attendance window has not started yet. Missing submissions are currently Pending.';
        } elseif ($isClosed) {
            $foot = 'Attendance window is closed. Missing submissions are counted as Absent.';
        } else {
            $foot = 'Attendance window is open. Missing submissions are counted as Pending.';
        }
        imagestring($im, 3, 18, $h - 34, $foot, $gray);

        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        imagedestroy($im);
        return is_string($png) ? $png : '';
    }
}
