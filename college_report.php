<?php
/**
 * College report: every papersetter entered for ONE college, in a single table.
 * A user sees their own college (set by the administrator on the Users page).
 * Administrators can pick any college:  college_report.php?college=503
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/data_source.php';
$me = require_login();
date_default_timezone_set('Asia/Kolkata');
$pdo = db();
$isAdmin = $me['role'] === 'admin';
$mine = (string)($me['college_code'] ?? '');
$code = $isAdmin ? trim($_GET['college'] ?? $mine) : $mine;       // ordinary users can only see their own college
$session = $_GET['session'] ?? '';
$colleges = $isAdmin ? (src_colleges()['items'] ?? []) : [];
$names = $pdo->query("SELECT DISTINCT exam_session FROM papers WHERE exam_session <> '' ORDER BY exam_session")->fetchAll(PDO::FETCH_COLUMN);

$rows = []; $collegeName = '';
if ($code !== '') {
    // codes are stored as typed or from the catalogue ('0196' vs '196'), so compare normalised
    $norm = fn($c) => ctype_digit(trim((string)$c)) ? (ltrim(trim((string)$c), '0') ?: '0') : trim((string)$c);
    $want = $norm($code);
    $sql = "SELECT p.programme_name, p.programme_code, p.paper_code, p.subject_name, p.course_name_dev, p.exam_session,
                   s.name, s.refused, s.college_code, s.college_name, s.appointed_as, s.email, s.mobile
            FROM papersetters s JOIN papers p ON p.id = s.paper_id"
         . ($session !== '' ? ' WHERE p.exam_session = ?' : '')
         . ' ORDER BY p.programme_name, p.subject_name, s.name';
    $st = $pdo->prepare($sql); $st->execute($session !== '' ? [$session] : []);
    foreach ($st->fetchAll() as $r) if ($norm($r['college_code']) === $want) { $rows[] = $r; $collegeName = $collegeName ?: $r['college_name']; }
    if ($collegeName === '') $collegeName = (string)($me['college_name'] ?? '');
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>College Report - <?= h($code) ?></title>
<link rel="stylesheet" href="assets/tablereport.css"></head>
<body<?= !empty($_GET['print']) ? ' data-autoprint="1"' : '' ?>>
<div class="toolbar">
  <button onclick="window.print()">&#11015; Download PDF</button>
  <a href="<?= $isAdmin ? 'dashboard.php' : 'index.php' ?>">&larr; Back</a>
  <form method="get" style="margin:0;display:flex;gap:8px">
    <?php if ($isAdmin): ?><input name="college" list="cc" value="<?= h($code) ?>" placeholder="college code" size="8" autocomplete="off"><datalist id="cc"><?php foreach ($colleges as $c): ?><option value="<?= h($c['code']) ?>"><?= h($c['name']) ?></option><?php endforeach; ?></datalist><?php endif; ?>
    <select name="session" onchange="this.form.submit()"><option value="">All sessions</option><?php foreach ($names as $n): ?><option <?= $n === $session ? 'selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
    <?php if ($isAdmin): ?><button>Show</button><?php endif; ?>
  </form>
  <span><?= count($rows) ?> row(s)</span>
</div>
<?php if ($code === ''): ?>
  <p class="empty"><?= $isAdmin ? 'Enter a college code above.' : 'No college is set for your account. Ask the administrator to set it on the Users page.' ?></p>
<?php else: ?>
<div class="page">
  <div class="top"><span>University of Mumbai</span><span>Confidential</span></div>
  <h1>College Report &mdash; <?= h(trim($code . ' - ' . $collegeName, ' -')) ?></h1>
  <p class="sub"><?= $session !== '' ? h($session) : 'All exam sessions' ?> &middot; Generated on <?= date('d M, Y H:i') ?></p>
  <table>
    <thead><tr><th class="n">Sr</th><th>Programme</th><th>Paper code</th><th>Subject</th><th>Session</th><th class="nm">Name</th><th>Appointed as</th><th class="m">Mobile</th><th class="e">Email</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="9" class="msg">No papersetters entered for this college yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $i => $r): ?>
      <tr><td class="n"><?= $i + 1 ?></td><td><?= h($r['programme_name']) ?></td><td><?= h($r['paper_code']) ?></td>
        <td><?= h($r['subject_name']) ?><?php if (!empty($r['course_name_dev'])): ?> <span class="dev" lang="mr">/ <?= h($r['course_name_dev']) ?></span><?php endif; ?></td>
        <td><?= h($r['exam_session']) ?></td><td><?= !empty($r['refused']) ? '<s>' . h($r['name']) . '</s> <b class="ref">(Refused)</b>' : h($r['name']) ?></td><td><?= h($r['appointed_as']) ?></td><td><?= h($r['mobile']) ?></td><td><?= h($r['email']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<script>if (document.body.dataset.autoprint) window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body></html>
