<?php
// Shared export helpers for report templates.

if (!function_exists('report_send_binary_download')) {
    function report_send_binary_download($mime, $filename, $binary) {
        $mime = (string) $mime;
        if ($mime === '') $mime = 'application/octet-stream';
        $filename = (string) $filename;
        $binary = (string) $binary;

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

if (!function_exists('report_zip_dos_datetime')) {
    function report_zip_dos_datetime() {
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

if (!function_exists('report_zip_pack_store')) {
    /**
     * Build a ZIP archive using the "store" method (no compression).
     * This avoids requiring the PHP ZipArchive extension.
     */
    function report_zip_pack_store(array $files) {
        [$dosTime, $dosDate] = report_zip_dos_datetime();
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

if (!function_exists('report_tmp_dir_create')) {
    function report_tmp_dir_create($prefix = 'rep_') {
        $base = sys_get_temp_dir();
        if (!is_string($base) || $base === '') return '';

        $token = '';
        try {
            $token = bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            $token = uniqid('', true);
            $token = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $token);
        }

        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $prefix . $token;
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) return '';
        return $dir;
    }
}

if (!function_exists('report_tmp_dir_delete')) {
    function report_tmp_dir_delete($dir) {
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
                report_tmp_dir_delete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

if (!function_exists('report_export_find_python')) {
    function report_export_find_python() {
        static $cached = null;
        if ($cached !== null) return (string) $cached;

        foreach (['python', 'python3'] as $candidate) {
            $out = [];
            $status = 1;
            @exec($candidate . ' -V 2>&1', $out, $status);
            $ver = trim(implode(' ', $out));
            $isPy3 = (preg_match('/Python\\s+3\\./i', $ver) === 1);
            if ($status === 0 && ($candidate === 'python3' || $isPy3)) {
                $cached = $candidate;
                return (string) $cached;
            }
        }
        $cached = '';
        return '';
    }
}

if (!function_exists('report_generate_pdf_binary_from_html')) {
    function report_generate_pdf_binary_from_html($html, $basePath, &$errorMsg = '') {
        $errorMsg = '';
        $html = (string) $html;
        $basePath = (string) $basePath;
        if ($html === '') {
            $errorMsg = 'HTML is empty.';
            return '';
        }

        $python = report_export_find_python();
        if ($python === '') {
            $errorMsg = 'Python not found on server. Install python3 and WeasyPrint.';
            return '';
        }

        $tmpDir = report_tmp_dir_create('rep_pdf_');
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
            report_tmp_dir_delete($tmpDir);
            $errorMsg = 'Unable to prepare export files.';
            return '';
        }

        $cmd = $python . ' '
            . escapeshellarg($pyPath) . ' '
            . escapeshellarg($htmlPath) . ' '
            . escapeshellarg($pdfPath) . ' '
            . escapeshellarg(str_replace('\\', '/', $basePath));

        $out = [];
        $status = 1;
        @exec($cmd . ' 2>&1', $out, $status);

        if ($status !== 0 || !is_file($pdfPath)) {
            $errorMsg = 'PDF renderer failed: ' . trim(implode(' ', $out));
            report_tmp_dir_delete($tmpDir);
            return '';
        }

        $pdf = (string) @file_get_contents($pdfPath);
        report_tmp_dir_delete($tmpDir);
        if ($pdf === '') {
            $errorMsg = 'Generated PDF is empty.';
            return '';
        }
        return $pdf;
    }
}

if (!function_exists('report_pdf_binary_to_png_paths')) {
    function report_pdf_binary_to_png_paths($pdfBinary, &$tmpDirOut = '', &$errorMsg = '') {
        $errorMsg = '';
        $tmpDirOut = '';
        $pdfBinary = (string) $pdfBinary;
        if ($pdfBinary === '') {
            $errorMsg = 'PDF data is empty.';
            return [];
        }

        $tmpDir = report_tmp_dir_create('rep_pdf_pages_');
        if ($tmpDir === '') {
            $errorMsg = 'Unable to create temp directory for PDF pages.';
            return [];
        }

        $pdfPath = $tmpDir . DIRECTORY_SEPARATOR . 'source.pdf';
        if (@file_put_contents($pdfPath, $pdfBinary) === false) {
            report_tmp_dir_delete($tmpDir);
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
            report_tmp_dir_delete($tmpDir);
            return [];
        }

        $pngFiles = glob($tmpDir . DIRECTORY_SEPARATOR . 'page-*.png');
        if (!is_array($pngFiles) || count($pngFiles) === 0) {
            $errorMsg = 'No PNG pages generated from PDF.';
            report_tmp_dir_delete($tmpDir);
            return [];
        }

        natsort($pngFiles);
        $tmpDirOut = $tmpDir;
        return array_values($pngFiles);
    }
}

if (!function_exists('report_export_xml')) {
    function report_export_xml($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

if (!function_exists('report_docx_fit_emu')) {
    function report_docx_fit_emu($imgW, $imgH, $maxWEmu, $maxHEmu) {
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

if (!function_exists('report_mm_to_twips')) {
    function report_mm_to_twips($mm) {
        $mm = (float) $mm;
        if ($mm <= 0) return 0;
        return (int) round($mm * (1440.0 / 25.4));
    }
}

if (!function_exists('report_make_docx_binary_from_png_pages')) {
    /**
     * Build a DOCX that embeds each page PNG as a full-page image.
     * Note: This is "visual DOCX" (not editable text).
     */
    function report_make_docx_binary_from_png_pages(array $pngPaths, array $pageSpec, array $meta = []) {
        $title = trim((string) ($meta['title'] ?? 'Report'));
        if ($title === '') $title = 'Report';
        $creator = trim((string) ($meta['creator'] ?? 'Doc-Ease'));
        if ($creator === '') $creator = 'Doc-Ease';

        $widthMm = (float) ($pageSpec['width_mm'] ?? 210.0);
        $heightMm = (float) ($pageSpec['height_mm'] ?? 297.0);
        $orientation = strtolower(trim((string) ($pageSpec['orientation'] ?? 'portrait')));
        if (!in_array($orientation, ['portrait', 'landscape'], true)) $orientation = 'portrait';

        $pageW = report_mm_to_twips($widthMm);
        $pageH = report_mm_to_twips($heightMm);
        if ($pageW <= 0) $pageW = 11906;
        if ($pageH <= 0) $pageH = 16838;

        // Keep margins at 0 for best page-fit of the snapshot images.
        $margin = 0;
        $maxWEmu = (int) (($pageW - (2 * $margin)) * 635);
        $maxHEmu = (int) (($pageH - (2 * $margin)) * 635);

        $media = [];
        $rels = [];
        $docBlocks = [];
        $docPrId = 1;

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
            [$cx, $cy] = report_docx_fit_emu($imgW, $imgH, $maxWEmu, $maxHEmu);

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
            . '<w:sectPr><w:pgSz w:w="' . $pageW . '" w:h="' . $pageH . '" w:orient="' . $orientation . '"/>'
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
            . '<dc:title>' . report_export_xml($title) . '</dc:title>'
            . '<dc:creator>' . report_export_xml($creator) . '</dc:creator>'
            . '<cp:lastModifiedBy>' . report_export_xml($creator) . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\\TH:i:s\\Z') . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\\TH:i:s\\Z') . '</dcterms:modified>'
            . '</cp:coreProperties>';

        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Doc-Ease</Application>'
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
        return report_zip_pack_store($files);
    }
}
