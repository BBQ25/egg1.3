<?php
// Helpers for applying teacher-created content to sibling sections
// (same subject + academic year + semester) assigned to the same teacher.

if (!function_exists('section_sync_get_teacher_peer_sections')) {
    function section_sync_get_teacher_peer_sections(
        mysqli $conn,
        $teacherId,
        $subjectId,
        $academicYear,
        $semester,
        $excludeClassRecordId = 0
    ) {
        $teacherId = (int) $teacherId;
        $subjectId = (int) $subjectId;
        $academicYear = trim((string) $academicYear);
        $semester = trim((string) $semester);
        $excludeClassRecordId = (int) $excludeClassRecordId;
        if ($teacherId <= 0 || $subjectId <= 0 || $academicYear === '' || $semester === '') return [];

        $rows = [];
        $stmt = $conn->prepare(
            "SELECT cr.id AS class_record_id,
                    cr.section
             FROM teacher_assignments ta
             JOIN class_records cr ON cr.id = ta.class_record_id
             WHERE ta.teacher_id = ?
               AND ta.status = 'active'
               AND cr.status = 'active'
               AND cr.subject_id = ?
               AND cr.academic_year = ?
               AND cr.semester = ?
               AND cr.id <> ?
             ORDER BY cr.section ASC, cr.id ASC"
        );
        if (!$stmt) return $rows;

        $stmt->bind_param('iissi', $teacherId, $subjectId, $academicYear, $semester, $excludeClassRecordId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) $rows[] = $row;
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('section_sync_peer_ids')) {
    function section_sync_peer_ids(array $peerSections) {
        $ids = [];
        foreach ($peerSections as $row) {
            $id = isset($row['class_record_id']) ? (int) $row['class_record_id'] : 0;
            if ($id > 0) $ids[$id] = $id;
        }
        return array_values($ids);
    }
}

if (!function_exists('section_sync_filter_selected_ids')) {
    function section_sync_filter_selected_ids($rawSelected, array $peerSections) {
        $allowed = [];
        foreach (section_sync_peer_ids($peerSections) as $id) $allowed[$id] = true;

        $selected = [];
        $list = is_array($rawSelected) ? $rawSelected : [];
        foreach ($list as $value) {
            $id = (int) $value;
            if ($id > 0 && isset($allowed[$id])) $selected[$id] = $id;
        }

        return array_values($selected);
    }
}

if (!function_exists('section_sync_ensure_preference_table')) {
    function section_sync_ensure_preference_table(mysqli $conn) {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS teacher_section_sync_preferences (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                teacher_id INT NOT NULL,
                subject_id INT NOT NULL,
                academic_year VARCHAR(50) NOT NULL,
                semester VARCHAR(50) NOT NULL,
                auto_apply TINYINT(1) NOT NULL DEFAULT 0,
                selected_class_record_ids_json LONGTEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tssp_teacher_subject_term (teacher_id, subject_id, academic_year, semester),
                KEY idx_tssp_teacher (teacher_id),
                KEY idx_tssp_subject_term (subject_id, academic_year, semester)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('section_sync_decode_ids_json')) {
    function section_sync_decode_ids_json($value) {
        $value = trim((string) $value);
        if ($value === '') return [];
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) return [];
        $ids = [];
        foreach ($decoded as $v) {
            $id = (int) $v;
            if ($id > 0) $ids[$id] = $id;
        }
        return array_values($ids);
    }
}

if (!function_exists('section_sync_get_preference')) {
    function section_sync_get_preference(mysqli $conn, $teacherId, $subjectId, $academicYear, $semester) {
        $teacherId = (int) $teacherId;
        $subjectId = (int) $subjectId;
        $academicYear = trim((string) $academicYear);
        $semester = trim((string) $semester);
        $default = [
            'auto_apply' => false,
            'selected_ids' => [],
        ];
        if ($teacherId <= 0 || $subjectId <= 0 || $academicYear === '' || $semester === '') return $default;

        section_sync_ensure_preference_table($conn);

        $stmt = $conn->prepare(
            "SELECT auto_apply, selected_class_record_ids_json
             FROM teacher_section_sync_preferences
             WHERE teacher_id = ?
               AND subject_id = ?
               AND academic_year = ?
               AND semester = ?
             LIMIT 1"
        );
        if (!$stmt) return $default;

        $stmt->bind_param('iiss', $teacherId, $subjectId, $academicYear, $semester);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows === 1) {
            $row = $res->fetch_assoc();
            $default['auto_apply'] = ((int) ($row['auto_apply'] ?? 0)) === 1;
            $default['selected_ids'] = section_sync_decode_ids_json($row['selected_class_record_ids_json'] ?? '');
        }
        $stmt->close();
        return $default;
    }
}

if (!function_exists('section_sync_save_preference')) {
    function section_sync_save_preference(mysqli $conn, $teacherId, $subjectId, $academicYear, $semester, array $selectedIds, $autoApply) {
        $teacherId = (int) $teacherId;
        $subjectId = (int) $subjectId;
        $academicYear = trim((string) $academicYear);
        $semester = trim((string) $semester);
        if ($teacherId <= 0 || $subjectId <= 0 || $academicYear === '' || $semester === '') return false;

        section_sync_ensure_preference_table($conn);

        $cleanIds = [];
        foreach ($selectedIds as $id) {
            $id = (int) $id;
            if ($id > 0) $cleanIds[$id] = $id;
        }
        $autoApply = !empty($autoApply) ? 1 : 0;
        $json = json_encode(array_values($cleanIds));
        if (!is_string($json)) $json = '[]';

        $stmt = $conn->prepare(
            "INSERT INTO teacher_section_sync_preferences
                (teacher_id, subject_id, academic_year, semester, auto_apply, selected_class_record_ids_json)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                auto_apply = VALUES(auto_apply),
                selected_class_record_ids_json = VALUES(selected_class_record_ids_json)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('iissis', $teacherId, $subjectId, $academicYear, $semester, $autoApply, $json);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('section_sync_preferred_peer_ids')) {
    function section_sync_preferred_peer_ids(array $preference, array $peerSections) {
        $peerAllowed = [];
        foreach (section_sync_peer_ids($peerSections) as $id) $peerAllowed[$id] = true;
        $selected = is_array($preference['selected_ids'] ?? null) ? $preference['selected_ids'] : [];
        $ids = [];
        foreach ($selected as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($peerAllowed[$id])) $ids[$id] = $id;
        }
        return array_values($ids);
    }
}

if (!function_exists('section_sync_resolve_target_ids')) {
    function section_sync_resolve_target_ids($rawSelected, array $peerSections, array $preference, $usePreferenceWhenEmpty = true) {
        $posted = section_sync_filter_selected_ids($rawSelected, $peerSections);
        if (count($posted) > 0) return $posted;
        if (!$usePreferenceWhenEmpty) return [];

        $autoApply = !empty($preference['auto_apply']);
        if (!$autoApply) return [];
        return section_sync_preferred_peer_ids($preference, $peerSections);
    }
}
