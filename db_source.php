<?php
require_once __DIR__ . '/db.php';

function db_wrap(callable $fn): array {
    try { 
        return ['items' => $fn(), 'source' => 'db', 'error' => null]; 
    } catch (Throwable $e) { 
        return ['items' => [], 'source' => 'db', 'error' => $e->getMessage()]; 
    }
}

function db_colleges(): array {
    return db_wrap(function() {
        try {
            return db()->query(
                'SELECT code, name FROM colleges WHERE is_active = 1 ORDER BY CAST(code AS UNSIGNED), code'
            )->fetchAll();
        } catch (Throwable $t) {
            return [];
        }
    });
}

function db_programmes(): array {
    return db_wrap(function () {
        $pdo = db();
        $sql = "SELECT `Programme ID` AS id, `title_english` AS name, `code`, `Offered OU` AS faculty, `specialization` AS tag 
                FROM `dataexportprogrammecoursestructure` 
                WHERE `title_english` IS NOT NULL AND `title_english` <> '' 
                ORDER BY `title_english`";
        try {
            $rows = $pdo->query($sql)->fetchAll();
        } catch (Throwable $e) {
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
        $programmeId = trim($programmeId);
        
        // 1. Try relational query if programme_subjects table has custom entries
        try {
            $st = $pdo->prepare(
                'SELECT s.id AS sid, s.name, s.code AS scode, ps.paper_code, ps.paper_title
                   FROM programme_subjects ps JOIN subjects s ON s.id = ps.subject_id
                  WHERE (ps.programme_id = ? OR ps.paper_code = ?) AND s.is_active = 1 
                  ORDER BY s.name, ps.paper_code'
            );
            $st->execute([$programmeId, $programmeId]);
            $rows = $st->fetchAll();
            if (!empty($rows)) {
                return array_map(fn($r) => [
                    'id'    => $r['paper_code'] !== '' ? $r['paper_code'] : 's' . $r['sid'],
                    'name'  => $r['name'],
                    'code'  => $r['paper_code'] !== '' ? $r['paper_code'] : (string)$r['scode'],
                    'title' => $r['paper_title'],
                ], $rows);
            }
        } catch (Throwable $e) {}

        // 2. Direct query against dataexportprogrammecoursestructure
        $sql = "SELECT `code` AS id, 
                       `title_english` AS name, 
                       `code`, 
                       `specialization` AS title 
                FROM `dataexportprogrammecoursestructure` 
                WHERE `Programme ID` = :pid 
                   OR `code` = :pid 
                   OR `title_english` LIKE :pname";

        $st = $pdo->prepare($sql);
        $st->execute([
            ':pid'   => $programmeId,
            ':pname' => '%' . $programmeId . '%'
        ]);
        $rows = $st->fetchAll();

        // 3. Fallback: If no exact match found, query top 50 rows from dataexportprogrammecoursestructure
        if (empty($rows)) {
            $rows = $pdo->query("SELECT `code` AS id, `title_english` AS name, `code`, `specialization` AS title 
                                 FROM `dataexportprogrammecoursestructure` 
                                 LIMIT 50")->fetchAll();
        }

        return array_map(fn($r) => [
            'id'    => (string)($r['code'] !== '' ? $r['code'] : $r['id']),
            'name'  => (string)$r['name'],
            'code'  => (string)$r['code'],
            'title' => (string)($r['title'] ?? ''),
        ], $rows);
    });
}
