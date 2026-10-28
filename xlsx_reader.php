<?php
/**
 * Minimal .xlsx reader (no Composer needed). Requires PHP's zip extension (enabled by default in XAMPP).
 * Streams the FIRST worksheet with XMLReader, so large files (70,000+ rows) use very little memory.
 *   xlsx_each_row($path)  -> generator of rows (arrays of trimmed strings)
 *   xlsx_read($path)      -> all rows as an array
 *   xlsx_read_assoc($path)-> rows keyed by lower-cased header text
 */

function xlsx_col_index(string $ref): int {
    preg_match('/^([A-Z]+)/i', $ref, $m);
    $n = 0;
    foreach (str_split(strtoupper($m[1] ?? 'A')) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

function xlsx_open(string $path): ZipArchive {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP zip extension is missing. In XAMPP php.ini enable "extension=zip" and restart Apache.');
    }
    if (!is_file($path)) throw new RuntimeException("Excel file not found: $path");
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException("Cannot open Excel file (is it a real .xlsx?): $path");
    return $zip;
}

function xlsx_shared_strings(ZipArchive $zip): array {
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) === false) return $shared;
    $r = new XMLReader();
    $r->XML($xml);
    $cur = null;
    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'si') {
            $cur = '';
            if ($r->isEmptyElement) { $shared[] = ''; $cur = null; }
        } elseif ($r->nodeType === XMLReader::ELEMENT && $r->name === 't' && $cur !== null) {
            $cur .= $r->readString();                   // <t> directly or inside rich-text <r> runs
        } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'si' && $cur !== null) {
            $shared[] = $cur; $cur = null;
        }
    }
    $r->close();
    return $shared;
}

function xlsx_first_sheet_path(ZipArchive $zip): string {
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb   = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $first = simplexml_load_string($wb)->sheets->sheet[0] ?? null;
        if ($first) {
            $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach (simplexml_load_string($rels)->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $t = ltrim((string)$rel['Target'], '/');
                    $sheetPath = (strpos($t, 'xl/') === 0) ? $t : 'xl/' . $t;
                    break;
                }
            }
        }
    }
    return $sheetPath;
}

function xlsx_each_row(string $path): Generator {
    $zip    = xlsx_open($path);
    $shared = xlsx_shared_strings($zip);
    $sheet  = xlsx_first_sheet_path($zip);
    if ($zip->locateName($sheet) === false) { $zip->close(); throw new RuntimeException("Worksheet not found inside $path"); }
    $stream = $zip->getStream($sheet);
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $out = fopen($tmp, 'wb');
    stream_copy_to_stream($stream, $out);               // XMLReader::open needs a file path
    fclose($out); fclose($stream);
    $zip->close();

    $r = new XMLReader();
    $r->open($tmp, null, LIBXML_NONET | LIBXML_COMPACT);
    try {
        while ($r->read()) {
            if ($r->nodeType !== XMLReader::ELEMENT || $r->name !== 'row') continue;
            $cells = [];
            if (!$r->isEmptyElement) {
                $depth = $r->depth;
                while ($r->read() && !($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'row' && $r->depth === $depth)) {
                    if ($r->nodeType !== XMLReader::ELEMENT || $r->name !== 'c') continue;
                    $idx  = xlsx_col_index((string)$r->getAttribute('r'));
                    $type = (string)$r->getAttribute('t');
                    $val  = '';
                    if (!$r->isEmptyElement) {
                        $cd = $r->depth;
                        while ($r->read() && !($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'c' && $r->depth === $cd)) {
                            if ($r->nodeType !== XMLReader::ELEMENT) continue;
                            if ($r->name === 'v') $val = $r->readString();
                            elseif ($r->name === 't') $val .= $r->readString();       // inlineStr
                        }
                    }
                    if ($type === 's') $val = $shared[(int)$val] ?? '';
                    elseif ($type === '' || $type === 'n') {
                        // numbers like 97527.0 -> 97527
                        if (is_numeric($val) && (float)$val == (int)(float)$val && stripos($val, 'E') === false) $val = (string)(int)(float)$val;
                    }
                    $cells[$idx] = trim($val);
                }
            }
            if (!$cells) continue;
            $out = [];
            for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) $out[] = $cells[$i] ?? '';
            yield $out;
        }
    } finally {
        $r->close();
        @unlink($tmp);
    }
}

function xlsx_read(string $path): array {
    return iterator_to_array(xlsx_each_row($path), false);
}

/** Generator of associative rows keyed by lower-cased header text (empty rows skipped). */
function xlsx_each_assoc(string $path): Generator {
    $head = null;
    foreach (xlsx_each_row($path) as $r) {
        if ($head === null) { $head = array_map(fn($h) => strtolower(trim($h)), $r); continue; }
        if (!array_filter($r, fn($v) => $v !== '')) continue;
        $a = [];
        foreach ($head as $i => $h) if ($h !== '') $a[$h] = $r[$i] ?? '';
        yield $a;
    }
}

function xlsx_read_assoc(string $path): array {
    return iterator_to_array(xlsx_each_assoc($path), false);
}
