<?php
require_once __DIR__ . '/db.php';

function db_wrap(callable $fn): array {
    try { return ['items' => $fn(), 'source' => 'db', 'error' => null]; }
    catch (Throwable $e) { return ['items' => [], 'source' => 'db', 'error' => $e->getMessage()]; }
}

function db_colleges(): array {
    return db_wrap(fn() => db()->query(
        'SELECT code, name FROM colleges WHERE is_active = 1 ORDER BY CAST(code AS UNSIGNED), code'
    )->fetchAll());
}

function db_programmes(): array {
    return db_wrap(function () {
        $rows = db()->query(
            'SELECT p.id, p.name, p.code, p.faculty, p.specialization, COUNT(ps.id) AS n
               FROM programmes p LEFT JOIN programme_subjects ps ON ps.programme_id = p.id
              WHERE p.is_active = 1 GROUP BY p.id ORDER BY p.name'
        )->fetchAll();
        return array_map(fn($r) => [
            'id'   => (string)$r['id'],
            'name' => $r['name'],
            'code' => (string)$r['code'],
            'faculty' => (string)$r['faculty'],
            'tag'  => $r['n'] > 0 ? $r['n'] . ' subjects' : (string)$r['specialization'],
        ], $rows);
    });
}

function db_sessions(): array {
    return db_wrap(fn() => array_map(
        fn($r) => ['name' => $r['name'], 'current' => (bool)$r['is_current']],
        db()->query('SELECT name, is_current FROM exam_sessions ORDER BY is_current DESC, id DESC')->fetchAll()
    ));
}

function db_subjects(string $programmeId): array {
    return db_wrap(function () use ($programmeId) {
        $pdo = db();
        $st = $pdo->prepare(
            'SELECT s.id AS sid, s.name, s.code AS scode, ps.paper_code, ps.paper_title
               FROM programme_subjects ps JOIN subjects s ON s.id = ps.subject_id
              WHERE ps.programme_id = ? AND s.is_active = 1 ORDER BY s.name, ps.paper_code'
        );
        $st->execute([(int)$programmeId]);
        $rows = $st->fetchAll();

        if ($rows) {
            return array_map(fn($r) => [
                'id'    => $r['paper_code'] !== '' ? $r['paper_code'] : 's' . $r['sid'],
                'name'  => $r['name'],
                'code'  => $r['paper_code'] !== '' ? $r['paper_code'] : (string)$r['scode'],
                'title' => $r['paper_title'],
            ], $rows);
        }
        $all = $pdo->query('SELECT id, name, code FROM subjects WHERE is_active = 1 ORDER BY name LIMIT 5000')->fetchAll();
        return array_map(fn($r) => [
            'id' => $r['code'] !== '' && $r['code'] !== null ? $r['code'] : 's' . $r['id'],
            'name' => $r['name'], 'code' => (string)$r['code'], 'title' => '',
        ], $all);
    });
}
