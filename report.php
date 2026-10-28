<?php
/**
 * "Recommendation Report" for everyone who is logged in: ONE PAGE PER PAPER in the university's layout,
 * programme-wise, then subject-wise. Fixed wording is exactly as on the printed report; entries fill in the rest.
 *   report.php                      -> all records
 *   report.php?fp=<programme id>    -> one programme   (&fs=<subject id> -> one subject)
 *   report.php?print=1              -> opens the browser's print dialog straight away: choose "Save as PDF"
 * The PDF is produced by the browser, which is what makes Devanagari text come out correctly.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();
date_default_timezone_set('Asia/Kolkata');

$cfg = (cfg()['report'] ?? []) + ['faculty_codes' => [], 'next_session' => ''];
$cfg['faculty_codes'] = $cfg['faculty_codes'] + [          // e-Samarth faculty name => code printed on the report (add more in config.php)
    'Faculty of Science' => 'SCI_TECH', 'Faculty of Science and Technology' => 'SCI_TECH', 'Faculty of Humanities' => 'HUM',
    'Faculty of Commerce and Management' => 'FCM', 'Faculty of Inter Disciplinary Studies' => 'FIDS',
];

$pdo = db();
$fp = $_GET['fp'] ?? '';
$fs = $_GET['fs'] ?? '';
$where = 'WHERE 1=1'; $args = [];
if ($fp !== '') { $where .= ' AND programme_id = ?'; $args[] = $fp; }
if ($fs !== '') { $where .= ' AND subject_id = ?';   $args[] = $fs; }
$st = $pdo->prepare("SELECT * FROM papers $where ORDER BY programme_name, programme_id, subject_name, subject_id, id");
$st->execute($args);
$papers = $st->fetchAll();
$setStmt = $pdo->prepare('SELECT * FROM papersetters WHERE paper_id = ? ORDER BY sr_no');

/** "For 2024 Second Half" -> "2024 Second Half" so that "Appointed for ..." does not say "for For" */
function session_label(string $s): string { return trim(preg_replace('/^for\s+/i', '', trim($s))); }

/** The session after the given one: FIRST HALF (SUMMER) 2026 -> SECOND HALF (WINTER) 2026 -> FIRST HALF (SUMMER) 2027 */
function next_session(string $s, string $override): string {
    if ($override !== '') return $override;
    if (preg_match('/^FIRST HALF \(SUMMER\)\s+(\d{4})$/i', $s, $m))  return 'SECOND HALF (WINTER) ' . $m[1];
    if (preg_match('/^SECOND HALF \(WINTER\)\s+(\d{4})$/i', $s, $m)) return 'FIRST HALF (SUMMER) ' . ($m[1] + 1);
    return '';
}
$now = date('d M, Y H:i:s');                                 // same style as "22 Aug, 2026 12:13:33"

/* one sheet per SUBJECT: papers entered more than once for the same subject share one page and one table */
$subjects = [];
foreach ($papers as $p) {
    $k = $p['programme_id'] . "\0" . $p['subject_id'];
    if (!isset($subjects[$k])) $subjects[$k] = ['p' => $p, 'rows' => [], 'nostud' => true, 'remarks' => []];
    if (!$p['no_students']) $subjects[$k]['nostud'] = false;
    $setStmt->execute([$p['id']]);
    foreach ($setStmt->fetchAll() as $r) $subjects[$k]['rows'][] = $r;
    if (trim((string)$p['remark']) !== '') $subjects[$k]['remarks'][] = $p['remark'];
    if (empty($subjects[$k]['p']['course_name_dev']) && !empty($p['course_name_dev'])) $subjects[$k]['p']['course_name_dev'] = $p['course_name_dev'];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recommendation Report - <?= date('Y-m-d') ?></title>
<link rel="stylesheet" href="assets/report.css">
</head>
<body<?= !empty($_GET['print']) ? ' data-autoprint="1"' : '' ?>>

<div class="toolbar">
  <button onclick="window.print()">&#11015; Download PDF</button>
  <a href="index.php">&larr; Back to register</a>
  <span>In the print window choose <b>Save as PDF</b> as the destination. <?= count($subjects) ?> page(s), one per subject.</span>
</div>

<?php if (!$subjects): ?><p class="empty">No records to show.</p><?php endif; ?>

<?php foreach ($subjects as $sj):
    $p = $sj['p']; $rows = $sj['rows'];
    $sess = session_label((string)$p['exam_session']);
    $next = next_session($sess, (string)$cfg['next_session']);
    $fac  = $cfg['faculty_codes'][$p['faculty']] ?? $p['faculty']; ?>
<section class="sheet">
  <div class="top"><span><?= h($fac) ?></span><span>Confidential</span></div>
  <h1>Recommendation Report</h1>

  <table class="box">
    <tr><td class="k"><?= h($p['programme_code']) ?></td><td><?= h($p['programme_name']) ?></td></tr>
    <tr><td class="k"><?= h($p['paper_code']) ?></td><td><?= h($p['subject_name']) ?><?php if (!empty($p['course_name_dev'])): ?>
        <span class="dev" lang="mr"> / <?= h($p['course_name_dev']) ?></span><?php endif; ?></td></tr>
    <tr><td class="k">Remark</td><td><?= h(implode('; ', $sj['remarks'])) ?></td></tr>
  </table>

  <h2>Appointed for <?= h($sess) ?></h2>
  <table class="appt">
    <?php if ($sj['nostud']): ?>
      <tr><td colspan="2" class="msg">No students opted for this course.</td></tr>
      <tr><td colspan="2" class="disc"><b>Disclaimer:</b> No students have opted for this course. If any student appears for it, the Examiners' panel<?= $rows ? ' named below' : '' ?> will take care of the paper setting.</td></tr>
    <?php endif; ?>
    <?php if (!$rows): ?>
      <?php if (!$sj['nostud']): ?><tr><td colspan="2" class="msg">No paper setters entered.</td></tr><?php endif; ?>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td class="who"><?= !empty($r['refused']) ? '<s>' . h($r['name']) . '</s> <b class="ref">(Refused)</b>' : h($r['name']) ?><?= $r['appointed_as'] !== '' ? ' (' . h($r['appointed_as']) . ')' : '' ?><br>
          <?= h(trim($r['college_code'] . ' - ' . $r['college_name'], ' -')) ?><?= !empty($r['address']) ? ' ' . h($r['address']) : '' ?></td>
        <td class="contact"><?php if ($r['mobile'] !== ''): ?>M- <?= h($r['mobile']) ?><br><?php endif; ?><?php if ($r['email'] !== ''): ?>Email-<br><?= h($r['email']) ?><?php endif; ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </table>

  <h2>Suggest Names<?= $next !== '' ? ' for ' . h($next) : '' ?></h2>
  <table class="suggest">
    <thead><tr><th class="s-role">Appoint as</th><th class="s-name">Name of Teacher</th><th class="s-col">College</th><th class="s-mob">Mobile Number</th><th class="s-mail">Email Id</th></tr></thead>
    <tbody><?php for ($i = 0; $i < 5; $i++): ?><tr><td></td><td></td><td></td><td></td><td></td></tr><?php endfor; ?></tbody>
  </table>

  <div class="foot">Generated on: <?= h($p['generated_on'] !== '' && $p['generated_on'] !== null ? $p['generated_on'] : $now) ?></div>
</section>
<?php endforeach; ?>

<script>
  if (document.body.dataset.autoprint) window.addEventListener('load', () => setTimeout(() => window.print(), 300));
</script>
</body>
</html>
