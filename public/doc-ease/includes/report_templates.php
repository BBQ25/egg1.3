<?php
// Superadmin-managed report templates (HTML/CSS) used for PDF/DOCX exports.

if (!function_exists('report_templates_ensure_tables')) {
    function report_templates_ensure_tables(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query(
            "CREATE TABLE IF NOT EXISTS report_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_key VARCHAR(64) NOT NULL,
                name VARCHAR(120) NOT NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                doc_code VARCHAR(64) NOT NULL DEFAULT '',
                revision VARCHAR(64) NOT NULL DEFAULT '',
                issue_date VARCHAR(120) NOT NULL DEFAULT '',
                page_format ENUM('A4','LETTER','LEGAL','CUSTOM') NOT NULL DEFAULT 'A4',
                orientation ENUM('portrait','landscape') NOT NULL DEFAULT 'portrait',
                page_width_mm DECIMAL(8,2) NULL,
                page_height_mm DECIMAL(8,2) NULL,
                margin_top_mm DECIMAL(8,2) NOT NULL DEFAULT 10.00,
                margin_right_mm DECIMAL(8,2) NOT NULL DEFAULT 10.00,
                margin_bottom_mm DECIMAL(8,2) NOT NULL DEFAULT 10.00,
                margin_left_mm DECIMAL(8,2) NOT NULL DEFAULT 10.00,
                header_height_mm DECIMAL(8,2) NOT NULL DEFAULT 20.00,
                footer_height_mm DECIMAL(8,2) NOT NULL DEFAULT 15.00,
                header_html MEDIUMTEXT NOT NULL,
                footer_html MEDIUMTEXT NOT NULL,
                body_html MEDIUMTEXT NOT NULL,
                css MEDIUMTEXT NOT NULL,
                sample_data_json MEDIUMTEXT NULL,
                created_by INT NULL,
                updated_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_report_templates_key (template_key),
                KEY idx_report_templates_status (status),
                KEY idx_report_templates_updated_at (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('report_templates_page_presets_mm')) {
    function report_templates_page_presets_mm() {
        return [
            'A4' => ['w' => 210.0, 'h' => 297.0],
            'LETTER' => ['w' => 215.9, 'h' => 279.4], // 8.5 x 11 in
            'LEGAL' => ['w' => 215.9, 'h' => 355.6],  // 8.5 x 14 in
        ];
    }
}

if (!function_exists('report_templates_resolve_page_mm')) {
    function report_templates_resolve_page_mm(array $tpl) {
        $format = strtoupper(trim((string) ($tpl['page_format'] ?? 'A4')));
        $orientation = strtolower(trim((string) ($tpl['orientation'] ?? 'portrait')));
        if (!in_array($orientation, ['portrait', 'landscape'], true)) $orientation = 'portrait';

        $presets = report_templates_page_presets_mm();
        $w = 210.0;
        $h = 297.0;
        if ($format === 'CUSTOM') {
            $cw = (float) ($tpl['page_width_mm'] ?? 0);
            $ch = (float) ($tpl['page_height_mm'] ?? 0);
            if ($cw > 0 && $ch > 0) {
                $w = $cw;
                $h = $ch;
            }
        } elseif (isset($presets[$format])) {
            $w = (float) ($presets[$format]['w'] ?? $w);
            $h = (float) ($presets[$format]['h'] ?? $h);
        }

        if ($orientation === 'landscape') {
            [$w, $h] = [$h, $w];
        }

        return [max(50.0, $w), max(50.0, $h)];
    }
}

if (!function_exists('report_template_default_sample_json')) {
    function report_template_default_sample_json() {
        $sample = [
            'title' => 'Monthly Accomplishment Report',
            'subtitle' => 'Embedded Systems Programming',
            'month_label' => 'February',
            'year_label' => '2026',
            'school_year_term' => '2025 - 2026 2nd Semester',
            'rows_html' => '<tr>'
                . '<td>Feb 2 - 8, 2026</td>'
                . '<td>'
                . '<div class="entry-block">'
                . '<p class="entry-title">Lecture</p>'
                . '<ul class="entry-details-list">'
                . '<li class="entry-details-item">Topic: Introduction to embedded systems</li>'
                . '<li class="entry-details-item">Quiz 1 administered</li>'
                . '</ul>'
                . '<p class="entry-proof-meta">Proof images attached: 2</p>'
                . '</div>'
                . '<div class="entry-block">'
                . '<p class="entry-title">Laboratory</p>'
                . '<p class="entry-details">Installed toolchain and ran first lab exercises.</p>'
                . '</div>'
                . '</td>'
                . '<td>'
                . '<div class="remarks-note">'
                . '<ul class="remarks-list">'
                . '<li class="remarks-item">On-track with the syllabus.</li>'
                . '<li class="remarks-item">One class moved due to a campus event.</li>'
                . '</ul>'
                . '</div>'
                . '</td>'
                . '</tr>'
                . '<tr>'
                . '<td>Feb 9 - 15, 2026</td>'
                . '<td>'
                . '<div class="entry-block">'
                . '<p class="entry-title">Lecture</p>'
                . '<p class="entry-details">Discussed sensors and microcontroller I/O.</p>'
                . '</div>'
                . '</td>'
                . '<td>'
                . '<div class="remarks-note">'
                . '<ul class="remarks-list">'
                . '<li class="remarks-item">Students requested extra examples for the next lab.</li>'
                . '</ul>'
                . '</div>'
                . '</td>'
                . '</tr>',
        ];
        return json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('report_template_default_fields')) {
    function report_template_default_fields() {
        $defaultCss = trim((string) implode("\n", [
            ':root {',
            '  /* --page-w / --page-h are injected dynamically by the renderer. */',
            '  --template-page-px-w: 1650;',
            '  --template-header-px-w: 1050;',
            '  --font-main: "Cambria Math", "Cambria", "Times New Roman", serif;',
            '  --ink: #111827;',
            '  --muted: #4b5563;',
            '}',
            '',
            '.rt-body {',
            '  font-family: var(--font-main);',
            '  color: var(--ink);',
            '  font-size: 12pt;',
            '  line-height: 1.2;',
            '}',
            '',
            '.rt-header, .rt-footer {',
            '  font-family: var(--font-main);',
            '  color: var(--ink);',
            '}',
            '.rt-footer { overflow: visible; }',
            '',
            '.template-header {',
            '  text-align: center;',
            '}',
            '.template-header-strip {',
            '  width: calc(var(--page-w) * var(--template-header-px-w) / var(--template-page-px-w));',
            '  max-width: 100%;',
            '  height: auto;',
            '  object-fit: contain;',
            '  display: block;',
            '  margin: 0 auto;',
            '}',
            '',
            '.footer-meta {',
            '  position: absolute;',
            '  left: 5mm;',
            '  bottom: 0;',
            '  font-size: 8.5pt;',
            '  color: var(--ink);',
            '  line-height: 1.35;',
            '  font-family: var(--font-main);',
            '  text-align: left;',
            '}',
            '.template-footer {',
            '  position: absolute;',
            '  right: 5mm;',
            '  bottom: -0.2mm; /* match accomplishment template footer baseline */',
            '  line-height: 1;',
            '  display: flex;',
            '  align-items: flex-end;',
            '  gap: 2.6mm;',
            '  overflow: visible;',
            '}',
            '.template-footer-qs {',
            '  width: auto;',
            '  height: 19.8mm;',
            '  max-width: none;',
            '  object-fit: contain;',
            '  object-position: center bottom;',
            '  display: block;',
            '}',
            '.template-footer-socotec {',
            '  width: 38.9mm;',
            '  max-width: none;',
            '  height: auto;',
            '  object-fit: contain;',
            '  display: block;',
            '}',
            '',
            '.rt-title {',
            '  text-align: center;',
            '  font-size: 14pt;',
            '  font-weight: 700;',
            '  letter-spacing: 0;',
            '  margin: 0;',
            '  text-transform: uppercase;',
            '}',
            '.rt-subtitle {',
            '  text-align: center;',
            '  font-size: 12pt;',
            '  font-weight: 700;',
            '  margin: 0.6mm 0 0;',
            '}',
            '.report-meta {',
            '  text-align: center;',
            '  font-size: 11pt;',
            '  margin-top: 0.8mm;',
            '  line-height: 1.15;',
            '}',
            '.report-meta + .report-meta {',
            '  margin-top: 0.5mm;',
            '}',
            '.rt-content {',
            '  margin-top: 4mm;',
            '}',
            '.table-wrap {',
            '  margin-top: 4.2mm;',
            '}',
            '',
            '.rt-table {',
            '  width: 100%;',
            '  border-collapse: collapse;',
            '  table-layout: fixed;',
            '  font-size: 11pt;',
            '  line-height: 1.16;',
            '}',
            '.rt-table th, .rt-table td {',
            '  border: 1px solid #000;',
            '  padding: 2mm 2.5mm;',
            '  vertical-align: top;',
            '}',
            '.rt-table th {',
            '  text-align: center;',
            '  font-size: 11pt;',
            '  font-weight: 700;',
            '  line-height: 1.12;',
            '}',
            '/* Match the Monthly Accomplishment column proportions. */',
            '.col-date { width: 13.9%; }',
            '.col-acc { width: 69.4%; }',
            '.col-rem { width: 16.7%; }',
            '.remarks-head-note {',
            '  display: block;',
            '  margin-top: 0.5mm;',
            '  font-weight: 400;',
            '  font-size: 10.5pt;',
            '  line-height: 1.14;',
            '}',
            '',
            '.entry-block + .entry-block {',
            '  margin-top: 1.6mm;',
            '  padding-top: 1.6mm;',
            '  border-top: 1px solid rgba(0, 0, 0, 0.18);',
            '}',
            '.entry-title {',
            '  font-weight: 700;',
            '  margin: 0 0 0.8mm;',
            '  line-height: 1.14;',
            '}',
            '.entry-details-list {',
            '  margin: 0;',
            '  padding-left: 4.4mm;',
            '}',
            '.entry-details-item {',
            '  white-space: pre-wrap;',
            '  line-height: 1.16;',
            '  margin: 0;',
            '}',
            '.entry-details-item + .entry-details-item {',
            '  margin-top: 0.95mm;',
            '}',
            '.entry-details {',
            '  white-space: pre-wrap;',
            '  margin: 0;',
            '  line-height: 1.16;',
            '}',
            '.entry-proof-meta {',
            '  margin: 0.8mm 0 0;',
            '  font-size: 10pt;',
            '  font-style: italic;',
            '  color: #374151;',
            '}',
            '.remarks-note {',
            '  font-size: 10.9pt;',
            '  line-height: 1.16;',
            '}',
            '.remarks-list {',
            '  margin: 0;',
            '  padding-left: 4.2mm;',
            '}',
            '.remarks-item {',
            '  white-space: pre-wrap;',
            '  line-height: 1.16;',
            '}',
            '.proof-note {',
            '  font-size: 10.5pt;',
            '  font-style: italic;',
            '  margin: 3.5mm 0 0;',
            '}',
        ]));

        $defaultHeader = trim((string) implode("\n", [
            '<div class="template-header">',
            '  <img class="template-header-strip" src="assets/images/report-template/header-strip-template.png" alt="Report Header">',
            '</div>',
        ]));

        $defaultFooter = trim((string) implode("\n", [
            '<div class="footer-meta">',
            '  Doc. Code: {{template.doc_code}}<br>',
            '  Revision: {{template.revision}}<br>',
            '  Date: {{template.issue_date}}',
            '</div>',
            '<div class="template-footer">',
            '  <img class="template-footer-qs" src="assets/images/report-template/image17.png" alt="QS Rated Good">',
            '  <img class="template-footer-socotec" src="assets/images/report-template/image18.png" alt="Socotec ISO 9001:2015">',
            '</div>',
        ]));

        $defaultBody = trim((string) implode("\n", [
            '<h1 class="rt-title">{{title}}</h1>',
            '<p class="rt-subtitle">{{subtitle}}</p>',
            '<div class="report-meta">Month: {{month_label}} - Year: {{year_label}}</div>',
            '<div class="report-meta">School Year: {{school_year_term}}</div>',
            '',
            '<div class="rt-content">',
            '  <div class="table-wrap">',
            '    <table class="rt-table">',
            '      <thead>',
            '        <tr>',
            '          <th class="col-date">DATE</th>',
            '          <th class="col-acc">ACCOMPLISHMENT</th>',
            '          <th class="col-rem">REMARKS<br><span class="remarks-head-note">(Progress and deviations from the plan) *</span></th>',
            '        </tr>',
            '      </thead>',
            '      <tbody>',
            '        {{{rows_html}}}',
            '      </tbody>',
            '    </table>',
            '  </div>',
            '  <p class="proof-note">*Provide relevant documentation such as pictures, summary reports, etc.</p>',
            '</div>',
        ]));

        return [
            'id' => 0,
            'template_key' => '',
            'name' => '',
            'description' => '',
            'status' => 'active',
            'doc_code' => 'SLSU-QF-IN41',
            'revision' => '01',
            'issue_date' => '14 October 2019',
            'page_format' => 'A4',
            'orientation' => 'landscape',
            'page_width_mm' => null,
            'page_height_mm' => null,
            'margin_top_mm' => 9.0,
            'margin_right_mm' => 13.0,
            'margin_bottom_mm' => 8.6,
            'margin_left_mm' => 13.0,
            'header_height_mm' => 44.0,
            'footer_height_mm' => 35.0,
            'header_html' => $defaultHeader,
            'footer_html' => $defaultFooter,
            'body_html' => $defaultBody,
            'css' => $defaultCss,
            'sample_data_json' => report_template_default_sample_json(),
        ];
    }
}

if (!function_exists('report_template_parse_sample_json')) {
    function report_template_parse_sample_json($json, &$errorMsg = '') {
        $errorMsg = '';
        $json = trim((string) $json);
        if ($json === '') {
            $out = json_decode((string) report_template_default_sample_json(), true);
            return is_array($out) ? $out : [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $errorMsg = 'Invalid JSON. Using default sample data.';
            $out = json_decode((string) report_template_default_sample_json(), true);
            return is_array($out) ? $out : [];
        }
        return $decoded;
    }
}

if (!function_exists('report_template_slugify')) {
    function report_template_slugify($text) {
        $text = strtolower(trim((string) $text));
        if ($text === '') return '';
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim((string) $text, '-');
        if ($text === '') return '';
        if (strlen($text) > 64) $text = substr($text, 0, 64);
        return $text;
    }
}

if (!function_exists('report_template_key_exists')) {
    function report_template_key_exists(mysqli $conn, $templateKey, $excludeId = 0) {
        $templateKey = trim((string) $templateKey);
        $excludeId = (int) $excludeId;
        if ($templateKey === '') return false;

        $sql = "SELECT id FROM report_templates WHERE template_key = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param('s', $templateKey);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        if ($id <= 0) return false;
        if ($excludeId > 0 && $id === $excludeId) return false;
        return true;
    }
}

if (!function_exists('report_template_build_unique_key')) {
    function report_template_build_unique_key(mysqli $conn, $baseKey, $excludeId = 0) {
        $baseKey = report_template_slugify($baseKey);
        if ($baseKey === '') $baseKey = 'template';

        $key = $baseKey;
        $n = 2;
        while (report_template_key_exists($conn, $key, $excludeId)) {
            $suffix = '-' . $n;
            $trimTo = 64 - strlen($suffix);
            $key = substr($baseKey, 0, max(1, $trimTo)) . $suffix;
            $n++;
            if ($n > 500) break;
        }
        return $key;
    }
}

if (!function_exists('report_templates_list')) {
    function report_templates_list(mysqli $conn) {
        report_templates_ensure_tables($conn);
        $rows = [];
        $res = $conn->query(
            "SELECT id, template_key, name, status, updated_at
             FROM report_templates
             ORDER BY updated_at DESC, id DESC"
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = [
                    'id' => (int) ($r['id'] ?? 0),
                    'template_key' => (string) ($r['template_key'] ?? ''),
                    'name' => (string) ($r['name'] ?? ''),
                    'status' => (string) ($r['status'] ?? 'active'),
                    'updated_at' => (string) ($r['updated_at'] ?? ''),
                ];
            }
        }
        return $rows;
    }
}

if (!function_exists('report_templates_get')) {
    function report_templates_get(mysqli $conn, $id) {
        report_templates_ensure_tables($conn);
        $id = (int) $id;
        if ($id <= 0) return null;

        $stmt = $conn->prepare("SELECT * FROM report_templates WHERE id = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('report_template_insert')) {
    function report_template_insert(mysqli $conn, array $data, $userId, &$errorMsg = '') {
        $errorMsg = '';
        report_templates_ensure_tables($conn);

        $userId = (int) $userId;
        $key = (string) ($data['template_key'] ?? '');
        $key = report_template_build_unique_key($conn, $key !== '' ? $key : (string) ($data['name'] ?? ''), 0);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errorMsg = 'Template name is required.';
            return 0;
        }

        $description = (string) ($data['description'] ?? '');
        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

        $docCode = (string) ($data['doc_code'] ?? '');
        $revision = (string) ($data['revision'] ?? '');
        $issueDate = (string) ($data['issue_date'] ?? '');
        $pageFormat = strtoupper((string) ($data['page_format'] ?? 'A4'));
        if (!in_array($pageFormat, ['A4', 'LETTER', 'LEGAL', 'CUSTOM'], true)) $pageFormat = 'A4';
        $orientation = strtolower((string) ($data['orientation'] ?? 'portrait'));
        if (!in_array($orientation, ['portrait', 'landscape'], true)) $orientation = 'portrait';

        $pageW = $data['page_width_mm'] ?? null;
        $pageH = $data['page_height_mm'] ?? null;
        if ($pageFormat !== 'CUSTOM') {
            $pageW = null;
            $pageH = null;
        }

        $mt = (string) ($data['margin_top_mm'] ?? '10');
        $mr = (string) ($data['margin_right_mm'] ?? '10');
        $mb = (string) ($data['margin_bottom_mm'] ?? '10');
        $ml = (string) ($data['margin_left_mm'] ?? '10');
        $hh = (string) ($data['header_height_mm'] ?? '20');
        $fh = (string) ($data['footer_height_mm'] ?? '15');

        $headerHtml = (string) ($data['header_html'] ?? '');
        $footerHtml = (string) ($data['footer_html'] ?? '');
        $bodyHtml = (string) ($data['body_html'] ?? '');
        $css = (string) ($data['css'] ?? '');
        $sampleJson = (string) ($data['sample_data_json'] ?? '');

        $stmt = $conn->prepare(
            "INSERT INTO report_templates (
                template_key, name, description, status,
                doc_code, revision, issue_date,
                page_format, orientation, page_width_mm, page_height_mm,
                margin_top_mm, margin_right_mm, margin_bottom_mm, margin_left_mm,
                header_height_mm, footer_height_mm,
                header_html, footer_html, body_html, css,
                sample_data_json, created_by, updated_by
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?
            )"
        );
        if (!$stmt) {
            $errorMsg = 'DB error: unable to prepare insert.';
            return 0;
        }

        $stmt->bind_param(
            'ssssssssssssssssssssssii',
            $key,
            $name,
            $description,
            $status,
            $docCode,
            $revision,
            $issueDate,
            $pageFormat,
            $orientation,
            $pageW,
            $pageH,
            $mt,
            $mr,
            $mb,
            $ml,
            $hh,
            $fh,
            $headerHtml,
            $footerHtml,
            $bodyHtml,
            $css,
            $sampleJson,
            $userId,
            $userId
        );

        try {
            $ok = $stmt->execute();
            $newId = $ok ? (int) $stmt->insert_id : 0;
            $stmt->close();
            if ($newId <= 0) {
                $errorMsg = 'Insert failed.';
                return 0;
            }
            return $newId;
        } catch (mysqli_sql_exception $e) {
            $stmt->close();
            $errorMsg = 'Insert failed: ' . $e->getMessage();
            return 0;
        }
    }
}

if (!function_exists('report_template_update')) {
    function report_template_update(mysqli $conn, $id, array $data, $userId, &$errorMsg = '') {
        $errorMsg = '';
        report_templates_ensure_tables($conn);

        $id = (int) $id;
        $userId = (int) $userId;
        if ($id <= 0) {
            $errorMsg = 'Invalid template.';
            return false;
        }

        $existing = report_templates_get($conn, $id);
        if (!$existing) {
            $errorMsg = 'Template not found.';
            return false;
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errorMsg = 'Template name is required.';
            return false;
        }

        $keyIn = trim((string) ($data['template_key'] ?? ''));
        if ($keyIn === '') $keyIn = (string) ($existing['template_key'] ?? '');
        $key = report_template_build_unique_key($conn, $keyIn !== '' ? $keyIn : $name, $id);

        $description = (string) ($data['description'] ?? '');
        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';

        $docCode = (string) ($data['doc_code'] ?? '');
        $revision = (string) ($data['revision'] ?? '');
        $issueDate = (string) ($data['issue_date'] ?? '');
        $pageFormat = strtoupper((string) ($data['page_format'] ?? 'A4'));
        if (!in_array($pageFormat, ['A4', 'LETTER', 'LEGAL', 'CUSTOM'], true)) $pageFormat = 'A4';
        $orientation = strtolower((string) ($data['orientation'] ?? 'portrait'));
        if (!in_array($orientation, ['portrait', 'landscape'], true)) $orientation = 'portrait';

        $pageW = $data['page_width_mm'] ?? null;
        $pageH = $data['page_height_mm'] ?? null;
        if ($pageFormat !== 'CUSTOM') {
            $pageW = null;
            $pageH = null;
        }

        $mt = (string) ($data['margin_top_mm'] ?? '10');
        $mr = (string) ($data['margin_right_mm'] ?? '10');
        $mb = (string) ($data['margin_bottom_mm'] ?? '10');
        $ml = (string) ($data['margin_left_mm'] ?? '10');
        $hh = (string) ($data['header_height_mm'] ?? '20');
        $fh = (string) ($data['footer_height_mm'] ?? '15');

        $headerHtml = (string) ($data['header_html'] ?? '');
        $footerHtml = (string) ($data['footer_html'] ?? '');
        $bodyHtml = (string) ($data['body_html'] ?? '');
        $css = (string) ($data['css'] ?? '');
        $sampleJson = (string) ($data['sample_data_json'] ?? '');

        $stmt = $conn->prepare(
            "UPDATE report_templates
             SET template_key = ?, name = ?, description = ?, status = ?,
                 doc_code = ?, revision = ?, issue_date = ?,
                 page_format = ?, orientation = ?, page_width_mm = ?, page_height_mm = ?,
                 margin_top_mm = ?, margin_right_mm = ?, margin_bottom_mm = ?, margin_left_mm = ?,
                 header_height_mm = ?, footer_height_mm = ?,
                 header_html = ?, footer_html = ?, body_html = ?, css = ?,
                 sample_data_json = ?, updated_by = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            $errorMsg = 'DB error: unable to prepare update.';
            return false;
        }

        $stmt->bind_param(
            'ssssssssssssssssssssssii',
            $key,
            $name,
            $description,
            $status,
            $docCode,
            $revision,
            $issueDate,
            $pageFormat,
            $orientation,
            $pageW,
            $pageH,
            $mt,
            $mr,
            $mb,
            $ml,
            $hh,
            $fh,
            $headerHtml,
            $footerHtml,
            $bodyHtml,
            $css,
            $sampleJson,
            $userId,
            $id
        );

        try {
            $ok = $stmt->execute();
            $stmt->close();
            if (!$ok) {
                $errorMsg = 'Update failed.';
                return false;
            }
            return true;
        } catch (mysqli_sql_exception $e) {
            $stmt->close();
            $errorMsg = 'Update failed: ' . $e->getMessage();
            return false;
        }
    }
}

if (!function_exists('report_template_delete')) {
    function report_template_delete(mysqli $conn, $id, &$errorMsg = '') {
        $errorMsg = '';
        report_templates_ensure_tables($conn);
        $id = (int) $id;
        if ($id <= 0) {
            $errorMsg = 'Invalid template.';
            return false;
        }

        $stmt = $conn->prepare("DELETE FROM report_templates WHERE id = ?");
        if (!$stmt) {
            $errorMsg = 'DB error: unable to prepare delete.';
            return false;
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            $errorMsg = 'Delete failed.';
            return false;
        }
        return true;
    }
}

if (!function_exists('report_template_duplicate')) {
    function report_template_duplicate(mysqli $conn, $id, $userId, &$errorMsg = '') {
        $errorMsg = '';
        report_templates_ensure_tables($conn);
        $id = (int) $id;
        $userId = (int) $userId;
        if ($id <= 0) {
            $errorMsg = 'Invalid template.';
            return 0;
        }

        $tpl = report_templates_get($conn, $id);
        if (!$tpl) {
            $errorMsg = 'Template not found.';
            return 0;
        }

        $newName = 'Copy of ' . trim((string) ($tpl['name'] ?? 'Template'));
        if (strlen($newName) > 120) $newName = substr($newName, 0, 120);
        $newKey = report_template_build_unique_key($conn, (string) ($tpl['template_key'] ?? 'template'), 0);

        $data = [
            'template_key' => $newKey,
            'name' => $newName,
            'description' => (string) ($tpl['description'] ?? ''),
            'status' => (string) ($tpl['status'] ?? 'active'),
            'doc_code' => (string) ($tpl['doc_code'] ?? ''),
            'revision' => (string) ($tpl['revision'] ?? ''),
            'issue_date' => (string) ($tpl['issue_date'] ?? ''),
            'page_format' => (string) ($tpl['page_format'] ?? 'A4'),
            'orientation' => (string) ($tpl['orientation'] ?? 'portrait'),
            'page_width_mm' => $tpl['page_width_mm'] ?? null,
            'page_height_mm' => $tpl['page_height_mm'] ?? null,
            'margin_top_mm' => $tpl['margin_top_mm'] ?? '10',
            'margin_right_mm' => $tpl['margin_right_mm'] ?? '10',
            'margin_bottom_mm' => $tpl['margin_bottom_mm'] ?? '10',
            'margin_left_mm' => $tpl['margin_left_mm'] ?? '10',
            'header_height_mm' => $tpl['header_height_mm'] ?? '20',
            'footer_height_mm' => $tpl['footer_height_mm'] ?? '15',
            'header_html' => (string) ($tpl['header_html'] ?? ''),
            'footer_html' => (string) ($tpl['footer_html'] ?? ''),
            'body_html' => (string) ($tpl['body_html'] ?? ''),
            'css' => (string) ($tpl['css'] ?? ''),
            'sample_data_json' => (string) ($tpl['sample_data_json'] ?? ''),
        ];

        return report_template_insert($conn, $data, $userId, $errorMsg);
    }
}

if (!function_exists('report_template_ctx_get')) {
    function report_template_ctx_get(array $ctx, $path) {
        $path = trim((string) $path);
        if ($path === '') return null;
        $parts = explode('.', $path);
        $val = $ctx;
        foreach ($parts as $p) {
            if (is_array($val) && array_key_exists($p, $val)) {
                $val = $val[$p];
                continue;
            }
            return null;
        }
        return $val;
    }
}

if (!function_exists('report_template_ctx_value_to_string')) {
    function report_template_ctx_value_to_string($val) {
        if ($val === null) return '';
        if (is_bool($val)) return $val ? 'true' : 'false';
        if (is_scalar($val)) return (string) $val;
        $json = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '';
    }
}

if (!function_exists('report_template_interpolate')) {
    function report_template_interpolate($text, array $ctx) {
        $text = (string) $text;
        if ($text === '') return '';

        // Raw HTML insertion: {{{key}}}
        $text = preg_replace_callback('/\\{\\{\\{\\s*([A-Za-z0-9_.-]+)\\s*\\}\\}\\}/', function ($m) use ($ctx) {
            $key = (string) ($m[1] ?? '');
            $val = report_template_ctx_get($ctx, $key);
            return report_template_ctx_value_to_string($val);
        }, $text);

        // Escaped insertion: {{key}}
        $text = preg_replace_callback('/\\{\\{\\s*([A-Za-z0-9_.-]+)\\s*\\}\\}/', function ($m) use ($ctx) {
            $key = (string) ($m[1] ?? '');
            $val = report_template_ctx_get($ctx, $key);
            $s = report_template_ctx_value_to_string($val);
            return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        }, $text);

        return is_string($text) ? $text : '';
    }
}

if (!function_exists('report_template_build_context')) {
    function report_template_build_context(mysqli $conn, array $tpl) {
        $sampleErr = '';
        $sample = report_template_parse_sample_json((string) ($tpl['sample_data_json'] ?? ''), $sampleErr);

        $u = current_user_row($conn);
        $displayName = $u ? current_user_display_name($u) : (isset($_SESSION['user_name']) ? (string) $_SESSION['user_name'] : 'Superadmin');

        $ctx = [
            'template' => [
                'id' => (int) ($tpl['id'] ?? 0),
                'key' => (string) ($tpl['template_key'] ?? ''),
                'name' => (string) ($tpl['name'] ?? ''),
                'doc_code' => (string) ($tpl['doc_code'] ?? ''),
                'revision' => (string) ($tpl['revision'] ?? ''),
                'issue_date' => (string) ($tpl['issue_date'] ?? ''),
            ],
            'generated' => [
                'date' => date('F j, Y'),
                'time' => date('g:i A'),
                'iso' => date('c'),
            ],
            'user' => [
                'name' => (string) $displayName,
                'id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0,
            ],
            'data' => $sample,
        ];

        // Convenience: expose sample keys at root unless they collide with reserved keys.
        $reserved = ['template' => true, 'generated' => true, 'user' => true, 'data' => true];
        foreach ($sample as $k => $v) {
            $k = (string) $k;
            if ($k === '' || isset($reserved[$k])) continue;
            $ctx[$k] = $v;
        }

        if ($sampleErr !== '') {
            $ctx['template_sample_error'] = $sampleErr;
        }

        return $ctx;
    }
}

if (!function_exists('report_template_render_full_html')) {
    function report_template_render_full_html(array $tpl, array $ctx) {
        [$pageW, $pageH] = report_templates_resolve_page_mm($tpl);

        $mTop = max(0.0, (float) ($tpl['margin_top_mm'] ?? 10));
        $mRight = max(0.0, (float) ($tpl['margin_right_mm'] ?? 10));
        $mBottom = max(0.0, (float) ($tpl['margin_bottom_mm'] ?? 10));
        $mLeft = max(0.0, (float) ($tpl['margin_left_mm'] ?? 10));
        $headerH = max(0.0, (float) ($tpl['header_height_mm'] ?? 20));
        $footerH = max(0.0, (float) ($tpl['footer_height_mm'] ?? 15));

        $pageMarginTop = $mTop + $headerH;
        $pageMarginBottom = $mBottom + $footerH;

        $name = trim((string) ($tpl['name'] ?? 'Report Template'));
        if ($name === '') $name = 'Report Template';

        $headerHtml = report_template_interpolate((string) ($tpl['header_html'] ?? ''), $ctx);
        $footerHtml = report_template_interpolate((string) ($tpl['footer_html'] ?? ''), $ctx);
        $bodyHtml = report_template_interpolate((string) ($tpl['body_html'] ?? ''), $ctx);
        $userCss = report_template_interpolate((string) ($tpl['css'] ?? ''), $ctx);

        $baseCss = trim((string) implode("\n", [
            ':root {',
            '  --page-w: ' . $pageW . 'mm;',
            '  --page-h: ' . $pageH . 'mm;',
            '}',
            '',
            '@page {',
            '  size: ' . $pageW . 'mm ' . $pageH . 'mm;',
            '  margin: ' . $pageMarginTop . 'mm ' . $mRight . 'mm ' . $pageMarginBottom . 'mm ' . $mLeft . 'mm;',
            '}',
            'html, body { margin: 0; padding: 0; }',
            '',
            '@media screen {',
            '  body { background: #f3f4f6; }',
            '  .rt-screen-frame { padding: 18px 12px; }',
            '  .rt-page {',
            '    width: ' . $pageW . 'mm;',
            '    min-height: ' . $pageH . 'mm;',
            '    margin: 0 auto 18px;',
            '    background: #fff;',
            '    border: 1px solid #e5e7eb;',
            '    box-shadow: 0 10px 28px rgba(17, 24, 39, 0.08);',
            '    position: relative;',
            '    padding: ' . $pageMarginTop . 'mm ' . $mRight . 'mm ' . $pageMarginBottom . 'mm ' . $mLeft . 'mm;',
            '    overflow: hidden;',
            '  }',
            '  .rt-header { position: absolute; top: ' . $mTop . 'mm; left: ' . $mLeft . 'mm; right: ' . $mRight . 'mm; height: ' . $headerH . 'mm; }',
            '  .rt-footer { position: absolute; bottom: ' . $mBottom . 'mm; left: ' . $mLeft . 'mm; right: ' . $mRight . 'mm; height: ' . $footerH . 'mm; }',
            '}',
            '',
            '@media print {',
            '  body { background: #fff; }',
            '  .rt-screen-frame { padding: 0; }',
            '  .rt-page { width: auto; min-height: auto; margin: 0; border: 0; box-shadow: none; padding: 0; }',
            '  .rt-header { position: fixed; top: ' . $mTop . 'mm; left: ' . $mLeft . 'mm; right: ' . $mRight . 'mm; height: ' . $headerH . 'mm; }',
            '  .rt-footer { position: fixed; bottom: ' . $mBottom . 'mm; left: ' . $mLeft . 'mm; right: ' . $mRight . 'mm; height: ' . $footerH . 'mm; }',
            '}',
        ]));

        $title = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        return '<!doctype html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title>'
            . '<style>' . $baseCss . '</style>'
            . '<style>' . $userCss . '</style>'
            . '</head>'
            . '<body>'
            . '<div class="rt-screen-frame">'
            . '<div class="rt-page">'
            . '<div class="rt-header">' . $headerHtml . '</div>'
            . '<div class="rt-footer">' . $footerHtml . '</div>'
            . '<div class="rt-body">' . $bodyHtml . '</div>'
            . '</div>'
            . '</div>'
            . '</body>'
            . '</html>';
    }
}
