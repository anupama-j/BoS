<?php
/**
 * Load the catalogue file(s) in data/catalogue/ (e.g. BSC_IT.xlsx) and data/exam_sessions.xlsx into the MySQL tables.
 * Safe to run repeatedly (updates existing rows, adds new ones).
 *   Browser: http://localhost/esamarth_papersetter/import_excel.php   (press the button)
 *   CLI:     php import_excel.php
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/excel_source.php';

function import_excel(PDO $pdo): array {
    $log = [];
    $note = function (string $entity, int $n, string $status = 'ok', ?string $msg = null) use ($pdo, &$log) {
        $pdo->prepare('INSERT INTO sync_log (source, entity, rows_seen, status, message) VALUES (\'excel\',?,?,?,?)')
            ->execute([$entity, $n, $status, $msg]);
        $log[] = "$entity: $n rows $status" . ($msg ? " ($msg)" : '');
    };

    $pdo->beginTransaction();
    try {
        $cat = catalogue();                       // parsed + de-duplicated (72k rows -> programmes, papers, colleges)

        // ---- colleges ----
        $n = 0;
        $up = $pdo->prepare('INSERT INTO colleges (code, name, source, synced_at) VALUES (?,?, \'excel\', NOW())
                             ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1, synced_at = NOW()');
        foreach ($cat['colleges'] as $code => $name) { $up->execute([(string)$code, $name]); $n++; }
        $note('colleges', $n);

        // ---- programmes / subjects / links ----
        $findP = $pdo->prepare('SELECT id FROM programmes WHERE name = ? AND external_id IS NULL LIMIT 1');
        $insP  = $pdo->prepare('INSERT INTO programmes (name, code, faculty, source, synced_at) VALUES (?,?,?, \'excel\', NOW())');
        $updP  = $pdo->prepare('UPDATE programmes SET code = COALESCE(NULLIF(?, \'\'), code), faculty = COALESCE(NULLIF(?, \'\'), faculty),
                                is_active = 1, synced_at = NOW() WHERE id = ?');
        $findS = $pdo->prepare('SELECT id FROM subjects WHERE code = ? AND name = ? AND external_id IS NULL LIMIT 1');
        $insS  = $pdo->prepare('INSERT INTO subjects (code, name, source, synced_at) VALUES (?,?, \'excel\', NOW())');
        $link  = $pdo->prepare('INSERT INTO programme_subjects (programme_id, subject_id, paper_code, paper_title) VALUES (?,?,?,?)
                                ON DUPLICATE KEY UPDATE paper_title = VALUES(paper_title)');
        $np = 0; $ns = []; $nl = 0;
        foreach ($cat['programmes'] as $p) {
            $findP->execute([$p['name']]);
            $pid = $findP->fetchColumn();
            if (!$pid) { $insP->execute([$p['name'], $p['code'] ?: null, $p['faculty'] ?: null]); $pid = $pdo->lastInsertId(); }
            else       { $updP->execute([$p['code'], $p['faculty'], $pid]); }
            $np++;
            foreach ($cat['subjects'][$p['id']] ?? [] as $s) {
                $findS->execute([$s['code'], $s['name']]);
                $sid = $findS->fetchColumn();
                if (!$sid) { $insS->execute([$s['code'], $s['name']]); $sid = $pdo->lastInsertId(); }
                $link->execute([$pid, $sid, $s['code'], $s['title']]);
                $ns[$sid] = 1; $nl++;
            }
        }
        $note('programmes', $np);
        $note('subjects', count($ns));
        $note('programme_subjects', $nl);

        // ---- exam sessions ----
        $n = 0;
        $upS = $pdo->prepare('INSERT INTO exam_sessions (name, is_current) VALUES (?,?) ON DUPLICATE KEY UPDATE is_current = VALUES(is_current)');
        foreach (excel_sessions()['items'] as $s) { $upS->execute([$s['name'], $s['current'] ? 1 : 0]); $n++; }
        $note('exam_sessions', $n);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $log[] = 'FAILED: ' . $e->getMessage();
    }
    return $log;
}

/* ---------------- run ---------------- */
if (PHP_SAPI === 'cli') {
    echo implode("\n", import_excel(db())), "\n";
    exit;
}
$_SESSION['csrf'] = auth_csrf();
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $result = import_excel(db());
}
?>
<!doctype html><meta charset="utf-8"><title>Import Excel to MySQL</title>
<style>body{font:14px/1.5 system-ui,sans-serif;max-width:700px;margin:20px auto;padding:0 12px}
button{padding:8px 14px;border:0;border-radius:6px;background:#2d5be3;color:#fff;font:inherit;cursor:pointer}
pre{background:#f4f5f7;padding:10px;border-radius:6px}</style>
<h2>Import Excel &rarr; MySQL</h2>
<p>Loads <code><?= h(excel_path('catalogue')) ?></code> into the
<code>colleges</code>, <code>programmes</code>, <code>subjects</code> and <code>programme_subjects</code> tables. Safe to run again.</p>
<?php if ($result): ?><pre><?= h(implode("\n", $result)) ?></pre>
<p>Now set <code>'source' =&gt; 'db'</code> in config.php to use the tables. <a href="index.php">Open the register</a> &middot; <a href="test.php">Check</a></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><button>Import now</button></form>
