<?php
/**
 * Pull reference data from the e-Samarth Student Data API into the MySQL tables.
 * Needs the 'api' section of config.php filled in (base_url, username, password).
 *   Browser: http://localhost/esamarth_papersetter/sync_api.php   (press the button)
 *   CLI / scheduled task:  php sync_api.php
 *
 *   /programme/index -> programmes    (id -> external_id)
 *   /course/index    -> subjects      (id -> external_id)
 *   /ou/index        -> colleges      (code -> code, name -> name)  *check: see note in config.php*
 *
 * The API has no programme -> course link, so programme_subjects is NOT touched here.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/esamarth.php';

function sync_api(PDO $pdo): array {
    $a = cfg()['api'];
    $log = [];
    $now = date('Y-m-d H:i:s');
    $note = function (string $entity, int $n, string $status, ?string $msg = null) use ($pdo, &$log) {
        $pdo->prepare('INSERT INTO sync_log (source, entity, rows_seen, status, message) VALUES (\'api\',?,?,?,?)')
            ->execute([$entity, $n, $status, $msg ? substr($msg, 0, 500) : null]);
        $log[] = "$entity: " . ($status === 'ok' ? "$n rows synced" : "FAILED - $msg");
    };

    // programmes
    try {
        $rows = esamarth_fetch_all($a['ep_programmes']);
        $st = $pdo->prepare('INSERT INTO programmes (external_id, code, name, level, specialization, source, is_active, synced_at)
                             VALUES (?,?,?,?,?, \'api\', ?, ?)
                             ON DUPLICATE KEY UPDATE code = VALUES(code), name = VALUES(name), level = VALUES(level),
                                 specialization = VALUES(specialization), is_active = VALUES(is_active), synced_at = VALUES(synced_at)');
        $pdo->beginTransaction();
        foreach ($rows as $r) {
            $name = $r['title_english'] ?? $r['code'] ?? '';
            if (!isset($r['id']) || $name === '') continue;
            $st->execute([$r['id'], $r['code'] ?? null, $name, $r['level'] ?? null, $r['specialization'] ?? null,
                          (($r['status_'] ?? 'ACTIVE') === 'ACTIVE') ? 1 : 0, $now]);
        }
        $pdo->commit();
        $note('programmes', count($rows), 'ok');
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $note('programmes', 0, 'error', $e->getMessage()); }

    // subjects (courses)
    try {
        $rows = esamarth_fetch_all($a['ep_courses']);
        $st = $pdo->prepare('INSERT INTO subjects (external_id, code, name, category, source, is_active, synced_at)
                             VALUES (?,?,?,?, \'api\', ?, ?)
                             ON DUPLICATE KEY UPDATE code = VALUES(code), name = VALUES(name), category = VALUES(category),
                                 is_active = VALUES(is_active), synced_at = VALUES(synced_at)');
        $pdo->beginTransaction();
        foreach ($rows as $r) {
            if (!isset($r['id']) || trim($r['title'] ?? '') === '') continue;
            $st->execute([$r['id'], $r['code'] ?? null, trim($r['title']), $r['category'] ?? null,
                          (($r['status'] ?? 'PUBLISHED') === 'PUBLISHED') ? 1 : 0, $now]);
        }
        $pdo->commit();
        $note('subjects', count($rows), 'ok');
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $note('subjects', 0, 'error', $e->getMessage()); }

    // colleges (organizational units)
    if (!empty($a['ep_ou'])) {
        try {
            $rows = esamarth_fetch_all($a['ep_ou']);
            $st = $pdo->prepare('INSERT INTO colleges (external_id, code, name, source, is_active, synced_at)
                                 VALUES (?,?,?, \'api\', 1, ?)
                                 ON DUPLICATE KEY UPDATE code = VALUES(code), name = VALUES(name), synced_at = VALUES(synced_at)');
            $pdo->beginTransaction();
            $n = 0;
            foreach ($rows as $r) {
                if (!isset($r['id']) || trim($r['name'] ?? '') === '') continue;
                $code = (string)($r[$a['ou_code_field'] ?? 'code'] ?? '');
                if ($code === '') continue;
                $st->execute([$r['id'], $code, trim($r['name']), $now]); $n++;
            }
            $pdo->commit();
            $note('colleges', $n, 'ok');
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $note('colleges', 0, 'error', $e->getMessage()); }
    }
    return $log;
}

/* ---------------- run ---------------- */
if (PHP_SAPI === 'cli') {
    echo implode("\n", sync_api(db())), "\n";
    exit;
}
auth_session();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $result = sync_api(db());
}
$last = db()->query('SELECT entity, rows_seen, status, message, created_at FROM sync_log WHERE source = \'api\' ORDER BY id DESC LIMIT 6')->fetchAll();
?>
<!doctype html><meta charset="utf-8"><title>Sync from e-Samarth</title>
<style>body{font:14px/1.5 system-ui,sans-serif;max-width:700px;margin:20px auto;padding:0 12px}
button{padding:8px 14px;border:0;border-radius:6px;background:#2d5be3;color:#fff;font:inherit;cursor:pointer}
pre{background:#f4f5f7;padding:10px;border-radius:6px}table{border-collapse:collapse}td,th{border:1px solid #dde1e9;padding:3px 8px}</style>
<h2>Sync from e-Samarth API &rarr; MySQL</h2>
<p>Base URL: <code><?= h(cfg()['api']['base_url']) ?></code> &middot; user <code><?= h(cfg()['api']['username']) ?></code></p>
<?php if ($result): ?><pre><?= h(implode("\n", $result)) ?></pre><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><button>Sync now</button></form>
<?php if ($last): ?><h3>Recent syncs</h3><table><tr><th>When</th><th>Entity</th><th>Rows</th><th>Status</th><th>Message</th></tr>
<?php foreach ($last as $l): ?><tr><td><?= h($l['created_at']) ?></td><td><?= h($l['entity']) ?></td><td><?= (int)$l['rows_seen'] ?></td><td><?= h($l['status']) ?></td><td><?= h($l['message']) ?></td></tr><?php endforeach; ?></table><?php endif; ?>
