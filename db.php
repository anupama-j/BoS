<?php
/** MySQL connection (XAMPP) + auto-create database and tables from schema.sql. */

function cfg(): array {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $d = cfg()['db'];

    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    $port = isset($d['port']) ? ';port=' . (int)$d['port'] : '';
    $name = str_replace('`', '', $d['name']);
    try {
        // Shared hosting (InfinityFree etc.): the database already exists and CREATE DATABASE is not allowed
        $pdo = new PDO("mysql:host={$d['host']}$port;dbname=$name;charset=utf8mb4", $d['user'], $d['pass'], $opts);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1049) {         // 1049 = unknown database -> only then try to create it (XAMPP)
            http_response_code(500);
            exit('<h3>Database connection failed</h3><p>' . htmlspecialchars($e->getMessage()) .
                 '</p><p>Check the <code>db</code> section of config.php: host, database name, user and password.</p>');
        }
        $pdo = new PDO("mysql:host={$d['host']}$port;charset=utf8mb4", $d['user'], $d['pass'], $opts);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$name`");
    }

    // Tables + upgrades run ONCE per database and schema version (a marker file remembers it), so a remote database
    // (freesqldatabase.com etc.) is not asked a dozen schema questions on every page view.
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if ($sql === false) throw new RuntimeException('schema.sql not found next to db.php');
    $markDir = __DIR__ . '/data/cache';
    $marker  = "$markDir/schema_" . md5($sql . '|v6|' . $d['host'] . '|' . $name) . '.ok';
    if (!is_file($marker)) {
        db_upgrade($pdo, $sql);
        if (!is_dir($markDir)) @mkdir($markDir, 0775, true);
        foreach (glob("$markDir/schema_*.ok") ?: [] as $old) @unlink($old);
        @file_put_contents($marker, date('c'));
    }
    return $pdo;
}

/** CREATE the tables and add any column an older database is missing (safe to repeat). */
function db_upgrade(PDO $pdo, string $sql): void {
    $sql = preg_replace('/--[^\n]*/', '', $sql);                 // strip comments
    foreach (explode(';', $sql) as $stmt) {
        if (trim($stmt) !== '') $pdo->exec($stmt);
    }
    $add = [   // table => [column => definition]
        'programmes'   => ['faculty' => 'VARCHAR(100) NULL'],
        'papersetters' => ['address' => 'VARCHAR(500) NULL', 'refused' => 'TINYINT(1) NOT NULL DEFAULT 0'],
        'papers'       => ['faculty' => 'VARCHAR(100) NULL', 'paper_title' => 'VARCHAR(255) NULL', 'remark' => 'VARCHAR(255) NULL',
                           'generated_on' => 'VARCHAR(50) NULL', 'course_name_dev' => 'VARCHAR(255) NULL',
                           'no_students' => 'TINYINT(1) NOT NULL DEFAULT 0', 'created_by' => 'INT NULL'],
        'users'        => ['college_code' => 'VARCHAR(20) NULL', 'college_name' => 'VARCHAR(255) NULL'],
    ];
    foreach ($add as $tbl => $cols) {
        try {
            $have = array_map('strtolower', array_column($pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(), 'Field'));
            foreach ($cols as $col => $def) if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
        } catch (Throwable $e) { /* table missing or not alterable: the page will report the real problem */ }
    }
    // Faculty names such as "Faculty of Science and Technology" need more than the old 30/50 characters
    foreach (['programmes', 'papers'] as $tbl) {
        try {
            $c = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'faculty'")->fetch();
            if ($c && preg_match('/varchar\((\d+)\)/i', $c['Type'], $m) && (int)$m[1] < 100) $pdo->exec("ALTER TABLE `$tbl` MODIFY faculty VARCHAR(100) NULL");
        } catch (Throwable $e) {}
    }
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
