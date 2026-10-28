<?php
require_once __DIR__ . '/db.php';

function db_wrap(callable $fn): array {
    try { return ['items' => $fn(), 'source' => 'db', 'error' => null]; }
    catch (Throwable $e) { return ['items' => [], 'source' => 'db', 'error' => $e->getMessage()]; }
}

function db_colleges(): array {
    return db_wrap(function() {
        // Try querying colleges table if exists, else return empty
        try {
            return db()->query(
                'SELECT code, name FROM colleges WHERE is_active = 1 ORDER BY CAST(code AS UNSIGNED), code'
            )->fetchAll();
        } catch (Throwable $t) {
            return [];
        }
    });
}

/**
 * Fetch programmes directly from `dataexportprogrammecoursestructure` table.
 */
function db_programmes(): array {
    return db_wrap(function () {
        $pdo = db();
        // Check if `dataexportprogrammecoursestructure` exists
        $sql = "SELECT `Programme ID` AS id, `title_english` AS name, `code`, `Offered OU` AS faculty, `specialization` AS tag 
                FROM `dataexportprogrammecoursestructure` 
                WHERE `title_english` IS NOT NULL AND `title_english` <> '' 
                ORDER BY `title_english`";
        try {
            $rows = $pdo->query($sql)->fetchAll();
        } catch (Throwable $e) {
            // Fallback if table name is lowercase or without spaces
            $sql = "SELECT `Programme ID` AS id, `title_english` AS name, `code`, `Offered OU` AS faculty, `specialization` AS tag 
                    FROM dataexportprogrammecoursestructure 
                    ORDER BY title_english";
            $rows = $pdo->query($sql)->fetchAll();
        }

        return array_map(fn($r) => [
            'id'      => (string)($r['id'] ?? $r['code']),
            'name'    => (string)($r['name'] ?? ''),
            'code'    => (string)($r['code'] ?? ''),
            'faculty' => (string)($r['faculty'] ?? ''),
            'tag'     => (string)($r['tag'] ?? ''),
        ], $rows);
    });
}

function db_sessions(): array {
    return db_wrap(function() {
        try {
            return array_map(
                fn($r) => ['name' => $r['name'], 'current' => (bool)$r['is_current']],
                db()->query('SELECT name, is_current FROM exam_sessions ORDER BY is_current DESC, id DESC')->fetchAll()
            );
        } catch (Throwable $e) {
            return [['name' => 'FIRST HALF 2026', 'current' => true]];
        }
    });
}

function db_subjects(string $programmeId): array {
    return db_wrap(function () use ($programmeId) {
        $pdo = db();
        // Check if programme_subjects has records, otherwise pull courses from dataexportprogrammecoursestructure
        try {
            $st = $pdo->prepare(
                'SELECT s.id AS sid, s.name, s.code AS scode, ps.paper_code, ps.paper_title
                   FROM programme_subjects ps JOIN subjects s ON s.id = ps.subject_id
                  WHERE ps.programme_id = ? AND s.is_active = 1 ORDER BY s.name, ps.paper_code'
            );
            $st->execute([$programmeId]);
            $rows = $st->fetchAll();
            if ($rows) {
                return array_map(fn($r) => [
                    'id'    => $r['paper_code'] !== '' ? $r['paper_code'] : 's' . $r['sid'],
                    'name'  => $r['name'],
                    'code'  => $r['paper_code'] !== '' ? $r['paper_code'] : (string)$r['scode'],
                    'title' => $r['paper_title'],
                ], $rows);
            }
        } catch (Throwable $e) {}

        // Fallback to reading subject from dataexportprogrammecoursestructure for this Programme ID / code
        $st = $pdo->prepare("SELECT `code` AS id, `title_english` AS name, `code`, `specialization` AS title 
                             FROM `dataexportprogrammecoursestructure` 
                             WHERE `Programme ID` = ? OR `code` = ?");
        $st->execute([$programmeId, $programmeId]);
        $rows = $st->fetchAll();

        if (!$rows) {
            // General query fallback
            $rows = $pdo->query("SELECT `code` AS id, `title_english` AS name, `code`, `specialization` AS title 
                                 FROM `dataexportprogrammecoursestructure` LIMIT 500")->fetchAll();
        }

        return array_map(fn($r) => [
            'id'    => (string)$r['id'],
            'name'  => (string)$r['name'],
            'code'  => (string)$r['code'],
            'title' => (string)($r['title'] ?? ''),
        ], $rows);
    });
}
