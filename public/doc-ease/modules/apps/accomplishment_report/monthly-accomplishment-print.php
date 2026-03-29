<?php include __DIR__ . '/../../../layouts/session.php'; ?>
<?php require_active_role('teacher'); ?>

<?php
require_once __DIR__ . '/accomplishments.php';
require_once __DIR__ . '/../../../includes/reference.php';
require_once __DIR__ . '/../../../includes/ai_credits.php';
require_once __DIR__ . '/../../../includes/audit.php';
require_once __DIR__ . '/../../../includes/profile.php';

ensure_accomplishment_tables($conn);
ensure_reference_tables($conn);
ai_credit_ensure_system($conn);
ensure_audit_logs_table($conn);
ensure_profile_tables($conn);

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId <= 0) deny_access(401, 'Unauthorized.');

$u = current_user_row($conn);
$displayName = $u ? current_user_display_name($u) : 'Account';
$role = isset($_SESSION['user_role']) ? normalize_role($_SESSION['user_role']) : '';
$roleLabel = $role !== '' ? ucwords(str_replace('_', ' ', $role)) : 'User';
if (strcasecmp($roleLabel, 'Teacher') === 0) {
    $roleLabel = 'Subject Instructor';
}

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function school_year_term_label($ym) {
    $d = DateTime::createFromFormat('Y-m', (string) $ym);
    if (!$d) return date('Y') . ' - ' . (date('Y') + 1) . ' 1st Semester';
    $y = (int) $d->format('Y');
    $m = (int) $d->format('n');
    if ($m >= 6 && $m <= 12) {
        return $y . ' - ' . ($y + 1) . ' 1st Semester';
    }
    return ($y - 1) . ' - ' . $y . ' 2nd Semester';
}
if (!function_exists('acc_build_subject_query_suffix')) {
    function acc_build_subject_query_suffix(array $subjects) {
        $subjects = acc_collect_subject_labels($subjects);
        if (count($subjects) === 0) return '';
        return '&' . http_build_query(['subject' => array_values($subjects)]);
    }
}

if (!function_exists('acc_export_xml')) {
    function acc_export_xml($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

if (!function_exists('acc_export_safe_formula')) {
    function acc_export_safe_formula($v) {
        $v = (string) $v;
        if ($v === '') return $v;
        if (preg_match('/^[=\-+@]/', $v) === 1) {
            return "'" . $v;
        }
        return $v;
    }
}

if (!function_exists('acc_build_export_rows')) {
    function acc_build_export_rows(array $entriesAsc) {
        $rows = [];
        $rows[] = ['Date', 'Title', 'Details', 'Remarks', 'Proof Count', 'Proof Files'];

        foreach ($entriesAsc as $e) {
            $entryDateRaw = trim((string) ($e['entry_date'] ?? ''));
            $entryDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDateRaw) ? $entryDateRaw : '';
            $title = trim((string) ($e['title'] ?? ''));
            if ($title === '') $title = 'Accomplishment';
            $details = trim((string) ($e['details'] ?? ''));
            $remarks = trim((string) ($e['remarks'] ?? ''));
            $proofs = is_array($e['proofs'] ?? null) ? $e['proofs'] : [];

            $proofPaths = [];
            foreach ($proofs as $p) {
                $path = trim((string) ($p['file_path'] ?? ''));
                if ($path !== '') $proofPaths[] = $path;
            }

            $rows[] = [
                acc_export_safe_formula($entryDate),
                acc_export_safe_formula($title),
                acc_export_safe_formula($details),
                acc_export_safe_formula($remarks),
                (string) count($proofs),
                acc_export_safe_formula(implode('; ', $proofPaths)),
            ];
        }

        return $rows;
    }
}

if (!function_exists('acc_make_csv_text')) {
    function acc_make_csv_text(array $rows) {
        $fp = fopen('php://temp', 'r+');
        if (!$fp) return '';
        foreach ($rows as $row) {
            $safe = [];
            foreach ((array) $row as $cell) $safe[] = (string) $cell;
            fputcsv($fp, $safe);
        }
        rewind($fp);
        $csv = (string) stream_get_contents($fp);
        fclose($fp);
        return $csv;
    }
}

if (!function_exists('acc_send_binary_download')) {
    function acc_send_binary_download($mime, $filename, $binary) {
        $mime = trim((string) $mime);
        if ($mime === '') $mime = 'application/octet-stream';
        $filename = trim((string) $filename);
        if ($filename === '') $filename = 'download.bin';
        $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        if (!is_string($safeFilename) || trim($safeFilename) === '') $safeFilename = 'download.bin';

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . strlen((string) $binary));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo (string) $binary;
    }
}

if (!function_exists('acc_zip_dos_datetime')) {
    function acc_zip_dos_datetime() {
        $ts = time();
        $dt = getdate($ts);
        $year = max(1980, (int) ($dt['year'] ?? 1980));
        $month = max(1, min(12, (int) ($dt['mon'] ?? 1)));
        $day = max(1, min(31, (int) ($dt['mday'] ?? 1)));
        $hour = max(0, min(23, (int) ($dt['hours'] ?? 0)));
        $minute = max(0, min(59, (int) ($dt['minutes'] ?? 0)));
        $second = max(0, min(59, (int) ($dt['seconds'] ?? 0)));

        $dosTime = (($hour & 0x1F) << 11) | (($minute & 0x3F) << 5) | ((int) floor($second / 2) & 0x1F);
        $dosDate = ((($year - 1980) & 0x7F) << 9) | (($month & 0x0F) << 5) | ($day & 0x1F);
        return [$dosTime, $dosDate];
    }
}

if (!function_exists('acc_zip_pack_store')) {
    function acc_zip_pack_store(array $files) {
        [$dosTime, $dosDate] = acc_zip_dos_datetime();
        $data = '';
        $central = '';
        $offset = 0;
        $fileCount = 0;

        foreach ($files as $name => $content) {
            $name = ltrim(str_replace('\\', '/', (string) $name), '/');
            if ($name === '') continue;
            $content = (string) $content;
            $size = strlen($content);
            $crc = (int) sprintf('%u', crc32($content));
            $nameLen = strlen($name);

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                $nameLen,
                0
            );
            $data .= $localHeader . $name . $content;

            $centralHeader = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                $dosTime,
                $dosDate,
                $crc,
                $size,
                $size,
                $nameLen,
                0,
                0,
                0,
                0,
                0,
                $offset
            );
            $central .= $centralHeader . $name;
            $offset += strlen($localHeader) + $nameLen + $size;
            $fileCount++;
        }

        $centralOffset = strlen($data);
        $centralSize = strlen($central);
        $eocd = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $fileCount,
            $fileCount,
            $centralSize,
            $centralOffset,
            0
        );
        return $data . $central . $eocd;
    }
}

if (!function_exists('acc_docx_paragraph')) {
    function acc_docx_paragraph($text, $bold = false) {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        if ($text === '') return '<w:p/>';

        $parts = explode("\n", $text);
        $runs = [];
        $count = count($parts);
        foreach ($parts as $idx => $part) {
            $segment = $part;
            if ($segment === '') $segment = ' ';
            $runs[] = '<w:r>'
                . ($bold ? '<w:rPr><w:b/></w:rPr>' : '')
                . '<w:t xml:space="preserve">' . acc_export_xml($segment) . '</w:t>'
                . '</w:r>';
            if ($idx < ($count - 1)) {
                $runs[] = '<w:r><w:br/></w:r>';
            }
        }

        return '<w:p>' . implode('', $runs) . '</w:p>';
    }
}

if (!function_exists('acc_make_docx_binary')) {
    function acc_make_docx_binary($monthLabel, $yearLabel, $subjectLabel, $displayName, array $entriesAsc) {
        $paragraphs = [];
        $paragraphs[] = acc_docx_paragraph('Monthly Accomplishment Report', true);
        $paragraphs[] = acc_docx_paragraph('Month: ' . (string) $monthLabel . ' ' . (string) $yearLabel);
        $paragraphs[] = acc_docx_paragraph('Subject: ' . (string) $subjectLabel);
        $paragraphs[] = acc_docx_paragraph('Prepared by: ' . (string) $displayName);
        $paragraphs[] = acc_docx_paragraph('');

        if (count($entriesAsc) === 0) {
            $paragraphs[] = acc_docx_paragraph('No entries found for this month and selected subject(s).');
        } else {
            foreach ($entriesAsc as $e) {
                $entryDateRaw = trim((string) ($e['entry_date'] ?? ''));
                $entryDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDateRaw)
                    ? date('M j, Y', strtotime($entryDateRaw))
                    : $entryDateRaw;
                $title = trim((string) ($e['title'] ?? ''));
                if ($title === '') $title = 'Accomplishment';
                $details = trim((string) ($e['details'] ?? ''));
                $remarks = trim((string) ($e['remarks'] ?? ''));
                $proofs = is_array($e['proofs'] ?? null) ? $e['proofs'] : [];

                $paragraphs[] = acc_docx_paragraph('Date: ' . $entryDate, true);
                $paragraphs[] = acc_docx_paragraph('Title: ' . $title);
                if ($details !== '') $paragraphs[] = acc_docx_paragraph('Details: ' . $details);
                if ($remarks !== '') $paragraphs[] = acc_docx_paragraph('Remarks: ' . $remarks);
                $paragraphs[] = acc_docx_paragraph('Proof images: ' . (string) count($proofs));
                $paragraphs[] = acc_docx_paragraph('');
            }
        }

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"'
            . ' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"'
            . ' xmlns:v="urn:schemas-microsoft-com:vml"'
            . ' xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' xmlns:w10="urn:schemas-microsoft-com:office:word"'
            . ' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ' xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"'
            . ' xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup"'
            . ' xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk"'
            . ' xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml"'
            . ' xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" mc:Ignorable="w14 wp14">'
            . '<w:body>'
            . implode('', $paragraphs)
            . '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
            . '</w:body></w:document>';

        $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Monthly Accomplishment Report</dc:title>'
            . '<dc:creator>' . acc_export_xml($displayName) . '</dc:creator>'
            . '<cp:lastModifiedBy>' . acc_export_xml($displayName) . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:modified>'
            . '</cp:coreProperties>';

        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>E-Record</Application>'
            . '</Properties>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
                . '</Relationships>',
            'word/document.xml' => $documentXml,
            'docProps/core.xml' => $coreXml,
            'docProps/app.xml' => $appXml,
        ];

        return acc_zip_pack_store($files);
    }
}

if (!function_exists('acc_xlsx_col_name')) {
    function acc_xlsx_col_name($index) {
        $index = (int) $index;
        if ($index < 1) $index = 1;
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(($index % 26) + 65) . $name;
            $index = (int) floor($index / 26);
        }
        return $name;
    }
}

if (!function_exists('acc_make_xlsx_binary')) {
    function acc_make_xlsx_binary(array $rows, $displayName) {
        $sheetRows = [];
        $rowNum = 1;
        foreach ($rows as $row) {
            $cells = [];
            $colNum = 1;
            foreach ((array) $row as $cellValue) {
                $cellRef = acc_xlsx_col_name($colNum) . $rowNum;
                $text = (string) $cellValue;
                $text = acc_export_safe_formula($text);
                $cells[] = '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">'
                    . acc_export_xml($text)
                    . '</t></is></c>';
                $colNum++;
            }
            $sheetRows[] = '<row r="' . $rowNum . '">' . implode('', $cells) . '</row>';
            $rowNum++;
        }
        if (count($sheetRows) === 0) {
            $sheetRows[] = '<row r="1"><c r="A1" t="inlineStr"><is><t>No data</t></is></c></row>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
            . '</worksheet>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Accomplishments" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Monthly Accomplishment Report</dc:title>'
            . '<dc:creator>' . acc_export_xml($displayName) . '</dc:creator>'
            . '<cp:lastModifiedBy>' . acc_export_xml($displayName) . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:modified>'
            . '</cp:coreProperties>';

        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>E-Record</Application>'
            . '</Properties>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => $workbookXml,
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '</Relationships>',
            'xl/worksheets/sheet1.xml' => $sheetXml,
            'docProps/core.xml' => $coreXml,
            'docProps/app.xml' => $appXml,
        ];

        return acc_zip_pack_store($files);
    }
}

if (!function_exists('acc_pdf_escape')) {
    function acc_pdf_escape($text) {
        $text = (string) $text;
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('(', '\\(', $text);
        $text = str_replace(')', '\\)', $text);
        $text = str_replace(["\r", "\n"], '', $text);
        return $text;
    }
}

if (!function_exists('acc_pdf_encode_text')) {
    function acc_pdf_encode_text($text) {
        $text = (string) $text;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }
        return preg_replace('/[^\x20-\x7E\xA0-\xFF]/', '?', $text);
    }
}

if (!function_exists('acc_wrap_text_lines')) {
    function acc_wrap_text_lines($text, $width = 108) {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                $out[] = '';
                continue;
            }
            $wrapped = wordwrap($line, (int) $width, "\n", true);
            foreach (explode("\n", (string) $wrapped) as $part) {
                $out[] = (string) $part;
            }
        }
        return $out;
    }
}

if (!function_exists('acc_make_pdf_binary')) {
    function acc_make_pdf_binary(array $lines) {
        $fontSize = 10;
        $lineHeight = 13;
        $startX = 36;
        $startY = 560;
        $maxLinesPerPage = 39;
        $pages = array_chunk($lines, $maxLinesPerPage);
        if (count($pages) === 0) $pages = [[]];

        $objects = [];
        $addObj = function ($content) use (&$objects) {
            $objects[] = (string) $content;
            return count($objects);
        };

        $fontObj = $addObj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
        $pagesObj = $addObj('');
        $pageObjIds = [];

        foreach ($pages as $pageLines) {
            $stream = "BT\n";
            $stream .= "/F1 " . $fontSize . " Tf\n";
            $stream .= "1 0 0 1 " . $startX . " " . $startY . " Tm\n";
            $stream .= $lineHeight . " TL\n";

            $lineCount = count($pageLines);
            foreach ($pageLines as $idx => $line) {
                $encoded = acc_pdf_encode_text($line);
                $stream .= '(' . acc_pdf_escape($encoded) . ") Tj\n";
                if ($idx < ($lineCount - 1)) $stream .= "T*\n";
            }
            $stream .= "ET";

            $contentObj = $addObj("<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream");
            $pageObj = $addObj(
                "<< /Type /Page /Parent " . $pagesObj . " 0 R"
                . " /MediaBox [0 0 842 595]"
                . " /Resources << /Font << /F1 " . $fontObj . " 0 R >> >>"
                . " /Contents " . $contentObj . " 0 R >>"
            );
            $pageObjIds[] = $pageObj;
        }

        $kids = [];
        foreach ($pageObjIds as $id) $kids[] = $id . ' 0 R';
        $objects[$pagesObj - 1] = '<< /Type /Pages /Count ' . count($pageObjIds) . ' /Kids [' . implode(' ', $kids) . '] >>';
        $catalogObj = $addObj('<< /Type /Catalog /Pages ' . $pagesObj . ' 0 R >>');

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        $count = count($objects);
        for ($i = 1; $i <= $count; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $objects[$i - 1] . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . ($count + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", (int) $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($count + 1) . " /Root " . $catalogObj . " 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";
        return $pdf;
    }
}

if (!function_exists('acc_tmp_dir_create')) {
    function acc_tmp_dir_create($prefix = 'acc_export_') {
        $base = rtrim((string) sys_get_temp_dir(), "\\/");
        if ($base === '') $base = '.';
        $token = '';
        try {
            $token = bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            $token = uniqid('', true);
            $token = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $token);
        }
        $dir = $base . DIRECTORY_SEPARATOR . $prefix . $token;
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) return '';
        return $dir;
    }
}

if (!function_exists('acc_tmp_dir_delete')) {
    function acc_tmp_dir_delete($dir) {
        $dir = (string) $dir;
        if ($dir === '' || !is_dir($dir)) return;
        $items = @scandir($dir);
        if (!is_array($items)) {
            @rmdir($dir);
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                acc_tmp_dir_delete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

if (!function_exists('acc_generate_pdf_binary_from_preview_html')) {
    function acc_generate_pdf_binary_from_preview_html($html, $basePath, &$errorMsg = '') {
        $errorMsg = '';
        $html = (string) $html;
        $basePath = (string) $basePath;
        if ($html === '') {
            $errorMsg = 'Preview HTML is empty.';
            return '';
        }

        $tmpDir = acc_tmp_dir_create();
        if ($tmpDir === '') {
            $errorMsg = 'Unable to create temporary directory.';
            return '';
        }

        $htmlPath = $tmpDir . DIRECTORY_SEPARATOR . 'preview.html';
        $pdfPath = $tmpDir . DIRECTORY_SEPARATOR . 'preview.pdf';
        $pyPath = $tmpDir . DIRECTORY_SEPARATOR . 'render_pdf.py';

        $script = implode("\n", [
            'import sys',
            'from weasyprint import HTML',
            '',
            'html_path = sys.argv[1]',
            'pdf_path = sys.argv[2]',
            'base_url = sys.argv[3]',
            "HTML(filename=html_path, base_url=base_url, media_type='print').write_pdf(pdf_path)",
        ]);

        $okWrite = (@file_put_contents($htmlPath, $html) !== false)
            && (@file_put_contents($pyPath, $script) !== false);
        if (!$okWrite) {
            acc_tmp_dir_delete($tmpDir);
            $errorMsg = 'Unable to prepare export files.';
            return '';
        }

        $cmd = 'python '
            . escapeshellarg($pyPath) . ' '
            . escapeshellarg($htmlPath) . ' '
            . escapeshellarg($pdfPath) . ' '
            . escapeshellarg(str_replace('\\', '/', $basePath));
        $out = [];
        $status = 1;
        @exec($cmd . ' 2>&1', $out, $status);

        if ($status !== 0 || !is_file($pdfPath)) {
            $errorMsg = 'PDF renderer failed: ' . trim(implode(' ', $out));
            acc_tmp_dir_delete($tmpDir);
            return '';
        }

        $pdf = (string) @file_get_contents($pdfPath);
        acc_tmp_dir_delete($tmpDir);
        if ($pdf === '') {
            $errorMsg = 'Generated PDF is empty.';
            return '';
        }
        return $pdf;
    }
}

if (!function_exists('acc_pdf_binary_to_png_paths')) {
    function acc_pdf_binary_to_png_paths($pdfBinary, &$tmpDirOut = '', &$errorMsg = '') {
        $errorMsg = '';
        $tmpDirOut = '';
        $pdfBinary = (string) $pdfBinary;
        if ($pdfBinary === '') {
            $errorMsg = 'PDF data is empty.';
            return [];
        }

        $tmpDir = acc_tmp_dir_create('acc_pdf_pages_');
        if ($tmpDir === '') {
            $errorMsg = 'Unable to create temp directory for PDF pages.';
            return [];
        }

        $pdfPath = $tmpDir . DIRECTORY_SEPARATOR . 'source.pdf';
        if (@file_put_contents($pdfPath, $pdfBinary) === false) {
            acc_tmp_dir_delete($tmpDir);
            $errorMsg = 'Unable to write temporary PDF.';
            return [];
        }

        $prefix = $tmpDir . DIRECTORY_SEPARATOR . 'page';
        $cmd = 'pdftoppm -png -r 170 '
            . escapeshellarg($pdfPath) . ' '
            . escapeshellarg($prefix);
        $out = [];
        $status = 1;
        @exec($cmd . ' 2>&1', $out, $status);

        if ($status !== 0) {
            $errorMsg = 'Unable to rasterize PDF pages: ' . trim(implode(' ', $out));
            acc_tmp_dir_delete($tmpDir);
            return [];
        }

        $pngFiles = glob($tmpDir . DIRECTORY_SEPARATOR . 'page-*.png');
        if (!is_array($pngFiles) || count($pngFiles) === 0) {
            $errorMsg = 'No PNG pages generated from PDF.';
            acc_tmp_dir_delete($tmpDir);
            return [];
        }

        natsort($pngFiles);
        $tmpDirOut = $tmpDir;
        return array_values($pngFiles);
    }
}

if (!function_exists('acc_docx_fit_emu')) {
    function acc_docx_fit_emu($imgW, $imgH, $maxWEmu, $maxHEmu) {
        $imgW = max(1.0, (float) $imgW);
        $imgH = max(1.0, (float) $imgH);
        $maxW = max(1.0, (float) $maxWEmu);
        $maxH = max(1.0, (float) $maxHEmu);

        $ratio = min($maxW / $imgW, $maxH / $imgH);
        if ($ratio <= 0) $ratio = 1.0;
        $cx = (int) round($imgW * $ratio);
        $cy = (int) round($imgH * $ratio);
        return [$cx, $cy];
    }
}

if (!function_exists('acc_make_docx_binary_from_png_pages')) {
    function acc_make_docx_binary_from_png_pages(array $pngPaths, $displayName) {
        $displayName = trim((string) $displayName);
        if ($displayName === '') $displayName = 'User';

        $media = [];
        $rels = [];
        $docBlocks = [];
        $docPrId = 1;

        $pageW = 16838; // twips, A4 landscape width
        $pageH = 11906; // twips, A4 landscape height
        $margin = 360;  // twips, 0.25in
        $maxWEmu = (int) (($pageW - (2 * $margin)) * 635);
        $maxHEmu = (int) (($pageH - (2 * $margin)) * 635);

        $index = 0;
        foreach ($pngPaths as $pngPath) {
            $index++;
            $pngPath = (string) $pngPath;
            if ($pngPath === '' || !is_file($pngPath)) continue;

            $imgData = (string) @file_get_contents($pngPath);
            if ($imgData === '') continue;
            $size = @getimagesize($pngPath);
            $imgW = (is_array($size) && isset($size[0])) ? (int) $size[0] : 1600;
            $imgH = (is_array($size) && isset($size[1])) ? (int) $size[1] : 1131;
            [$cx, $cy] = acc_docx_fit_emu($imgW, $imgH, $maxWEmu, $maxHEmu);

            $imageName = 'image' . $index . '.png';
            $rid = 'rId' . $index;
            $media['word/media/' . $imageName] = $imgData;
            $rels[] = '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $imageName . '"/>';

            $docBlocks[] =
                '<w:p><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
                . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
                . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
                . '<wp:docPr id="' . $docPrId . '" name="Page ' . $index . '"/>'
                . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
                . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                . '<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="' . $imageName . '"/><pic:cNvPicPr/></pic:nvPicPr>'
                . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
                . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
                . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
                . '</pic:pic></a:graphicData></a:graphic>'
                . '</wp:inline></w:drawing></w:r></w:p>';

            if ($index < count($pngPaths)) {
                $docBlocks[] = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
            }
            $docPrId++;
        }

        if (count($media) === 0) return '';

        $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"'
            . ' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'
            . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"'
            . ' xmlns:v="urn:schemas-microsoft-com:vml"'
            . ' xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' xmlns:w10="urn:schemas-microsoft-com:office:word"'
            . ' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ' xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"'
            . ' xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup"'
            . ' xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk"'
            . ' xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml"'
            . ' xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" mc:Ignorable="w14 wp14">'
            . '<w:body>' . implode('', $docBlocks)
            . '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
            . '<w:pgMar w:top="' . $margin . '" w:right="' . $margin . '" w:bottom="' . $margin . '" w:left="' . $margin . '" w:header="0" w:footer="0" w:gutter="0"/>'
            . '</w:sectPr></w:body></w:document>';

        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', $rels)
            . '</Relationships>';

        $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Monthly Accomplishment Report</dc:title>'
            . '<dc:creator>' . acc_export_xml($displayName) . '</dc:creator>'
            . '<cp:lastModifiedBy>' . acc_export_xml($displayName) . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:modified>'
            . '</cp:coreProperties>';

        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>E-Record</Application>'
            . '</Properties>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Default Extension="png" ContentType="image/png"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
                . '</Relationships>',
            'word/document.xml' => $documentXml,
            'word/_rels/document.xml.rels' => $docRels,
            'docProps/core.xml' => $coreXml,
            'docProps/app.xml' => $appXml,
        ];

        foreach ($media as $k => $v) $files[$k] = $v;
        return acc_zip_pack_store($files);
    }
}

$month = isset($_GET['month']) ? trim((string) $_GET['month']) : date('Y-m');
[$firstDay, $lastDay, $month] = acc_month_bounds($month);

$subjectLabels = acc_collect_subject_labels($_GET['subject'] ?? []);
if (count($subjectLabels) === 0) $subjectLabels[] = 'Monthly Accomplishment';
$entries = acc_list_month($conn, $userId, $month, $subjectLabels);
$subjectLabel = implode(', ', $subjectLabels);

$subjectHasSpecific = false;
$genericSubjectNorms = ['monthly accomplishment', 'monthly accomplishment report'];
foreach ($subjectLabels as $subjectValue) {
    $subjectNorm = strtolower(trim((string) preg_replace('/\s+/', ' ', $subjectValue)));
    if (!in_array($subjectNorm, $genericSubjectNorms, true)) {
        $subjectHasSpecific = true;
        break;
    }
}
$showSubjectSubtitle = $subjectHasSpecific || count($subjectLabels) > 1;

$approvedBy = isset($_GET['approved_by']) ? trim((string) $_GET['approved_by']) : '';
$approvedRole = isset($_GET['approved_role']) ? trim((string) $_GET['approved_role']) : 'Program Chair';
if ($approvedRole === '') $approvedRole = 'Program Chair';
$resolvedProgramChair = profile_resolve_program_chair_for_subjects($conn, $userId, $subjectLabels);
if ($approvedBy === '') {
    if (!empty($resolvedProgramChair['has_assignment'])) {
        $approvedBy = trim((string) ($resolvedProgramChair['program_chair_display_name'] ?? ''));
    }

    if ($approvedBy === '') {
        // Legacy fallback: single Program Chair in profile for older records.
        $profileRow = profile_load($conn, $userId);
        if (is_array($profileRow)) {
            $assignedProgramChair = trim((string) ($profileRow['program_chair_display_name'] ?? ''));
            if ($assignedProgramChair !== '') $approvedBy = $assignedProgramChair;
        }
    }
}
if ($approvedRole === 'Program Chair' && !empty($resolvedProgramChair['multiple'])) {
    $approvedRole = 'Program Chair (Per Subject)';
}

$downloadFormat = isset($_GET['download']) ? strtolower(trim((string) $_GET['download'])) : '';
$allowedDownloadFormats = ['docx', 'xlsx', 'pdf', 'csv'];
$downloadCreditCost = 2;

$subjectQuerySuffix = acc_build_subject_query_suffix($subjectLabels);
$backHref = 'monthly-accomplishment.php?month=' . urlencode($month) . $subjectQuerySuffix;
$printBaseHref = 'monthly-accomplishment-print.php'
    . '?month=' . urlencode($month)
    . $subjectQuerySuffix
    . '&approved_by=' . urlencode($approvedBy)
    . '&approved_role=' . urlencode($approvedRole);

$monthLabel = date('F', strtotime($firstDay));
$yearLabel = date('Y', strtotime($firstDay));
$schoolYearTerm = school_year_term_label($month);
$reportFooterSettings = ref_get_report_template_settings($conn);
$footerDocCode = trim((string) ($reportFooterSettings['doc_code'] ?? 'SLSU-QF-IN41'));
$footerRevision = trim((string) ($reportFooterSettings['revision'] ?? '01'));
$footerIssueDate = trim((string) ($reportFooterSettings['issue_date'] ?? '14 October 2019'));

// Group entries by ISO week to match the DOCX table style.
$entriesAsc = $entries;
usort($entriesAsc, function ($a, $b) {
    $da = (string) ($a['entry_date'] ?? '');
    $db = (string) ($b['entry_date'] ?? '');
    return strcmp($da, $db);
});

$subjectSlug = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string) $subjectLabel));
if (!is_string($subjectSlug) || $subjectSlug === '') $subjectSlug = 'subject';
if (strlen($subjectSlug) > 80) $subjectSlug = substr($subjectSlug, 0, 80);
$exportBaseName = 'monthly_accomplishment_' . $month . '_' . $subjectSlug;
$exportGeneratedAt = date('Y-m-d H:i:s');
$pendingVisualDownload = '';
$pendingVisualAuditMeta = [];
$pendingVisualCreditCost = 0;

if ($downloadFormat !== '') {
    if (!in_array($downloadFormat, $allowedDownloadFormats, true)) {
        $_SESSION['flash_message'] = 'Unsupported download format.';
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . $backHref);
        exit;
    }

    [$okConsume, $consumeMsg] = ai_credit_try_consume_count($conn, $userId, $downloadCreditCost);
    if (!$okConsume) {
        $_SESSION['flash_message'] = (string) $consumeMsg;
        $_SESSION['flash_type'] = 'warning';
        header('Location: ' . $backHref);
        exit;
    }

    $downloadAuditMeta = [
        'month' => $month,
        'subject' => $subjectLabel,
        'subjects' => $subjectLabels,
        'format' => $downloadFormat,
        'credit_cost' => $downloadCreditCost,
        'entries' => count($entriesAsc),
    ];

    if ($downloadFormat === 'csv' || $downloadFormat === 'xlsx') {
        try {
            $binary = '';
            $mime = 'application/octet-stream';
            $ext = $downloadFormat;
            if ($downloadFormat === 'csv') {
                $rows = acc_build_export_rows($entriesAsc);
                $binary = "\xEF\xBB\xBF" . acc_make_csv_text($rows);
                $mime = 'text/csv; charset=UTF-8';
            } else {
                $rows = acc_build_export_rows($entriesAsc);
                $binary = acc_make_xlsx_binary($rows, $displayName);
                $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }

            if (!is_string($binary) || $binary === '') {
                throw new RuntimeException('Unable to generate file.');
            }

            [$okCreditAfter, $creditStatusAfter] = ai_credit_get_user_status($conn, $userId);
            $remainingAfter = ($okCreditAfter && is_array($creditStatusAfter))
                ? (float) ($creditStatusAfter['remaining'] ?? 0)
                : 0;
            $downloadAuditMeta['ai_credit_remaining'] = $remainingAfter;
            audit_log($conn, 'accomplishment.report.downloaded', 'accomplishment_report', null, null, $downloadAuditMeta);

            $filename = $exportBaseName . '.' . $ext;
            acc_send_binary_download($mime, $filename, $binary);
            exit;
        } catch (Throwable $e) {
            ai_credit_refund($conn, $userId, $downloadCreditCost);
            $_SESSION['flash_message'] = 'Unable to generate the requested file. Credits were refunded.';
            $_SESSION['flash_type'] = 'danger';
            header('Location: ' . $backHref);
            exit;
        }
    }

    // For visual formats, defer generation until the exact preview HTML is rendered.
    $pendingVisualDownload = $downloadFormat;
    $pendingVisualAuditMeta = $downloadAuditMeta;
    $pendingVisualCreditCost = $downloadCreditCost;
}

$deferVisualExport = ($pendingVisualDownload === 'pdf' || $pendingVisualDownload === 'docx');
if ($deferVisualExport) {
    ob_start();
}

[$okCreditStatus, $creditStatusOrMsg] = ai_credit_get_user_status($conn, $userId);
$creditRemaining = ($okCreditStatus && is_array($creditStatusOrMsg))
    ? (float) ($creditStatusOrMsg['remaining'] ?? 0)
    : 0;
$canDownloadReports = $creditRemaining >= $downloadCreditCost;

$weeklyRows = [];
foreach ($entriesAsc as $e) {
    $d = (string) ($e['entry_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
    $dt = new DateTimeImmutable($d);
    $key = $dt->format('o-\WW');

    if (!isset($weeklyRows[$key])) {
        $weeklyRows[$key] = [
            'start' => $dt,
            'end' => $dt,
            'entries' => [],
        ];
    }
    if ($dt < $weeklyRows[$key]['start']) $weeklyRows[$key]['start'] = $dt;
    if ($dt > $weeklyRows[$key]['end']) $weeklyRows[$key]['end'] = $dt;
    $weeklyRows[$key]['entries'][] = $e;
}

if (!function_exists('format_week_range')) {
    function format_week_range(DateTimeImmutable $a, DateTimeImmutable $b) {
        if ($a->format('Y-m') === $b->format('Y-m')) {
            if ($a->format('d') === $b->format('d')) {
                return $a->format('F j, Y');
            }
            return $a->format('F j') . ' - ' . $b->format('j, Y');
        }
        if ($a->format('Y') === $b->format('Y')) {
            return $a->format('M j') . ' - ' . $b->format('M j, Y');
        }
        return $a->format('M j, Y') . ' - ' . $b->format('M j, Y');
    }
}

if (!function_exists('acc_report_normalize_text')) {
    function acc_report_normalize_text($value): string {
        $text = (string) $value;
        // Replace NBSP with regular spaces before collapse.
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }
}

if (!function_exists('acc_report_normalize_multiline_text')) {
    function acc_report_normalize_multiline_text($value): string {
        $text = (string) $value;
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);

        $lines = preg_split('/\n+/', $text);
        if (!is_array($lines)) return '';

        $out = [];
        foreach ($lines as $line) {
            $line = preg_replace('/[ \t]+/u', ' ', (string) $line);
            $line = trim((string) $line);
            if ($line === '') continue;
            $out[] = $line;
        }
        return implode("\n", $out);
    }
}

if (!function_exists('acc_report_extract_detail_items')) {
    /**
     * Convert a stored detail block into list items.
     * Supports:
     * - true multiline bullets ("- item\\n- item")
     * - inline bullets ("- item - item - item")
     * - plain paragraph (single item)
     */
    function acc_report_extract_detail_items($value): array {
        $text = acc_report_normalize_multiline_text($value);
        if ($text === '') return [];

        $items = [];
        $current = '';
        $hasExplicitBullet = false;
        $lines = preg_split('/\n+/', $text);
        if (!is_array($lines)) $lines = [$text];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') continue;

            if (preg_match('/^(?:[-*]+|\d+[.)])\s*(.+)$/u', $line, $m)) {
                $hasExplicitBullet = true;
                if ($current !== '') {
                    $items[] = trim((string) preg_replace('/\s+/', ' ', $current));
                }
                $current = trim((string) ($m[1] ?? ''));
                continue;
            }

            if ($current === '') {
                $current = $line;
            } else {
                $current .= ' ' . $line;
            }
        }
        if ($current !== '') {
            $items[] = trim((string) preg_replace('/\s+/', ' ', $current));
        }

        // Fallback: parse inline dash bullets from a single flattened line.
        if (!$hasExplicitBullet && count($items) <= 1) {
            $flat = trim((string) preg_replace('/\s+/', ' ', str_replace('•', '-', $text)));
            if ($flat !== '' && preg_match('/^\s*-\s*/', $flat)) {
                $flat = preg_replace('/^\s*-\s*/', '', $flat);
                $parts = preg_split('/\s+-\s+/', (string) $flat);
                $expanded = [];
                if (is_array($parts)) {
                    foreach ($parts as $part) {
                        $part = trim((string) $part);
                        if ($part === '') continue;
                        $expanded[] = $part;
                    }
                }
                if (count($expanded) > 0) {
                    $items = $expanded;
                }
            }
        }

        if (count($items) === 0) return [];
        return array_values(array_filter($items, function ($item) {
            return trim((string) $item) !== '';
        }));
    }
}

if (!function_exists('acc_report_canonical_type')) {
    function acc_report_canonical_type($title): string {
        $clean = acc_report_normalize_text($title);
        if ($clean === '') return 'Accomplishment Item';

        $raw = strtolower($clean);
        $hasLecture = strpos($raw, 'lecture') !== false;
        $hasLaboratory = (strpos($raw, 'laboratory') !== false) || (preg_match('/\blab\b/u', $raw) === 1);

        if ($hasLecture && $hasLaboratory) return 'Lecture & Laboratory';
        if ($hasLecture) return 'Lecture';
        if ($hasLaboratory) return 'Laboratory';
        return $clean;
    }
}

if (!function_exists('acc_build_weekly_display_entries')) {
    /**
     * Group weekly entries by type/title while preserving first-seen order.
     * Returns rows with: title, details(list), proof_count.
     */
    function acc_build_weekly_display_entries(array $entries): array {
        $groups = [];
        $order = [];

        foreach ($entries as $e) {
            $title = acc_report_canonical_type((string) ($e['title'] ?? ''));
            $key = strtolower(acc_report_normalize_text($title));
            if ($key === '') $key = 'accomplishment-item';

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'title' => $title,
                    'details' => [],
                    'proof_count' => 0,
                ];
                $order[] = $key;
            }

            $detailItems = acc_report_extract_detail_items((string) ($e['details'] ?? ''));
            if (count($detailItems) > 0) {
                foreach ($detailItems as $detailItem) {
                    $groups[$key]['details'][] = $detailItem;
                }
            }
            $proofs = is_array($e['proofs'] ?? null) ? $e['proofs'] : [];
            $groups[$key]['proof_count'] += count($proofs);
        }

        $out = [];
        foreach ($order as $key) {
            $g = $groups[$key];
            $out[] = $g;
        }

        return $out;
    }
}

if (!function_exists('acc_build_weekly_remark_items')) {
    /**
     * Build one remark line per accomplishment item (entry), preserving order.
     */
    function acc_build_weekly_remark_items(array $entries): array {
        $remarkItems = [];
        foreach ($entries as $wx) {
            $er = acc_report_normalize_text((string) ($wx['remarks'] ?? ''));
            if ($er === '') $er = 'Accomplished';
            $remarkItems[] = $er;
        }
        if (count($remarkItems) === 0) {
            $remarkItems[] = 'Accomplished';
        }
        return $remarkItems;
    }
}

if (!function_exists('estimate_weekly_row_units')) {
    function estimate_weekly_row_units(array $row): int {
        $units = 5;
        $entries = is_array($row['entries'] ?? null) ? $row['entries'] : [];
        $displayEntries = acc_build_weekly_display_entries($entries);

        foreach ($displayEntries as $entry) {
            $title = trim((string) ($entry['title'] ?? ''));
            $detailsList = is_array($entry['details'] ?? null) ? $entry['details'] : [];
            $proofCount = (int) ($entry['proof_count'] ?? 0);
            $units += 2;
            $units += max(1, (int) ceil(strlen($title) / 58));

            if (count($detailsList) > 0) {
                foreach ($detailsList as $details) {
                    $details = trim((string) $details);
                    $units += max(1, (int) ceil(strlen($details) / 92));
                    $units += substr_count($details, "\n");
                }
            } else {
                $units += 1;
            }
            if ($proofCount > 0) $units += 1;
        }

        $remarkItems = acc_build_weekly_remark_items($entries);
        $remarkUnits = 0;
        foreach ($remarkItems as $remarkItem) {
            $remarkItem = trim((string) $remarkItem);
            $remarkUnits += max(1, (int) ceil(strlen($remarkItem) / 42));
            $remarkUnits += substr_count($remarkItem, "\n");
        }
        $units += max(2, $remarkUnits + max(0, count($remarkItems) - 1));

        return max(7, $units);
    }
}

$weeklyRowsList = array_values($weeklyRows);
$rowPackets = [];
foreach ($weeklyRowsList as $r) {
    $rowPackets[] = [
        'row' => $r,
        'units' => estimate_weekly_row_units($r),
    ];
}

// Paginate report rows into multiple A4 sheets (first page has less room due title/meta).
// Keep conservative limits so each logical sheet reliably fits one physical A4 page.
$firstPageUnitLimit = 40;
$nextPageUnitLimit = 54;
$reportRowPages = [];
$reportPageUnits = [];
$currentRows = [];
$currentUnits = 0;

foreach ($rowPackets as $packet) {
    $row = $packet['row'];
    $units = (int) $packet['units'];
    $unitLimit = (count($reportRowPages) === 0) ? $firstPageUnitLimit : $nextPageUnitLimit;

    if (!empty($currentRows) && ($currentUnits + $units) > $unitLimit) {
        $reportRowPages[] = $currentRows;
        $reportPageUnits[] = $currentUnits;
        $currentRows = [];
        $currentUnits = 0;
    }

    $currentRows[] = $row;
    $currentUnits += $units;
}

if (!empty($currentRows) || count($reportRowPages) === 0) {
    $reportRowPages[] = $currentRows;
    $reportPageUnits[] = $currentUnits;
}

// Keep signature block on last report page when there's room; otherwise push to its own A4 page.
$signatureReserveUnits = 16;
$lastReportPageIndex = count($reportRowPages) - 1;
$lastReportLimit = ($lastReportPageIndex === 0) ? $firstPageUnitLimit : $nextPageUnitLimit;
$signatureOwnPage = ((int) ($reportPageUnits[$lastReportPageIndex] ?? 0) + $signatureReserveUnits) > $lastReportLimit;

// Flatten proofs for dedicated proof/evidence pages.
$allProofs = [];
$proofIndex = 0;
foreach ($entriesAsc as $e) {
    $proofs = is_array($e['proofs'] ?? null) ? $e['proofs'] : [];
    foreach ($proofs as $p) {
        $rel = trim((string) ($p['file_path'] ?? ''));
        if ($rel === '') continue;
        $proofIndex++;
        $allProofs[] = [
            'file_path' => $rel,
            'entry_date' => (string) ($e['entry_date'] ?? ''),
            'title' => trim((string) ($e['title'] ?? '')),
            'label' => 'Proof ' . $proofIndex,
        ];
    }
}

// Keep proofs to 6 per A4 landscape page (3 columns x 2 rows max).
$proofPages = array_chunk($allProofs, 6);
if (count($proofPages) === 0) $proofPages = [[]];

if (!function_exists('render_report_header')) {
    function render_report_header() {
        ?>
        <div class="template-header">
            <img class="template-header-strip" src="assets/images/report-template/header-strip-template.png" alt="Report Header">
        </div>
        <?php
    }
}

if (!function_exists('render_report_footer')) {
    function report_asset_version_token($absPath) {
        $hash = @md5_file($absPath);
        if (is_string($hash) && $hash !== '') {
            return substr($hash, 0, 12);
        }
        $mtime = @filemtime($absPath);
        $size = @filesize($absPath);
        return (string) (($mtime ?: 0) . '-' . ($size ?: 0));
    }

    function render_report_footer($docCode, $revision, $issueDate) {
        $qsPath = __DIR__ . '/../../../assets/images/report-template/image17.png';
        $socotecPath = __DIR__ . '/../../../assets/images/report-template/image18.png';
        $qsVer = report_asset_version_token($qsPath);
        $socotecVer = report_asset_version_token($socotecPath);
        $qsSrc = 'assets/images/report-template/image17.png' . ($qsVer ? ('?v=' . $qsVer) : '');
        $socotecSrc = 'assets/images/report-template/image18.png' . ($socotecVer ? ('?v=' . $socotecVer) : '');
        ?>
        <div class="footer-meta">
            Doc. Code: <?php echo h((string) $docCode); ?><br>
            Revision: <?php echo h((string) $revision); ?><br>
            Date: <?php echo h((string) $issueDate); ?>
        </div>
        <div class="template-footer">
            <img class="template-footer-qs" src="<?php echo h($qsSrc); ?>" alt="QS Rated Good">
            <img class="template-footer-socotec" src="<?php echo h($socotecSrc); ?>" alt="Socotec ISO 9001:2015">
        </div>
        <?php
    }
}

if (!function_exists('render_signature_block')) {
    function render_signature_block($displayName, $roleLabel, $approvedBy, $approvedRole) {
        $approvedName = trim((string) $approvedBy);
        if ($approvedName === '') $approvedName = '____________________________';
        ?>
        <div class="signatures">
            <div>
                <div class="sig-label">Prepared by:</div>
                <div class="sig-name"><?php echo h(strtoupper((string) $displayName)); ?></div>
                <div class="sig-role"><?php echo h((string) $roleLabel); ?></div>
            </div>
            <div>
                <div class="sig-label">Approved:</div>
                <div class="sig-name"><?php echo h((string) $approvedName); ?></div>
                <div class="sig-role"><?php echo h((string) $approvedRole); ?></div>
            </div>
        </div>
        <?php
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monthly Accomplishment Report</title>
    <?php include __DIR__ . '/../../../layouts/head-css.php'; ?>
    <style>
        :root {
            --page-w: 297mm;
            --page-h: 210mm;
            --template-page-px-w: 1650;
            --template-header-px-w: 1050;
            --template-footer-px-w: 372;
            --font-main: "Cambria Math", "Cambria", "Times New Roman", serif;
            --line: #111827;
            --ink: #111827;
            --muted: #4b5563;
            --bg: #eef2f7;
        }
        body {
            background: var(--bg) !important;
            color: var(--ink);
            font-family: var(--font-main);
            font-size: 12pt;
            line-height: 1.2;
            margin: 0;
        }
        .toolbar {
            max-width: 1120px;
            margin: 14px auto 8px;
            padding: 0 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .sheet-wrap {
            max-width: 1120px;
            margin: 0 auto 14px;
            padding: 0 10px 20px;
        }
        .sheet {
            width: var(--page-w);
            min-height: var(--page-h);
            background: #fff;
            box-shadow: 0 12px 26px rgba(0, 0, 0, 0.16);
            border: 1px solid rgba(0, 0, 0, 0.12);
            position: relative;
            margin: 0 auto;
            box-sizing: border-box;
            padding: 9mm 13mm 13mm 13mm;
            display: flex;
            flex-direction: column;
        }
        .page-sheet + .page-sheet {
            margin-top: 14px;
        }
        .page-body {
            flex: 1 1 auto;
            padding-bottom: 30mm;
        }
        .proof-sheet .page-body {
            padding-bottom: 35mm;
        }

        .template-header {
            margin-bottom: 2.2mm;
            text-align: center;
        }
        .template-header-strip {
            width: calc(var(--page-w) * var(--template-header-px-w) / var(--template-page-px-w));
            max-width: 100%;
            height: auto;
            object-fit: contain;
            display: block;
            margin: 0 auto;
        }

        .report-title {
            text-align: center;
            font-size: 14pt;
            font-weight: 700;
            letter-spacing: 0;
            margin: 0;
            text-transform: uppercase;
        }
        .report-subtitle {
            text-align: center;
            font-size: 12pt;
            font-weight: 700;
            margin: 0.6mm 0 0;
        }
        .report-meta {
            text-align: center;
            font-size: 11pt;
            margin-top: 0.8mm;
            line-height: 1.15;
        }
        .report-meta + .report-meta {
            margin-top: 0.5mm;
        }

        .table-wrap {
            margin-top: 4.2mm;
        }
        table.report {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 10.9pt;
            line-height: 1.16;
        }
        table.report th,
        table.report td {
            border: 1px solid #000;
            vertical-align: top;
            padding: 2.1mm 2.5mm;
        }
        table.report th {
            text-align: center;
            font-size: 11pt;
            font-weight: 700;
            line-height: 1.12;
        }
        /* Match DOCX proportions: 2126 / 10632 / 2552 of 15310 total width. */
        .col-date { width: 13.9%; }
        .col-acc { width: 69.4%; }
        .col-rem { width: 16.7%; }
        .remarks-head-note {
            display: block;
            margin-top: 0.5mm;
            font-weight: 400;
            font-size: 10.5pt;
            line-height: 1.14;
        }

        .entry-block + .entry-block {
            margin-top: 1.6mm;
            padding-top: 1.6mm;
            border-top: 1px solid rgba(0, 0, 0, 0.18);
        }
        .entry-title {
            font-weight: 700;
            margin: 0 0 0.8mm;
            line-height: 1.14;
        }
        .entry-details-list {
            margin: 0;
            padding-left: 4.4mm;
        }
        .entry-details-item {
            white-space: pre-wrap;
            line-height: 1.16;
            margin: 0;
        }
        .entry-details-item + .entry-details-item {
            margin-top: 0.95mm;
        }
        .entry-details {
            white-space: pre-wrap;
            margin: 0;
            line-height: 1.16;
        }
        .entry-details + .entry-details {
            margin-top: 0.95mm;
        }
        .entry-proof-meta {
            margin: 0.8mm 0 0;
            font-size: 10pt;
            font-style: italic;
            color: #374151;
        }
        .remarks-note {
            font-size: 10.9pt;
            line-height: 1.16;
        }
        .remarks-list {
            margin: 0;
            padding-left: 4.2mm;
        }
        .remarks-item {
            white-space: pre-wrap;
            line-height: 1.16;
        }
        .proof-note {
            font-size: 10.5pt;
            font-style: italic;
            margin: 3.5mm 0 0;
        }

        .signatures {
            margin-top: 6mm;
            display: grid;
            grid-template-columns: 1fr 1fr;
            column-gap: 16mm;
            align-items: end;
            font-size: 11pt;
            font-family: "Cambria Math", "Cambria", "Times New Roman", serif;
        }
        .sig-label {
            font-weight: 400 !important;
            font-family: "Cambria Math", "Cambria", "Times New Roman", serif;
            margin-bottom: 10mm;
        }
        .sig-name {
            text-align: left;
            font-weight: 700;
            text-decoration: underline;
            text-underline-offset: 2px;
            min-height: 17px;
        }
        .sig-role {
            text-align: left;
            margin-top: 2px;
        }

        .proof-board {
            margin-top: 4mm;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 3mm 3.5mm;
            align-content: start;
        }
        .proof-board-continued {
            margin-top: 4mm;
        }
        .proof-card {
            margin: 0;
            border: 1px solid #000;
            padding: 1.2mm;
            background: #fff;
            display: flex;
            flex-direction: column;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        .proof-card img {
            width: 100%;
            height: 23mm;
            object-fit: cover;
            display: block;
        }
        .proof-caption {
            margin-top: 1.2mm;
            font-size: 8.5px;
            line-height: 1.2;
            color: #111827;
            min-height: 6mm;
            max-height: 6mm;
            overflow: hidden;
        }
        .proof-empty {
            grid-column: 1 / -1;
            border: 1px dashed #9ca3af;
            color: #4b5563;
            text-align: center;
            font-size: 11pt;
            padding: 11mm 7mm;
        }

        .template-footer {
            position: absolute;
            right: 18mm;
            bottom: 8.4mm;
            line-height: 1;
            display: flex;
            align-items: flex-end;
            gap: 2.6mm;
            overflow: visible;
        }
        .template-footer-qs {
            width: auto;
            height: 19.8mm;
            max-width: none;
            object-fit: contain;
            object-position: center bottom;
            display: block;
        }
        .template-footer-socotec {
            width: 38.9mm;
            max-width: none;
            height: auto;
            object-fit: contain;
            display: block;
        }
        .footer-meta {
            position: absolute;
            left: 18mm;
            bottom: 8.6mm;
            font-size: 8.5pt;
            color: #111827;
            line-height: 1.35;
            font-family: var(--font-main);
            text-align: left;
        }

        @page {
            size: A4 landscape;
            margin: 0;
        }
        @media print {
            body {
                background: #fff !important;
            }
            .toolbar {
                display: none !important;
            }
            .sheet-wrap {
                max-width: none;
                margin: 0;
                padding: 0;
            }
            .sheet {
                width: 297mm;
                min-height: 210mm;
                height: 210mm;
                margin: 0;
                border: 0;
                box-shadow: none;
                display: block;
                overflow: hidden;
                page-break-inside: avoid;
                break-inside: avoid-page;
                page-break-after: always;
            }
            .sheet:last-child {
                page-break-after: auto;
            }
            .page-body {
                padding-bottom: 32mm;
            }
            table.report {
                page-break-inside: auto;
                break-inside: auto;
            }
            tbody tr,
            .entry-block {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            thead {
                display: table-header-group;
            }
            tfoot {
                display: table-footer-group;
            }
        }
        @media (max-width: 1200px) {
            .sheet {
                width: 100%;
                min-height: auto;
            }
            .page-sheet + .page-sheet {
                margin-top: 18px;
            }
        }
        @media (max-width: 900px) {
            .template-header-strip {
                width: 100%;
            }
            .signatures {
                grid-template-columns: 1fr;
                row-gap: 10mm;
            }
            .proof-board {
                grid-template-columns: 1fr;
            }
            .proof-card img {
                height: 44mm;
            }
            .template-footer {
                position: static;
                margin-top: 8px;
                justify-content: flex-end;
            }
            .footer-meta {
                position: static;
                margin-top: 8px;
                margin-bottom: 6px;
            }
            .page-body {
                padding-bottom: 8mm;
            }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn btn-primary" type="button" onclick="window.print()">Print</button>
        <a class="btn btn-outline-primary<?php echo $canDownloadReports ? '' : ' disabled'; ?>"
           href="<?php echo $canDownloadReports ? h($printBaseHref . '&download=docx') : '#'; ?>"
           <?php echo $canDownloadReports ? '' : 'aria-disabled="true" onclick="return false;"'; ?>>Download DOCX</a>
        <a class="btn btn-outline-primary<?php echo $canDownloadReports ? '' : ' disabled'; ?>"
           href="<?php echo $canDownloadReports ? h($printBaseHref . '&download=xlsx') : '#'; ?>"
           <?php echo $canDownloadReports ? '' : 'aria-disabled="true" onclick="return false;"'; ?>>Download XLSX</a>
        <a class="btn btn-outline-primary<?php echo $canDownloadReports ? '' : ' disabled'; ?>"
           href="<?php echo $canDownloadReports ? h($printBaseHref . '&download=pdf') : '#'; ?>"
           <?php echo $canDownloadReports ? '' : 'aria-disabled="true" onclick="return false;"'; ?>>Download PDF</a>
        <a class="btn btn-outline-primary<?php echo $canDownloadReports ? '' : ' disabled'; ?>"
           href="<?php echo $canDownloadReports ? h($printBaseHref . '&download=csv') : '#'; ?>"
           <?php echo $canDownloadReports ? '' : 'aria-disabled="true" onclick="return false;"'; ?>>Download CSV</a>
        <span class="ms-auto text-muted small">
            Download cost: <?php echo number_format((float) $downloadCreditCost, 2, '.', ''); ?> credits each. Remaining: <strong><?php echo number_format((float) $creditRemaining, 2, '.', ''); ?></strong>
        </span>
        <a class="btn btn-outline-secondary" href="<?php echo h($backHref); ?>">Back</a>
    </div>

    <div class="sheet-wrap">
        <?php foreach ($reportRowPages as $reportPageIndex => $pageRows): ?>
            <?php $isLastReportPage = ($reportPageIndex === (count($reportRowPages) - 1)); ?>
            <section class="sheet page-sheet">
                <?php render_report_header(); ?>
                <div class="page-body">
                    <?php if ($reportPageIndex === 0): ?>
                        <h1 class="report-title">Monthly Accomplishment Report</h1>
                        <?php if ($showSubjectSubtitle): ?>
                            <div class="report-subtitle"><?php echo h($subjectLabel); ?></div>
                        <?php endif; ?>
                        <div class="report-meta">Month: <?php echo h($monthLabel); ?> - Year: <?php echo h($yearLabel); ?></div>
                        <div class="report-meta">School Year: <?php echo h($schoolYearTerm); ?></div>
                    <?php endif; ?>

                    <div class="table-wrap">
                        <table class="report">
                            <thead>
                                <tr>
                                    <th class="col-date">DATE</th>
                                    <th class="col-acc">ACCOMPLISHMENT</th>
                                    <th class="col-rem">
                                        REMARKS<br>
                                        <span class="remarks-head-note">(Progress and deviations from the plan) *</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($weeklyRows) === 0 && $reportPageIndex === 0): ?>
                                    <tr>
                                        <td colspan="3" style="text-align:center; color:#4b5563;">No entries found for this month and selected subject(s).</td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($pageRows as $row): ?>
                                    <?php
                                    $weekRange = format_week_range($row['start'], $row['end']);
                                    $displayEntries = acc_build_weekly_display_entries($row['entries']);
                                    $remarkItems = acc_build_weekly_remark_items($row['entries']);
                                    ?>
                                    <tr>
                                        <td><?php echo h($weekRange); ?></td>
                                        <td>
                                            <?php foreach ($displayEntries as $entry): ?>
                                                <?php
                                                $title = trim((string) ($entry['title'] ?? ''));
                                                $detailsList = is_array($entry['details'] ?? null) ? $entry['details'] : [];
                                                $proofCount = (int) ($entry['proof_count'] ?? 0);
                                                ?>
                                                <div class="entry-block">
                                                    <?php if ($title !== ''): ?>
                                                        <p class="entry-title"><?php echo h($title); ?></p>
                                                    <?php else: ?>
                                                        <p class="entry-title">Accomplishment Item</p>
                                                    <?php endif; ?>
                                                    <?php if (count($detailsList) > 0): ?>
                                                        <ul class="entry-details-list">
                                                            <?php foreach ($detailsList as $details): ?>
                                                                <li class="entry-details-item"><?php echo h((string) $details); ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php else: ?>
                                                        <p class="entry-details">-</p>
                                                    <?php endif; ?>
                                                    <?php if ($proofCount > 0): ?>
                                                        <p class="entry-proof-meta">Proof images attached: <?php echo (int) $proofCount; ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </td>
                                        <td>
                                            <div class="remarks-note">
                                                <ul class="remarks-list">
                                                    <?php foreach ($remarkItems as $remarkItem): ?>
                                                        <li class="remarks-item"><?php echo h((string) $remarkItem); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($isLastReportPage && !$signatureOwnPage): ?>
                        <p class="proof-note">*Provide relevant documentation such as pictures, summary reports, etc.</p>
                        <?php render_signature_block($displayName, $roleLabel, $approvedBy, $approvedRole); ?>
                    <?php endif; ?>
                </div>
                <?php render_report_footer($footerDocCode, $footerRevision, $footerIssueDate); ?>
            </section>
        <?php endforeach; ?>

        <?php if ($signatureOwnPage): ?>
            <section class="sheet page-sheet">
                <?php render_report_header(); ?>
                <div class="page-body">
                    <p class="proof-note">*Provide relevant documentation such as pictures, summary reports, etc.</p>
                    <?php render_signature_block($displayName, $roleLabel, $approvedBy, $approvedRole); ?>
                </div>
                <?php render_report_footer($footerDocCode, $footerRevision, $footerIssueDate); ?>
            </section>
        <?php endif; ?>

        <?php foreach ($proofPages as $pageIndex => $proofChunk): ?>
            <?php $isLastProofPage = ($pageIndex === (count($proofPages) - 1)); ?>
            <section class="sheet page-sheet proof-sheet">
                <?php render_report_header(); ?>
                <div class="page-body">
                    <?php if ($pageIndex === 0): ?>
                        <h1 class="report-title">Proof/Evidence of Reported Accomplishments</h1>
                        <?php if ($showSubjectSubtitle): ?>
                            <div class="report-subtitle"><?php echo h($subjectLabel); ?></div>
                        <?php endif; ?>
                        <div class="report-meta">Month: <?php echo h($monthLabel); ?> - Year: <?php echo h($yearLabel); ?></div>
                        <div class="report-meta">School Year: <?php echo h($schoolYearTerm); ?></div>
                    <?php endif; ?>

                    <div class="proof-board<?php echo ($pageIndex > 0) ? ' proof-board-continued' : ''; ?>">
                        <?php if (count($allProofs) === 0 && $pageIndex === 0): ?>
                            <div class="proof-empty">
                                No proof images uploaded for this month.
                            </div>
                        <?php endif; ?>

                        <?php foreach ($proofChunk as $proof): ?>
                            <?php
                            $captionParts = [];
                            $entryDate = (string) ($proof['entry_date'] ?? '');
                            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
                                $captionParts[] = date('M j, Y', strtotime($entryDate));
                            }
                            $proofTitle = trim((string) ($proof['title'] ?? ''));
                            if ($proofTitle !== '') $captionParts[] = $proofTitle;
                            $caption = implode(' | ', $captionParts);
                            if ($caption === '') $caption = (string) ($proof['label'] ?? 'Proof image');
                            $filePath = (string) ($proof['file_path'] ?? '');
                            ?>
                            <figure class="proof-card">
                                <img src="<?php echo h($filePath); ?>" alt="proof">
                                <figcaption class="proof-caption"><?php echo h($caption); ?></figcaption>
                            </figure>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($isLastProofPage): ?>
                        <p class="proof-note">*Provide relevant documentation such as pictures, summary reports, etc.</p>
                        <?php render_signature_block($displayName, $roleLabel, $approvedBy, $approvedRole); ?>
                    <?php endif; ?>
                </div>
                <?php render_report_footer($footerDocCode, $footerRevision, $footerIssueDate); ?>
            </section>
        <?php endforeach; ?>
    </div>
</body>
</html>
<?php
if ($deferVisualExport) {
    $previewHtml = (string) ob_get_clean();
    try {
        $rootPath = realpath(__DIR__ . '/../../..');
        if (!is_string($rootPath) || $rootPath === '') {
            throw new RuntimeException('Project root not found.');
        }

        $err = '';
        $pdfBinary = acc_generate_pdf_binary_from_preview_html($previewHtml, $rootPath, $err);
        if ($pdfBinary === '') {
            throw new RuntimeException($err !== '' ? $err : 'Unable to generate preview PDF.');
        }

        $binary = '';
        $mime = 'application/octet-stream';
        $ext = $pendingVisualDownload;

        if ($pendingVisualDownload === 'pdf') {
            $binary = $pdfBinary;
            $mime = 'application/pdf';
        } else {
            $tmpPngDir = '';
            $pngPaths = acc_pdf_binary_to_png_paths($pdfBinary, $tmpPngDir, $err);
            if (count($pngPaths) === 0) {
                throw new RuntimeException($err !== '' ? $err : 'Unable to build DOCX pages from preview PDF.');
            }
            $binary = acc_make_docx_binary_from_png_pages($pngPaths, $displayName);
            acc_tmp_dir_delete($tmpPngDir);
            $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }

        if (!is_string($binary) || $binary === '') {
            throw new RuntimeException('Generated visual export is empty.');
        }

        [$okCreditAfter, $creditStatusAfter] = ai_credit_get_user_status($conn, $userId);
        $remainingAfter = ($okCreditAfter && is_array($creditStatusAfter))
            ? (float) ($creditStatusAfter['remaining'] ?? 0)
            : 0;
        if (!is_array($pendingVisualAuditMeta)) $pendingVisualAuditMeta = [];
        $pendingVisualAuditMeta['ai_credit_remaining'] = $remainingAfter;
        audit_log($conn, 'accomplishment.report.downloaded', 'accomplishment_report', null, null, $pendingVisualAuditMeta);

        $filename = $exportBaseName . '.' . $ext;
        acc_send_binary_download($mime, $filename, $binary);
        exit;
    } catch (Throwable $e) {
        $refundCredits = round((float) $pendingVisualCreditCost, 2);
        if ($refundCredits > 0) {
            ai_credit_refund($conn, $userId, $refundCredits);
        }
        $_SESSION['flash_message'] = 'Unable to generate the requested visual export. Credits were refunded.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: ' . $backHref);
        exit;
    }
}
?>
