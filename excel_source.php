<?php
/**
 * Excel-backed data source (used while e-Samarth API access is not available).
 *
 *   data/catalogue/*.xlsx  - one or more files in the university format (one row per college x paper):
 *       Faculty | Course Full Name | CoursePattern | CourseTerm | College/Study Center Code |
 *       College/Study Center Name | Paper Code | Paper Name
 *   data/DataExportProgramme*.csv - e-Samarth programme master (optional; replaces the programme list taken from the catalogue)
 *   data/All_Organizational_Unit.xlsx - e-Samarth OU export: the college list (optional; else colleges come from the catalogue)
 *   data/exam_sessions.xlsx - Exam Session | Current
 *
 * The big catalogue (70,000+ rows) is parsed once and cached in data/cache/ (rebuilt automatically when a
 * file changes). Mapping:  programme = Course Full Name   subject = Paper Code + Paper Name
 *                          college   = College/Study Center Code + Name (used by the papersetter rows)
 */
require_once __DIR__ . '/xlsx_reader.php';

function excel_path(string $key): string {
    $p = cfg()['excel'][$key];
    return (preg_match('~^([A-Za-z]:|/)~', $p)) ? $p : __DIR__ . '/' . $p;
}

/** pick first non-empty value among several possible header names */
function col(array $row, array $names): string {
    foreach ($names as $n) if (isset($row[$n]) && $row[$n] !== '') return $row[$n];
    return '';
}

function excel_wrap(callable $fn): array {
    try { return ['items' => $fn(), 'source' => 'excel', 'error' => null]; }
    catch (Throwable $e) { return ['items' => [], 'source' => 'excel', 'error' => $e->getMessage()]; }
}

/** '0196' and '196' are the same college; strip leading zeros, keep '0' itself. */
function norm_college_code(string $c): string {
    $c = trim($c);
    return ctype_digit($c) ? (ltrim($c, '0') === '' ? '0' : ltrim($c, '0')) : $c;
}
function norm_space(string $s): string { return trim(preg_replace('/\s+/u', ' ', $s)); }

/** Files that make up the catalogue (a file name, or a pattern like data/catalogue/*.xlsx). */
function catalogue_files(): array {
    $f = glob(excel_path('catalogue')) ?: [];
    $f = array_values(array_filter($f, fn($x) => is_file($x) && strpos(basename($x), '~$') !== 0));
    sort($f);
    return $f;
}

/**
 * Parse all catalogue files into ['programmes'=>[id=>[...]], 'subjects'=>[progId=>[...]], 'colleges'=>[code=>name], 'rows'=>n]
 * Cached as JSON; the cache key is the list of file names + sizes + modified times.
 */
function catalogue(): array {
    static $mem = null;
    if ($mem !== null) return $mem;
    $files = catalogue_files();
    if (!$files) throw new RuntimeException('No catalogue file found: ' . excel_path('catalogue'));

    // Key = content of the files (not their path/date), so a cache built on another computer can be uploaded and reused
    $key = md5(implode('|', array_map(fn($f) => basename($f) . md5_file($f), $files)));
    $dir = __DIR__ . '/data/cache';
    $cache = "$dir/catalogue_$key.json";
    $load = function () use ($cache) {
        return (is_file($cache) && ($j = json_decode(file_get_contents($cache), true)) && isset($j['programmes'])) ? $j : null;
    };
    if ($j = $load()) return $mem = $j;

    // Only one request may build the cache; the others wait and then read it (the page asks for several lists at once)
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $lock = @fopen("$dir/build.lock", 'c');
    if ($lock) flock($lock, LOCK_EX);
    if ($j = $load()) { if ($lock) { flock($lock, LOCK_UN); fclose($lock); } return $mem = $j; }

    @set_time_limit(300);
    $progs = []; $subs = []; $colleges = []; $collegeCount = []; $rows = 0;
    foreach ($files as $file) {
        foreach (xlsx_each_assoc($file) as $r) {
            $rows++;
            $full = norm_space(col($r, ['course full name', 'programme name', 'programme', 'course']));
            if ($full === '') continue;
            $term = norm_space(col($r, ['courseterm', 'course term']));
            $code = '';
            if (preg_match('/\[([^\]]+)\]\s*$/', $term !== '' ? $term : $full, $m)) $code = $m[1];     // "Sem VI [1S00256]"
            $id = $code !== '' ? $code : substr(md5($full), 0, 10);

            if (!isset($progs[$id])) {
                $progs[$id] = ['id' => $id, 'name' => $full, 'code' => $code, 'faculty' => norm_space(col($r, ['faculty'])),
                               'pattern' => norm_space(col($r, ['coursepattern', 'course pattern'])), 'term' => $term];
            }
            $pc = norm_space(col($r, ['paper code']));
            $pn = norm_space(col($r, ['paper name']));
            if ($pc !== '' || $pn !== '') $subs[$id][$pc !== '' ? $pc : $pn] = ['id' => $pc !== '' ? $pc : $pn, 'name' => $pn, 'code' => $pc, 'title' => $pn];

            $cc = norm_college_code(col($r, ['college/study center code', 'college code', 'center code']));
            $cn = norm_space(col($r, ['college/study center name', 'college name', 'center name']));
            if ($cc !== '' && $cn !== '') $collegeCount[$cc][$cn] = ($collegeCount[$cc][$cn] ?? 0) + 1;
        }
    }
    foreach ($collegeCount as $cc => $names) { arsort($names); $colleges[$cc] = array_key_first($names); }   // most frequent spelling wins
    uksort($colleges, fn($a, $b) => (ctype_digit((string)$a) && ctype_digit((string)$b)) ? (int)$a <=> (int)$b : strcmp((string)$a, (string)$b));
    foreach ($subs as $id => $list) { ksort($list, SORT_NATURAL); $subs[$id] = array_values($list); }
    ksort($progs, SORT_NATURAL);

    if (!$progs) throw new RuntimeException('No programmes found - check the column headings of: ' . implode(', ', array_map('basename', $files)));
    $data = ['programmes' => $progs, 'subjects' => $subs, 'colleges' => $colleges, 'rows' => $rows, 'files' => array_map('basename', $files)];
    foreach (glob("$dir/catalogue_*.json") ?: [] as $old) @unlink($old);
    @file_put_contents("$cache.tmp", json_encode($data, JSON_UNESCAPED_UNICODE));
    @rename("$cache.tmp", $cache);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    return $mem = $data;
}

/**
 * Colleges from the e-Samarth "All Organizational Unit" export (config: excel.colleges).
 *   Code "MU-0003" / "Mu-560" / 1195  ->  college code 3 / 560 / 1195 (the number printed on the papersetter sheets)
 *   Only rows that have such a code are colleges / departments; faculties, hostels, sections etc. are skipped.
 *   Name = OU name plus its city ("Akbar Peerbhoy College of Commerce and Economics, Mumbai").
 * The result is cached as JSON next to the catalogue cache (key = content of the file).
 */
function ou_colleges(string $file): array {
    $dir = __DIR__ . '/data/cache';
    $cache = "$dir/ou_" . md5(basename($file) . md5_file($file)) . '.json';
    if (is_file($cache) && is_array($j = json_decode(file_get_contents($cache), true))) return $j;

    $rank = ['Affiliated College' => 1, 'Autonomous College' => 2, 'Department' => 3, 'Centre' => 4, 'Sub Campus' => 5];
    $best = [];
    foreach (xlsx_each_assoc($file) as $r) {
        $type = col($r, ['type']);
        if (!isset($rank[$type])) continue;
        if (!preg_match('/^(?:mu-?)?0*(\d+)\s*$/i', col($r, ['code']), $m)) continue;
        $code = $m[1];
        if ($code === '0') continue;                                   // placeholder code
        $name = norm_space(col($r, ['name']));
        $city = norm_space(col($r, ['city']));
        if ($name === '') continue;
        if ($city !== '' && stripos($name, $city) === false) $name .= ', ' . $city;
        if (!isset($best[$code]) || $rank[$type] < $best[$code]['rank']) $best[$code] = ['rank' => $rank[$type], 'name' => $name];
    }
    ksort($best, SORT_NUMERIC);
    $out = [];
    foreach ($best as $code => $b) $out[] = ['code' => (string)$code, 'name' => $b['name']];
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    foreach (glob("$dir/ou_*.json") ?: [] as $old) @unlink($old);
    @file_put_contents($cache, json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

function excel_colleges(): array {
    return excel_wrap(function () {
        $ou = cfg()['excel']['colleges'] ?? 'data/All_Organizational_Unit.xlsx';       // default works without touching config.php
        $path = (preg_match('~^([A-Za-z]:|/)~', $ou)) ? $ou : __DIR__ . '/' . $ou;
        if ($ou !== '' && is_file($path)) return ou_colleges($path);   // organisational-unit export
        $out = [];                                                                                         // else: from the catalogue
        foreach (catalogue()['colleges'] as $code => $name) $out[] = ['code' => (string)$code, 'name' => $name];
        return $out;
    });
}

/**
 * Programme master from the e-Samarth "Programme Course Structure" CSV (config: excel.programmes, a file or a pattern;
 * the newest matching file is used). Columns used: Programme ID, code, Offered OU (= faculty), specialization,
 * title_english, level, year_of_start. The file is Windows-1252 text, not UTF-8; it is converted here.
 * Rows damaged by an unbalanced quote (Programme ID not a number / no title) are skipped.
 */
function programme_csv_file(): ?string {
    $pat = cfg()['excel']['programmes'] ?? 'data/DataExportProgramme*.csv';
    if ($pat === '') return null;
    $pat = (preg_match('~^([A-Za-z]:|/)~', $pat)) ? $pat : __DIR__ . '/' . $pat;
    $files = array_filter(glob($pat) ?: [], 'is_file');
    if (!$files) return null;
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files[0];
}

function csv_programmes(string $file): array {
    $dir = __DIR__ . '/data/cache';
    $cache = "$dir/prog_" . md5(basename($file) . md5_file($file)) . '.json';
    if (is_file($cache) && is_array($j = json_decode(file_get_contents($cache), true))) return $j;

    $text = file_get_contents($file);
    if (substr($text, 0, 3) === "\xEF\xBB\xBF") $text = substr($text, 3);
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    $fh = fopen('php://temp', 'w+'); fwrite($fh, $text); rewind($fh);
    $head = null; $out = []; $seen = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($head === null) { $head = array_map(fn($h) => strtolower(trim($h)), $r); continue; }
        if (count($r) < count($head)) $r = array_pad($r, count($head), '');
        $a = array_combine($head, array_slice($r, 0, count($head)));
        $id = trim($a['programme id'] ?? '');
        $title = ltrim(norm_space($a['title_english'] ?? ''), '? ');          // the export has a stray leading "?" on some titles
        if (!ctype_digit($id) || $title === '' || isset($seen[$id])) continue;
        $seen[$id] = 1;
        $spec = ltrim(norm_space($a['specialization'] ?? ''), '? ');
        if ($spec !== '' && stripos($title, $spec) !== false) $spec = '';            // already part of the title
        $tag = trim(norm_space($a['level'] ?? '') . (($a['year_of_start'] ?? '') !== '' ? ' · ' . trim($a['year_of_start']) : ''), ' ·');
        $out[] = ['id' => $id, 'name' => $title, 'code' => trim($a['code'] ?? ''), 'faculty' => norm_space($a['offered ou'] ?? ''),
                  'tag' => $tag . ($spec !== '' && $spec !== '.' ? ($tag !== '' ? ' · ' : '') . $spec : '')];
    }
    fclose($fh);
    usort($out, fn($x, $y) => strcasecmp($x['name'], $y['name']));
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    foreach (glob("$dir/prog_*.json") ?: [] as $old) @unlink($old);
    @file_put_contents($cache, json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

/** true when the programme list comes from the CSV; the catalogue then only supplies papers (not tied to a programme). */
function programmes_from_csv(): bool { return programme_csv_file() !== null; }

function excel_programmes(): array {
    return excel_wrap(function () {
        if ($f = programme_csv_file()) return csv_programmes($f);
        $c = catalogue();
        return array_values(array_map(fn($p) => [
            'id' => $p['id'], 'name' => $p['name'], 'code' => $p['code'], 'faculty' => $p['faculty'],
            'tag' => count($c['subjects'][$p['id']] ?? []) . ' papers',
        ], $c['programmes']));
    });
}

/** Exam sessions: [['name'=>..., 'current'=>bool], ...] */
function excel_sessions(): array {
    return excel_wrap(function () {
        $out = [];
        foreach (xlsx_read_assoc(excel_path('sessions')) as $r) {
            $name = col($r, ['exam session', 'session', 'name']);
            if ($name === '') continue;
            $out[] = ['name' => $name, 'current' => in_array(strtolower(col($r, ['current', 'is current'])), ['yes', 'y', '1', 'true'], true)];
        }
        return $out;
    });
}

function excel_subjects(string $programmeId): array {
    return excel_wrap(function () use ($programmeId) {
        $c = catalogue();
        if (!programmes_from_csv()) return $c['subjects'][$programmeId] ?? [];
        // CSV mode: the programme file has no papers, so every paper in the catalogue files is offered (search box in the panel)
        $all = [];
        foreach ($c['subjects'] as $list) foreach ($list as $s) $all[$s['id']] ??= $s;
        uasort($all, fn($x, $y) => strnatcasecmp($x['code'] . $x['name'], $y['code'] . $y['name']));
        return array_values($all);
    });
}
