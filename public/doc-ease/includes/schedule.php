<?php
// Scheduling helpers: structured schedule slots + admin-approved change requests.

if (!function_exists('ensure_schedule_tables')) {
    function ensure_schedule_tables(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS schedule_slots (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                class_record_id INT NOT NULL,
                day_of_week TINYINT NOT NULL, /* 0=Sun .. 6=Sat */
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                room VARCHAR(60) NULL,
                modality ENUM('face_to_face','online','hybrid') NOT NULL DEFAULT 'face_to_face',
                notes VARCHAR(255) NULL,
                status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_sched_class_day (class_record_id, day_of_week),
                KEY idx_sched_day_time (day_of_week, start_time),
                CONSTRAINT fk_sched_class
                    FOREIGN KEY (class_record_id) REFERENCES class_records(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_sched_created_by
                    FOREIGN KEY (created_by) REFERENCES users(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS schedule_change_requests (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                requester_id INT NOT NULL,
                class_record_id INT NOT NULL,
                action ENUM('create','update','delete') NOT NULL,
                slot_id BIGINT NULL,
                payload_json TEXT NOT NULL,
                status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
                review_note TEXT NULL,
                requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL DEFAULT NULL,
                reviewed_by INT NULL DEFAULT NULL,
                KEY idx_scr_status_time (status, requested_at),
                KEY idx_scr_requester_status (requester_id, status),
                KEY idx_scr_class_status (class_record_id, status),
                CONSTRAINT fk_scr_requester
                    FOREIGN KEY (requester_id) REFERENCES users(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_scr_class
                    FOREIGN KEY (class_record_id) REFERENCES class_records(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_scr_reviewed_by
                    FOREIGN KEY (reviewed_by) REFERENCES users(id)
                    ON DELETE SET NULL,
                CONSTRAINT fk_scr_slot
                    FOREIGN KEY (slot_id) REFERENCES schedule_slots(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('schedule_day_label')) {
    function schedule_day_label($dow) {
        $dow = (int) $dow;
        $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return $names[$dow] ?? 'Day';
    }
}

if (!function_exists('schedule_day_short')) {
    function schedule_day_short($dow) {
        $dow = (int) $dow;
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        return $names[$dow] ?? 'Day';
    }
}

if (!function_exists('schedule_time_ok')) {
    function schedule_time_ok($t) {
        $t = trim((string) $t);
        // Accept HH:MM (or HH:MM:SS).
        return (bool) preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/', $t);
    }
}

if (!function_exists('schedule_teacher_has_class')) {
    function schedule_teacher_has_class(mysqli $conn, $teacherId, $classRecordId) {
        $teacherId = (int) $teacherId;
        $classRecordId = (int) $classRecordId;
        if ($teacherId <= 0 || $classRecordId <= 0) return false;

        $ok = false;
        $stmt = $conn->prepare(
            "SELECT 1
             FROM teacher_assignments ta
             JOIN class_records cr ON cr.id = ta.class_record_id
             WHERE ta.teacher_id = ? AND ta.class_record_id = ?
               AND ta.status = 'active' AND cr.status = 'active'
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $teacherId, $classRecordId);
            $stmt->execute();
            $res = $stmt->get_result();
            $ok = ($res && $res->num_rows === 1);
            $stmt->close();
        }
        return $ok;
    }
}

if (!function_exists('schedule_list_teacher_classes')) {
    function schedule_list_teacher_classes(mysqli $conn, $teacherId) {
        $teacherId = (int) $teacherId;
        $rows = [];
        if ($teacherId <= 0) return $rows;

        $stmt = $conn->prepare(
            "SELECT cr.id AS class_record_id,
                    cr.section, cr.academic_year, cr.semester,
                    s.subject_code, s.subject_name
             FROM teacher_assignments ta
             JOIN class_records cr ON cr.id = ta.class_record_id
             JOIN subjects s ON s.id = cr.subject_id
             WHERE ta.teacher_id = ? AND ta.status = 'active' AND cr.status = 'active'
             ORDER BY cr.academic_year DESC, cr.semester ASC, s.subject_name ASC, cr.section ASC
             LIMIT 200"
        );
        if ($stmt) {
            $stmt->bind_param('i', $teacherId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
            $stmt->close();
        }
        return $rows;
    }
}

if (!function_exists('schedule_list_slots_for_teacher')) {
    function schedule_list_slots_for_teacher(mysqli $conn, $teacherId) {
        $teacherId = (int) $teacherId;
        $rows = [];
        if ($teacherId <= 0) return $rows;

        $stmt = $conn->prepare(
            "SELECT ss.id AS slot_id, ss.class_record_id, ss.day_of_week, ss.start_time, ss.end_time,
                    ss.room, ss.modality, ss.notes,
                    cr.section, cr.academic_year, cr.semester,
                    s.subject_code, s.subject_name
             FROM schedule_slots ss
             JOIN class_records cr ON cr.id = ss.class_record_id
             JOIN subjects s ON s.id = cr.subject_id
             JOIN teacher_assignments ta ON ta.class_record_id = cr.id
             WHERE ta.teacher_id = ? AND ta.status = 'active'
               AND cr.status = 'active'
               AND ss.status = 'active'
             ORDER BY ss.day_of_week ASC, ss.start_time ASC, s.subject_name ASC, cr.section ASC"
        );
        if ($stmt) {
            $stmt->bind_param('i', $teacherId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
            $stmt->close();
        }
        return $rows;
    }
}

if (!function_exists('schedule_list_slots_for_class')) {
    function schedule_list_slots_for_class(mysqli $conn, $classRecordId) {
        $classRecordId = (int) $classRecordId;
        $rows = [];
        if ($classRecordId <= 0) return $rows;

        $stmt = $conn->prepare(
            "SELECT id AS slot_id, class_record_id, day_of_week, start_time, end_time, room, modality, notes, status
             FROM schedule_slots
             WHERE class_record_id = ?
             ORDER BY status = 'active' DESC, day_of_week ASC, start_time ASC, id ASC"
        );
        if ($stmt) {
            $stmt->bind_param('i', $classRecordId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
            $stmt->close();
        }
        return $rows;
    }
}

if (!function_exists('schedule_update_class_record_summary')) {
    function schedule_update_class_record_summary(mysqli $conn, $classRecordId) {
        $classRecordId = (int) $classRecordId;
        if ($classRecordId <= 0) return;

        $slots = schedule_list_slots_for_class($conn, $classRecordId);
        $active = array_values(array_filter($slots, function ($s) {
            return (string) ($s['status'] ?? '') === 'active';
        }));

        $parts = [];
        $rooms = [];
        foreach ($active as $s) {
            $dow = (int) ($s['day_of_week'] ?? 0);
            $st = substr((string) ($s['start_time'] ?? ''), 0, 5);
            $et = substr((string) ($s['end_time'] ?? ''), 0, 5);
            $p = schedule_day_short($dow) . ' ' . $st . '-' . $et;
            $room = trim((string) ($s['room'] ?? ''));
            if ($room !== '') {
                $p .= ' (' . $room . ')';
                $rooms[$room] = true;
            }
            $parts[] = $p;
        }

        $summary = implode('; ', $parts);

        $stmt = $conn->prepare("UPDATE class_records SET schedule = ? WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('si', $summary, $classRecordId);
            try { $stmt->execute(); } catch (Throwable $e) { /* ignore */ }
            $stmt->close();
        }

        // If exactly one room is used and room_number is empty, populate it.
        if (count($rooms) === 1) {
            $onlyRoom = array_key_first($rooms);
            if (is_string($onlyRoom) && $onlyRoom !== '') {
                $q = $conn->prepare("SELECT room_number FROM class_records WHERE id = ? LIMIT 1");
                $roomNumber = null;
                if ($q) {
                    $q->bind_param('i', $classRecordId);
                    $q->execute();
                    $res = $q->get_result();
                    if ($res && $res->num_rows === 1) $roomNumber = (string) ($res->fetch_assoc()['room_number'] ?? '');
                    $q->close();
                }
                if (trim((string) $roomNumber) === '') {
                    $u = $conn->prepare("UPDATE class_records SET room_number = ? WHERE id = ? LIMIT 1");
                    if ($u) {
                        $u->bind_param('si', $onlyRoom, $classRecordId);
                        try { $u->execute(); } catch (Throwable $e) { /* ignore */ }
                        $u->close();
                    }
                }
            }
        }
    }
}

if (!function_exists('schedule_create_change_request')) {
    /**
     * Returns [ok(bool), messageOrId(mixed)].
     */
    function schedule_create_change_request(mysqli $conn, $requesterId, $classRecordId, $action, array $payload, $slotId = null) {
        $requesterId = (int) $requesterId;
        $classRecordId = (int) $classRecordId;
        $action = (string) $action;
        $slotId = $slotId !== null ? (int) $slotId : null;

        if ($requesterId <= 0 || $classRecordId <= 0) return [false, 'Invalid request.'];
        if (!in_array($action, ['create', 'update', 'delete'], true)) return [false, 'Invalid action.'];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) $json = '{}';

        $stmt = $conn->prepare(
            "INSERT INTO schedule_change_requests (requester_id, class_record_id, action, slot_id, payload_json, status)
             VALUES (?, ?, ?, ?, ?, 'pending')"
        );
        if (!$stmt) return [false, 'Unable to prepare request.'];

        $slotIdParam = $slotId !== null && $slotId > 0 ? $slotId : null;
        $stmt->bind_param('iisis', $requesterId, $classRecordId, $action, $slotIdParam, $json);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $newId = $ok ? (int) $conn->insert_id : 0;
        $stmt->close();

        return $ok ? [true, $newId] : [false, 'Unable to submit request.'];
    }
}

if (!function_exists('schedule_cancel_change_request')) {
    function schedule_cancel_change_request(mysqli $conn, $requesterId, $requestId) {
        $requesterId = (int) $requesterId;
        $requestId = (int) $requestId;
        if ($requesterId <= 0 || $requestId <= 0) return false;

        $stmt = $conn->prepare(
            "UPDATE schedule_change_requests
             SET status = 'cancelled', reviewed_at = CURRENT_TIMESTAMP, reviewed_by = ?
             WHERE id = ? AND requester_id = ? AND status = 'pending'"
        );
        if (!$stmt) return false;
        $stmt->bind_param('iii', $requesterId, $requestId, $requesterId);
        $ok = false;
        try { $ok = $stmt->execute(); } catch (Throwable $e) { $ok = false; }
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('schedule_apply_change_request')) {
    /**
     * Admin: approve/reject a request. When approving, applies the change to schedule_slots.
     * Returns [ok(bool), message(string)].
     */
    function schedule_apply_change_request(mysqli $conn, $requestId, $adminId, $approve, $reviewNote = '') {
        $requestId = (int) $requestId;
        $adminId = (int) $adminId;
        $approve = (bool) $approve;
        $reviewNote = trim((string) $reviewNote);

        if ($requestId <= 0 || $adminId <= 0) return [false, 'Invalid request.'];

        $req = null;
        $stmt = $conn->prepare(
            "SELECT id, requester_id, class_record_id, action, slot_id, payload_json, status
             FROM schedule_change_requests
             WHERE id = ?
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) $req = $res->fetch_assoc();
            $stmt->close();
        }
        if (!$req) return [false, 'Request not found.'];
        if ((string) ($req['status'] ?? '') !== 'pending') return [false, 'Request is not pending.'];

        $classRecordId = (int) ($req['class_record_id'] ?? 0);
        $action = (string) ($req['action'] ?? '');
        $slotId = isset($req['slot_id']) ? (int) ($req['slot_id'] ?? 0) : 0;

        $payload = json_decode((string) ($req['payload_json'] ?? ''), true);
        if (!is_array($payload)) $payload = [];

        $conn->begin_transaction();
        try {
            if ($approve) {
                if ($action === 'create') {
                    $dow = (int) ($payload['day_of_week'] ?? -1);
                    $st = (string) ($payload['start_time'] ?? '');
                    $et = (string) ($payload['end_time'] ?? '');
                    $room = isset($payload['room']) ? trim((string) $payload['room']) : '';
                    $modality = isset($payload['modality']) ? (string) $payload['modality'] : 'face_to_face';
                    $notes = isset($payload['notes']) ? trim((string) $payload['notes']) : '';

                    if ($dow < 0 || $dow > 6 || !schedule_time_ok($st) || !schedule_time_ok($et) || $st >= $et) {
                        throw new RuntimeException('Invalid slot payload.');
                    }
                    if (!in_array($modality, ['face_to_face', 'online', 'hybrid'], true)) $modality = 'face_to_face';

                    $ins = $conn->prepare(
                        "INSERT INTO schedule_slots (class_record_id, day_of_week, start_time, end_time, room, modality, notes, status, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)"
                    );
                    if ($ins) {
                        $roomParam = ($room !== '') ? $room : null;
                        $notesParam = ($notes !== '') ? $notes : null;
                        $ins->bind_param('iisssssi', $classRecordId, $dow, $st, $et, $roomParam, $modality, $notesParam, $adminId);
                        $ins->execute();
                        $ins->close();
                    }
                } elseif ($action === 'update') {
                    if ($slotId <= 0) throw new RuntimeException('Missing slot.');
                    $dow = (int) ($payload['day_of_week'] ?? -1);
                    $st = (string) ($payload['start_time'] ?? '');
                    $et = (string) ($payload['end_time'] ?? '');
                    $room = isset($payload['room']) ? trim((string) $payload['room']) : '';
                    $modality = isset($payload['modality']) ? (string) $payload['modality'] : 'face_to_face';
                    $notes = isset($payload['notes']) ? trim((string) $payload['notes']) : '';

                    if ($dow < 0 || $dow > 6 || !schedule_time_ok($st) || !schedule_time_ok($et) || $st >= $et) {
                        throw new RuntimeException('Invalid slot payload.');
                    }
                    if (!in_array($modality, ['face_to_face', 'online', 'hybrid'], true)) $modality = 'face_to_face';

                    $upd = $conn->prepare(
                        "UPDATE schedule_slots
                         SET day_of_week = ?, start_time = ?, end_time = ?, room = ?, modality = ?, notes = ?
                         WHERE id = ? AND class_record_id = ?
                         LIMIT 1"
                    );
                    if ($upd) {
                        $roomParam = ($room !== '') ? $room : null;
                        $notesParam = ($notes !== '') ? $notes : null;
                        $upd->bind_param('isssssii', $dow, $st, $et, $roomParam, $modality, $notesParam, $slotId, $classRecordId);
                        $upd->execute();
                        $upd->close();
                    }
                } elseif ($action === 'delete') {
                    if ($slotId <= 0) throw new RuntimeException('Missing slot.');
                    $upd = $conn->prepare("UPDATE schedule_slots SET status = 'inactive' WHERE id = ? AND class_record_id = ? LIMIT 1");
                    if ($upd) {
                        $upd->bind_param('ii', $slotId, $classRecordId);
                        $upd->execute();
                        $upd->close();
                    }
                }
            }

            $newStatus = $approve ? 'approved' : 'rejected';
            $u = $conn->prepare(
                "UPDATE schedule_change_requests
                 SET status = ?, review_note = ?, reviewed_at = CURRENT_TIMESTAMP, reviewed_by = ?
                 WHERE id = ? AND status = 'pending'"
            );
            if ($u) {
                $u->bind_param('ssii', $newStatus, $reviewNote, $adminId, $requestId);
                $u->execute();
                $u->close();
            }

            if ($approve) {
                schedule_update_class_record_summary($conn, $classRecordId);
            }

            $conn->commit();
            return [true, $approve ? 'Request approved.' : 'Request rejected.'];
        } catch (Throwable $e) {
            $conn->rollback();
            return [false, 'Update failed.'];
        }
    }
}

