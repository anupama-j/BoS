<?php
/** MySQL connection & auto-schema setup */

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
        $pdo = new PDO("mysql:host={$d['host']}$port;dbname=$name;charset=utf8mb4", $d['user'], $d['pass'], $opts);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1049) {
            http_response_code(500);
            exit('<h3>Database connection failed</h3><p>' . htmlspecialchars($e->getMessage()) .
                 '</p><p>Check the <code>db</code> section of config.php: host, database name, user and password.</p>');
        }
        $pdo = new PDO("mysql:host={$d['host']}$port;charset=utf8mb4", $d['user'], $d['pass'], $opts);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$name`");
    }

    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if ($sql === false) throw new RuntimeException('schema.sql not found next to db.php');
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    foreach (explode(';', $sql) as $stmt) {
        if (trim($stmt) !== '') $pdo->exec($stmt);
    }

    // Dynamic schema updates
    if (!$pdo->query("SHOW COLUMNS FROM programmes LIKE 'faculty'")->fetch()) {
        $pdo->exec("ALTER TABLE programmes ADD COLUMN faculty VARCHAR(100) NULL AFTER name");
    }
    if (!$pdo->query("SHOW COLUMNS FROM papersetters LIKE 'address'")->fetch()) {
        $pdo->exec("ALTER TABLE papersetters ADD COLUMN address VARCHAR(500) NULL AFTER college_name");
    }
    if (!$pdo->query("SHOW COLUMNS FROM papers LIKE 'course_name_dev'")->fetch()) {
        $pdo->exec("ALTER TABLE papers ADD COLUMN course_name_dev VARCHAR(255) NULL AFTER subject_id");
    }
    if (!$pdo->query("SHOW COLUMNS FROM papers LIKE 'no_students'")->fetch()) {
        $pdo->exec("ALTER TABLE papers ADD COLUMN no_students TINYINT(1) NOT NULL DEFAULT 0 AFTER course_name_dev");
    }
    if (!$pdo->query("SHOW COLUMNS FROM papers LIKE 'created_by'")->fetch()) {
        $pdo->exec("ALTER TABLE papers ADD COLUMN created_by INT NULL");
    }
    if (!$pdo->query("SHOW COLUMNS FROM papersetters LIKE 'refused'")->fetch()) {
        $pdo->exec("ALTER TABLE papersetters ADD COLUMN refused TINYINT(1) NOT NULL DEFAULT 0");
    }
    foreach (['college_code' => 'VARCHAR(20)', 'college_name' => 'VARCHAR(255)'] as $col => $type) {
        if (!$pdo->query("SHOW COLUMNS FROM users LIKE '$col'")->fetch()) $pdo->exec("ALTER TABLE users ADD COLUMN $col $type NULL");
    }
    foreach (['programmes' => 30, 'papers' => 50] as $tbl => $old) {
        $c = $pdo->query("SHOW COLUMNS FROM $tbl LIKE 'faculty'")->fetch();
        if ($c && preg_match('/varchar\((\d+)\)/i', $c['Type'], $m) && (int)$m[1] < 100) {
            $pdo->exec("ALTER TABLE $tbl MODIFY faculty VARCHAR(100) NULL");
        }
    }
    return $pdo;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
