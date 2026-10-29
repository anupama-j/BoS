<?php
/**
 * MySQL-backed data source (config 'source' => 'db').
 *   programmes : table `dataexportprogrammecoursestructure` (the e-Samarth programme CSV imported as-is) when it has rows,
 *                otherwise the `programmes` table.
 *   subjects   : `programme_subjects` + `subjects` for a programme that has links; otherwise the whole `subjects` list.
 *   colleges   : `colleges` table; when it is empty the Organizational Unit file (data/All_Organizational_Unit.xlsx) is used.
 *   sessions   : `exam_sessions` table; when it is empty data/exam_sessions.xlsx is used.
 * Returns the same shapes as excel_source.php so the page needs no changes. Columns are looked up first, so tables
 * imported from older versions (missing is_active etc.) still work.
 */
require_once __DIR__ . '/db.php';

const DB_PROGRAMME_CSV_TABLE = 'dataexportprogrammecoursestructure';
const DB_COURSES_TABLE = 'programme_courses';

function db_wrap(callable $fn): array {
    try { return ['items' => $fn(), 'source' => 'db', 'error' => null]; }
    catch (Throwable $e) { return ['items' => [], 'source' => 'db', 'error' => $e->getMessage()]; }
}

/** column names (lower-case => real name) of a table, or [] when the table does not exist */
function db_cols(string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $out = [];
    try {
        foreach (db()->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll() as $r) $out[strtolower($r['Field'])] = $r['Field'];
    } catch (Throwable $e) {
        try { foreach (db()->query('PRAGMA table_info(`' . str_replace('`', '', $table) . '`)')->fetchAll() as $r) $out[strtolower($r['name'])] = $r['name']; }
        catch (Throwable $e2) {}
    }
    return $cache[$table] = $out;
}
function db_q(string $col): string { return '`' . str_replace('`', '', $col) . '`'; }
/** first existing column among candidates, quoted, or null */
function db_pick(string $table, array $cands): ?string {
    $c = db_cols($table);
    foreach ($cands as $n) if (isset($c[strtolower($n)])) return db_q($c[strtolower($n)]);
    return null;
}
function db_rowcount(string $table): int {
    static $n = [];
    if (!isset($n[$table])) { try { $n[$table] = (int)db()->query('SELECT COUNT(*) FROM ' . db_q($table))->fetchColumn(); } catch (Throwable $e) { $n[$table] = 0; } }
    return $n[$table];
}

/** true when the imported programme CSV table exists and has data */
function db_csv_table_mode(): bool { return db_cols(DB_PROGRAMME_CSV_TABLE) && db_rowcount(DB_PROGRAMME_CSV_TABLE) > 0; }

/** true when programme_courses (programme_code, course_code, course_name, description) exists and has active rows */
function db_courses_mode(): bool {
    static $m = null;
    if ($m === null) {
        $m = false;
        if (db_cols(DB_COURSES_TABLE)) {
            $act = db_pick(DB_COURSES_TABLE, ['is_active']);
            try { $m = (int)db()->query('SELECT COUNT(*) FROM ' . db_q(DB_COURSES_TABLE) . ($act ? " WHERE $act = 1" : ''))->fetchColumn() > 0; } catch (Throwable $e) {}
        }
    }
    return $m;
}
/** set of programme codes that have courses in programme_courses */
function db_courses_programme_codes(): array {
    $act = db_pick(DB_COURSES_TABLE, ['is_active']);
    $rows = db()->query('SELECT DISTINCT programme_code FROM ' . db_q(DB_COURSES_TABLE) . ($act ? " WHERE $act = 1" : ''))->fetchAll(PDO::FETCH_COLUMN);
    return array_fill_keys(array_map('strval', $rows), true);
}
/** code of a programme from its id (CSV table or programmes table) */
function db_programme_code(string $id): string {
    $pdo = db();
    if (db_csv_table_mode()) {
        $idc = db_pick(DB_PROGRAMME_CSV_TABLE, ['Programme ID', 'programme_id', 'id']); $cc = db_pick(DB_PROGRAMME_CSV_TABLE, ['code']);
        if ($idc && $cc) { $st = $pdo->prepare('SELECT ' . $cc . ' FROM ' . db_q(DB_PROGRAMME_CSV_TABLE) . ' WHERE ' . $idc . ' = ? LIMIT 1'); $st->execute([$id]); return trim((string)$st->fetchColumn()); }
    } elseif (db_cols('programmes') && ($cc = db_pick('programmes', ['code']))) {
        $st = $pdo->prepare("SELECT $cc FROM programmes WHERE id = ? LIMIT 1"); $st->execute([(int)$id]); return trim((string)$st->fetchColumn());
    }
    return '';
}

function db_colleges(): array {
    if (db_cols('colleges') && db_rowcount('colleges') > 0) {
        return db_wrap(function () {
            $code = db_pick('colleges', ['code', 'college_code']); $name = db_pick('colleges', ['name', 'college_name']);
            if (!$code || !$name) throw new RuntimeException('Table colleges needs columns code and name.');
            $act = db_pick('colleges', ['is_active']);
            $q = fn(bool $only) => db()->query("SELECT $code AS code, $name AS name FROM colleges" . ($only && $act ? " WHERE $act = 1" : '') . " ORDER BY CAST($code AS UNSIGNED), $code")->fetchAll();
            return $q(true) ?: $q(false);                          // is_active may be NULL / 0 in an imported table
        });
    }
    return excel_colleges();                                   // table empty: use the Organizational Unit file
}

function db_programmes(): array {
    if (db_csv_table_mode()) {
        return db_wrap(function () {
            $t = DB_PROGRAMME_CSV_TABLE; $out = []; $seen = [];
            $id = db_pick($t, ['Programme ID', 'programme_id', 'id']); $title = db_pick($t, ['title_english', 'title']);
            if (!$id || !$title) throw new RuntimeException("Table $t needs the columns 'Programme ID' and 'title_english'.");
            $code = db_pick($t, ['code']) ?: "''"; $fac = db_pick($t, ['Offered OU', 'faculty']) ?: "''"; $spec = db_pick($t, ['specialization']) ?: "''";
            $lvl = db_pick($t, ['level']) ?: "''"; $yr = db_pick($t, ['year_of_start']) ?: "''";
            $q = db()->query("SELECT $id AS id, $title AS title, $code AS code, $fac AS faculty, $spec AS spec, $lvl AS lvl, $yr AS yr FROM " . db_q($t));
            foreach ($q->fetchAll() as $r) {
                $pid = trim((string)$r['id']);
                $name = ltrim(trim(preg_replace('/\s+/u', ' ', (string)$r['title'])), '? ');
                if (!ctype_digit($pid) || $name === '' || isset($seen[$pid])) continue;       // rows damaged by the CSV import
                $seen[$pid] = 1;
                $spec = ltrim(trim(preg_replace('/\s+/u', ' ', (string)$r['spec'])), '? ');
                if ($spec === '.' || ($spec !== '' && stripos($name, $spec) !== false)) $spec = '';
                $tag = trim(trim((string)$r['lvl']) . ((string)$r['yr'] !== '' ? ' · ' . trim((string)$r['yr']) : '') . ($spec !== '' ? ' · ' . $spec : ''), ' ·');
                $out[] = ['id' => $pid, 'name' => $name, 'code' => trim((string)$r['code']), 'faculty' => trim((string)$r['faculty']), 'tag' => $tag];
            }
            usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            return $out;
        });
    }
    return db_wrap(function () {
        $f = db_pick('programmes', ['faculty']); $sp = db_pick('programmes', ['specialization']); $act = db_pick('programmes', ['is_active']);
        $facE = $f ? "p.$f" : "''"; $spE = $sp ? "p.$sp" : "''";
        $rows = db()->query(
            "SELECT p.id, p.name, p.code, $facE AS faculty, $spE AS specialization, COUNT(ps.id) AS n
               FROM programmes p LEFT JOIN programme_subjects ps ON ps.programme_id = p.id" . ($act ? " WHERE p.$act = 1" : '') .
            " GROUP BY p.id, p.name, p.code" . ($f ? ", $facE" : '') . ($sp ? ", $spE" : '') . ' ORDER BY p.name'
        )->fetchAll();
        return array_map(fn($r) => [
            'id' => (string)$r['id'], 'name' => $r['name'], 'code' => (string)$r['code'], 'faculty' => (string)$r['faculty'],
            'tag' => $r['n'] > 0 ? $r['n'] . ' subjects' : (string)$r['specialization'],
        ], $rows);
    });
}

/** Exam sessions: [['name'=>..., 'current'=>bool], ...] */
function db_sessions(): array {
    if (db_cols('exam_sessions') && db_rowcount('exam_sessions') > 0) {
        return db_wrap(function () {
            $name = db_pick('exam_sessions', ['name', 'exam_session', 'session']); $cur = db_pick('exam_sessions', ['is_current', 'current']);
            if (!$name) throw new RuntimeException('Table exam_sessions needs a column called name.');
            $rows = db()->query("SELECT $name AS name" . ($cur ? ", $cur AS cur" : ', 0 AS cur') . ' FROM exam_sessions ORDER BY ' . ($cur ? "$cur DESC, " : '') . 'id DESC')->fetchAll();
            return array_map(fn($r) => ['name' => $r['name'], 'current' => in_array(strtolower((string)$r['cur']), ['1', 'yes', 'y', 'true'], true)], $rows);
        });
    }
    return excel_sessions();                                   // table empty: use data/exam_sessions.xlsx
}

function db_subjects(string $programmeId): array {
    return db_wrap(function () use ($programmeId) {
        $pdo = db();

        // 1. the courses table: programme_code -> course_code, course_name, description
        if (db_courses_mode() && ($pcode = db_programme_code($programmeId)) !== '') {
            $act = db_pick(DB_COURSES_TABLE, ['is_active']);
            $st = $pdo->prepare('SELECT course_code, course_name, description FROM ' . db_q(DB_COURSES_TABLE) . ' WHERE programme_code = ?' . ($act ? " AND $act = 1" : '') . ' ORDER BY course_code');
            $st->execute([$pcode]);
            if ($rows = $st->fetchAll()) {
                return array_map(fn($r) => ['id' => (string)$r['course_code'], 'name' => (string)$r['course_name'], 'code' => (string)$r['course_code'],
                                            'title' => (string)$r['course_name'], 'desc' => (string)($r['description'] ?? '')], $rows);
            }
        }
        $scode = db_pick('subjects', ['code']) ?: "''"; $sname = db_pick('subjects', ['name', 'title']); $act = db_pick('subjects', ['is_active']);
        if (!$sname) throw new RuntimeException('Table subjects needs a column called name.');

        // A programme with links in programme_subjects (only possible when programmes come from the `programmes` table)
        if (!db_csv_table_mode() && db_cols('programme_subjects')) {
            $st = $pdo->prepare(
                "SELECT s.id AS sid, s.$sname AS name, s.$scode AS scode, ps.paper_code, ps.paper_title
                   FROM programme_subjects ps JOIN subjects s ON s.id = ps.subject_id
                  WHERE ps.programme_id = ?" . ($act ? " AND s.$act = 1" : '') . " ORDER BY s.$sname, ps.paper_code"
            );
            $st->execute([(int)$programmeId]);
            if ($rows = $st->fetchAll()) {
                return array_map(fn($r) => [
                    'id' => $r['paper_code'] !== '' && $r['paper_code'] !== null ? $r['paper_code'] : 's' . $r['sid'],
                    'name' => $r['name'], 'code' => $r['paper_code'] !== '' && $r['paper_code'] !== null ? $r['paper_code'] : (string)$r['scode'], 'title' => (string)$r['paper_title'],
                ], $rows);
            }
        }
        // No links (the programme CSV has no papers): offer every subject; the panel has a search box.
        $q = function (bool $activeOnly) use ($pdo, $sname, $scode, $act) {
            return $pdo->query("SELECT id, $sname AS name, $scode AS code FROM subjects" . ($activeOnly && $act ? " WHERE $act = 1" : '') . " ORDER BY $sname LIMIT 20000")->fetchAll();
        };
        $all = db_cols('subjects') ? ($q(true) ?: $q(false)) : [];        // is_active may be NULL / 0 in an imported table
        if ($all) {
            return array_map(fn($r) => [
                'id' => $r['code'] !== '' && $r['code'] !== null ? (string)$r['code'] : 's' . $r['id'],
                'name' => $r['name'], 'code' => (string)$r['code'], 'title' => '',
            ], $all);
        }
        // subjects table is empty: use the paper list held in programme_subjects, then the catalogue files
        if (db_cols('programme_subjects') && ($pc = db_pick('programme_subjects', ['paper_code'])) && ($pt = db_pick('programme_subjects', ['paper_title']))) {
            $rows = $pdo->query("SELECT DISTINCT $pc AS code, $pt AS title FROM programme_subjects WHERE $pc IS NOT NULL AND $pc <> '' ORDER BY $pc LIMIT 20000")->fetchAll();
            if ($rows) return array_map(fn($r) => ['id' => (string)$r['code'], 'name' => (string)$r['title'], 'code' => (string)$r['code'], 'title' => (string)$r['title']], $rows);
        }
        try {
            $cat = catalogue(); $u = [];
            foreach ($cat['subjects'] as $list) foreach ($list as $x) $u[$x['id']] ??= $x;
            if ($u) { uasort($u, fn($x, $y) => strnatcasecmp($x['code'] . $x['name'], $y['code'] . $y['name'])); return array_values($u); }
        } catch (Throwable $e) { /* no catalogue files either */ }
        throw new RuntimeException('No subjects found. Table subjects has ' . db_rowcount('subjects') . ' row(s), programme_subjects has '
            . db_rowcount('programme_subjects') . ' and data/catalogue has no .xlsx. Import the subjects/papers list into the subjects table (columns code, name, is_active).');
    });
}

/** Row counts for test.php */
function db_counts(): array {
    $out = [];
    foreach (['colleges', 'programmes', DB_PROGRAMME_CSV_TABLE, DB_COURSES_TABLE, 'subjects', 'programme_subjects', 'exam_sessions'] as $t) {
        $out[$t] = db_cols($t) ? db_rowcount($t) : 'missing';
    }
    return $out;
}
