<?php
/**
 * Minimal .xlsx writer (no Composer needed; uses PHP's zip extension, enabled by default in XAMPP).
 * First row is written as a bold header with a frozen pane and filter buttons.
 * Every cell is stored as text so codes / mobile numbers keep their leading zeros.
 */

function xlsx_col_letter(int $i): string {          // 0 -> A, 25 -> Z, 26 -> AA
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

function xlsx_esc($v): string {
    $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$v);
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Sheet names: max 31 chars, no \ / ? * [ ] :, unique within the workbook. */
function xlsx_sheet_name(string $name, array &$used): string {
    $n = trim(substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $name), 0, 31)) ?: 'Sheet';
    $base = $n; $i = 2;
    while (isset($used[strtolower($n)])) { $suffix = ' ' . $i++; $n = substr($base, 0, 31 - strlen($suffix)) . $suffix; }
    $used[strtolower($n)] = true;
    return $n;
}

function xlsx_sheet_xml(array $rows, array $widths, string $ns): string {
    $ncols = 0;
    foreach ($rows as $r) $ncols = max($ncols, count($r));
    $lastRef = xlsx_col_letter(max(0, $ncols - 1)) . max(1, count($rows));
    $cols = '';
    foreach ($widths as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
    $data = '';
    foreach (array_values($rows) as $ri => $row) {
        $data .= '<row r="' . ($ri + 1) . '">';
        foreach (array_values($row) as $ci => $v) {
            if ($v === null || $v === '') continue;
            if ($ri > 0 && is_int($v)) {                       // real PHP integers (counts) become numbers; everything else stays text
                $data .= '<c r="' . xlsx_col_letter($ci) . ($ri + 1) . '"><v>' . $v . '</v></c>';
                continue;
            }
            $data .= '<c r="' . xlsx_col_letter($ci) . ($ri + 1) . '" t="inlineStr"' . ($ri === 0 ? ' s="1"' : '') . '>'
                   . '<is><t xml:space="preserve">' . xlsx_esc($v) . '</t></is></c>';
        }
        $data .= '</row>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="' . $ns . '">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
        . '<sheetData>' . $data . '</sheetData>'
        . (count($rows) > 1 ? '<autoFilter ref="A1:' . $lastRef . '"/>' : '')
        . '</worksheet>';
}

/**
 * Write a workbook with several sheets.
 * @param array $sheets list of ['name' => string, 'rows' => list of rows (rows[0] = header), 'widths' => optional list]
 */
function xlsx_write_sheets(string $path, array $sheets): void {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP zip extension is missing. Enable "extension=zip" in php.ini and restart the web server.');
    }
    if (!$sheets) throw new InvalidArgumentException('No sheets to write');
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $used = []; $n = count($sheets);

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wbSheets = ''; $wbRels = '';
    $files = [];
    foreach (array_values($sheets) as $i => $sh) {
        $k = $i + 1;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wbSheets .= '<sheet name="' . xlsx_esc(xlsx_sheet_name($sh['name'] ?? 'Sheet', $used)) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
        $wbRels  .= '<Relationship Id="rId' . $k . '" Type="' . $rel . '/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
        $files['xl/worksheets/sheet' . $k . '.xml'] = xlsx_sheet_xml($sh['rows'], $sh['widths'] ?? [], $ns);
    }
    $ct .= '</Types>';
    $wbRels .= '<Relationship Id="rId' . ($n + 1) . '" Type="' . $rel . '/styles" Target="styles.xml"/>';

    $files += [
        '[Content_Types].xml' => $ct,
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="' . $ns . '" xmlns:r="' . $rel . '"><sheets>' . $wbSheets . '</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="' . $ns . '">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF2D5BE3"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
    ];

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException("Cannot create $path");
    foreach ($files as $name => $xml) $zip->addFromString($name, $xml);
    $zip->close();
}

/** Single-sheet convenience wrapper (kept for older callers). */
function xlsx_write(string $path, array $rows, string $sheet = 'Data', array $widths = []): void {
    xlsx_write_sheets($path, [['name' => $sheet, 'rows' => $rows, 'widths' => $widths]]);
}
