<?php
/**
 * Setup check: open http://localhost/esamarth_papersetter/test.php
 * Shows exactly what works and what does not. Delete this file when everything works.
 */
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/esamarth.php';
require_once __DIR__ . '/excel_source.php';
header('Content-Type: text/html; charset=utf-8');
$cfg = cfg();
$a = $cfg['api'];
function ok($t)  { return '<b class="ok">' . h($t) . '</b>'; }
function bad($t) { return '<b class="bad">' . h($t) . '</b>'; }
?>
<!doctype html><meta charset="utf-8"><title>Setup check</title>
<style>body{font:14px/1.5 system-ui,sans-serif;max-width:900px;margin:20px auto;padding:0 12px}
.ok{color:#1e8e5a}.bad{color:#c0392b}pre{background:#f4f5f7;padding:10px;overflow:auto;border-radius:6px}</style>
<h2>Setup check</h2>
<p>Data source in config.php: <b><?= h($cfg['source']) ?></b></p>
<p>MySQL: <?php try { db(); echo ok('connected'); } catch (Throwable $e) { echo bad('failed - ' . $e->getMessage()); } ?></p>

<h3>Database tables</h3>
<?php
try {
    require_once __DIR__ . '/db_source.php';
    $cnt = db_counts();
    echo '<p>' . implode(' &middot; ', array_map(fn($t, $n) => h($t) . ': ' . ($n > 0 ? ok($n) : '<b>0</b>'), array_keys($cnt), $cnt)) . '</p>';
    if (array_sum($cnt) === 0) echo '<p>Tables are empty. Run <a href="import_excel.php">import_excel.php</a> (from the Excel files) or <a href="sync_api.php">sync_api.php</a> (from the API).</p>';
} catch (Throwable $e) { echo '<p>' . bad($e->getMessage()) . '</p>'; }
?>

<h3>Excel files</h3>
<p>PHP zip extension: <?= class_exists('ZipArchive') ? ok('enabled') : bad('MISSING - enable "extension=zip" in php.ini and restart Apache') ?></p>
<?php
foreach (['Colleges' => 'excel_colleges', 'Programmes (from the catalogue)' => 'excel_programmes'] as $label => $fn) {
    echo '<p><b>' . h($label) . '</b> &mdash; <code>' . h(excel_path('catalogue')) . '</code><br>';
    $r = $fn();
    if ($r['error']) { echo bad($r['error']) . '</p>'; continue; }
    echo ok(count($r['items']) . ' rows read') . '</p><pre>' . h(json_encode(array_slice($r['items'], 0, 3), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
}
?>

<?php if ($cfg['source'] === 'api'): ?>
<h3>e-Samarth API</h3>
<p>Base URL: <code><?= h($a['base_url']) ?></code> &middot; Username: <code><?= h($a['username']) ?></code></p>
<p>cURL extension: <?= function_exists('curl_init') ? ok('enabled') : bad('DISABLED (enable extension=curl in php.ini, restart Apache)') ?></p>
<?php
foreach (['Programmes' => $a['ep_programmes'], 'Courses' => $a['ep_courses']] as $label => $path) {
    echo '<h4>' . h($label) . ' <small>(' . h($path) . ')</small></h4>';
    try {
        $url = rtrim($a['base_url'], '/') . $path . '?page=1';
        $r = esamarth_http($url);
        echo '<p>GET <code>' . h($url) . '</code> &rarr; HTTP ' . ($r['status'] === 200 ? ok($r['status']) : bad($r['status'])) . '</p>';
        if ($r['status'] !== 200) { echo '<p class="bad">' . h(esamarth_fail($r, $url)->getMessage()) . '</p><pre>' . h(substr($r['body'], 0, 600)) . '</pre>'; continue; }
        $json = json_decode($r['body'], true);
        if (!is_array($json)) { echo '<p class="bad">Response is not JSON:</p><pre>' . h(substr($r['body'], 0, 600)) . '</pre>'; continue; }
        echo '<p>' . ok(count($json) . ' rows on page 1') . ' &middot; total ' . h($r['headers']['x-pagination-total-count'] ?? '?') . ', pages ' . h($r['headers']['x-pagination-page-count'] ?? '?') . '</p>';
        echo '<pre>' . h(json_encode($json[0] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
    } catch (Throwable $e) {
        echo '<p class="bad">' . h($e->getMessage()) . '</p>';
    }
}
endif; ?>
