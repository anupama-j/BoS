<?php
/**
 * Admin report: ONE table, programme-wise then subject-wise, every papersetter entered.
 *   admin_report.php?session=<name>   (optional filter)   &print=1 opens the print dialog (Save as PDF)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
$me = require_admin();
date_default_timezone_set('Asia/Kolkata');
$pdo = db();
$session = $_GET['session'] ?? '';
$names = $pdo->query("SELECT DISTINCT exam_session FROM papers WHERE exam_session <> '' ORDER BY exam_session")->fetchAll(PDO::FETCH_COLUMN);

$sql = "SELECT p.id pid, p.programme_id, p.programme_name, p.programme_code, p.subject_id, p.subject_name, p.paper_code,
               p.course_name_dev, p.no_students, p.exam_session, u.name AS entered_by,
               s.sr_no, s.name, s.refused, s.college_code, s.college_name, s.appointed_as, s.email, s.mobile
        FROM papers p LEFT JOIN papersetters s ON s.paper_id = p.id LEFT JOIN users u ON u.id = p.created_by"
     . ($session !== '' ? ' WHERE p.exam_session = ?' : '')
     . ' ORDER BY p.programme_name, p.programme_id, p.subject_name, p.subject_id, p.id, s.sr_no';
$st = $pdo->prepare($sql); $st->execute($session !== '' ? [$session] : []);
$rows = $st->fetchAll();

$tree = [];   // programme => subject => paper => rows
foreach ($rows as $r) {
    $tree[$r['programme_id']]['p'] = $r;
    $tree[$r['programme_id']]['s'][$r['subject_id']]['h'] = $r;
    $tree[$r['programme_id']]['s'][$r['subject_id']]['r'][] = $r;
}
$count = 0; foreach ($rows as $r) if ($r['name'] !== null) $count++;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Programme-wise Subject-wise Report - <?= date('Y-m-d') ?></title>
<link rel="stylesheet" href="assets/tablereport.css"></head>
<body<?= !empty($_GET['print']) ? ' data-autoprint="1"' : '' ?>>
<div class="toolbar">
  <button onclick="window.print()">&#11015; Download PDF</button>
  <a href="dashboard.php">&larr; Dashboard</a>
  <form method="get" style="margin:0"><select name="session" onchange="this.form.submit()">
    <option value="">All sessions</option>
    <?php foreach ($names as $n): ?><option <?= $n === $session ? 'selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?>
  </select></form>
  <span><?= $count ?> papersetter(s). In the print window choose <b>Save as PDF</b>.</span>
</div>
<?php if (!$tree): ?><p class="empty">No records to show.</p><?php else: ?>
<div class="page">
  <div class="top"><span>University of Mumbai</span><span>Confidential</span></div>
  <h1>Papersetters &mdash; Programme-wise, Subject-wise</h1>
  <p class="sub"><?= $session !== '' ? h($session) : 'All exam sessions' ?> &middot; Generated on <?= date('d M, Y H:i') ?></p>
  <table>
    <thead><tr><th class="n">Sr</th><th class="nm">Name</th><th>Appointed as</th><th>College</th><th class="m">Mobile</th><th class="e">Email</th><th class="m">Entered by</th></tr></thead>
    <tbody>
    <?php foreach ($tree as $prog): $p = $prog['p']; ?>
      <tr class="prog"><td colspan="7"><?= h($p['programme_name']) ?><?= $p['programme_code'] !== '' && strpos($p['programme_name'], '[' . $p['programme_code'] . ']') === false ? ' [' . h($p['programme_code']) . ']' : '' ?></td></tr>
      <?php foreach ($prog['s'] as $sub): $h0 = $sub['h']; ?>
        <tr class="subj"><td colspan="7"><?= h($h0['paper_code']) ?> &ndash; <?= h($h0['subject_name']) ?><?php if (!empty($h0['course_name_dev'])): ?> <span class="dev" lang="mr">/ <?= h($h0['course_name_dev']) ?></span><?php endif; ?></td></tr>
        <?php $seen = []; foreach ($sub['r'] as $r): ?>
          <?php if ($r['no_students'] && empty($seen[$r['pid']])): $seen[$r['pid']] = 1; ?><tr><td colspan="7" class="msg">No students opted for this course. If any student appears, the Examiners' panel will take care of the paper setting.</td></tr><?php endif; ?>
          <?php if ($r['name'] === null): if (!$r['no_students']): ?><tr><td colspan="7" class="msg">No paper setters entered.</td></tr><?php endif; ?>
          <?php else: ?><tr><td class="n"><?= (int)$r['sr_no'] ?></td><td><?= !empty($r['refused']) ? '<s>' . h($r['name']) . '</s> <b class="ref">(Refused)</b>' : h($r['name']) ?></td><td><?= h($r['appointed_as']) ?></td>
            <td><?= h(trim($r['college_code'] . ' - ' . $r['college_name'], ' -')) ?></td><td><?= h($r['mobile']) ?></td><td><?= h($r['email']) ?></td><td><?= h($r['entered_by'] ?? '') ?></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<script>if (document.body.dataset.autoprint) window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body></html>
