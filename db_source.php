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

function db_colleges(): array {
    if (db_cols('colleges') && db_rowcount('colleges') > 0) {
        return db_wrap(function () {
            $code = db_pick('colleges', ['code', 'college_code']); $name = db_pick('colleges', ['name', 'college_name']);
            if (!$code || !$name) throw new RuntimeException('Table colleges needs columns code and name.');
            $act = db_pick('colleges', ['is_active']);
            return db()->query("SELECT $code AS code, $name AS name FROM colleges" . ($act ? " WHERE $act = 1" : '') . " ORDER BY CAST($code AS UNSIGNED), $code")->fetchAll();
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
        // No links (the programme CSV has no papers): offer the whole subject list; the panel has a search box
        $all = $pdo->query("SELECT id, $sname AS name, $scode AS code FROM subjects" . ($act ? " WHERE $act = 1" : '') . " ORDER BY $sname LIMIT 20000")->fetchAll();
        return array_map(fn($r) => [
            'id' => $r['code'] !== '' && $r['code'] !== null ? (string)$r['code'] : 's' . $r['id'],
            'name' => $r['name'], 'code' => (string)$r['code'], 'title' => '',
        ], $all);
    });
}

/** Row counts for test.php */
function db_counts(): array {
    $out = [];
    foreach (['colleges', 'programmes', DB_PROGRAMME_CSV_TABLE, 'subjects', 'programme_subjects', 'exam_sessions'] as $t) {
        $out[$t] = db_cols($t) ? db_rowcount($t) : 'missing';
    }
    return $out;
}
