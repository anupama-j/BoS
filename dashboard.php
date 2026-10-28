<?php
/**
 * Administrator dashboard: how far the papersetter data collection has got, and who has sent what.
 * Reads the register (papers + papersetters) and compares it with the catalogue (programme x subject) from the data source.
 *   dashboard.php                 -> current exam session
 *   dashboard.php?session=NAME    -> one session        dashboard.php?session= -> all sessions
 */
require_once __DIR__ . '/data_source.php';   // also loads db.php
require_once __DIR__ . '/auth.php';
$me = require_admin();
date_default_timezone_set('Asia/Kolkata');
$pdo = db();
$dbNow = strtotime((string)$pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn()) ?: time();   // the database's own clock: created_at values are compared with this, so time zones cannot skew them

/* ---------------- scope: exam session ---------------- */
$cur = src_current_session();
$session = array_key_exists('session', $_GET) ? (string)$_GET['session'] : (string)($cur ?? '');
$names = $pdo->query("SELECT DISTINCT exam_session FROM papers WHERE exam_session IS NOT NULL AND exam_session <> ''")->fetchAll(PDO::FETCH_COLUMN);
if ($cur !== null && !in_array($cur, $names, true)) $names[] = $cur;
rsort($names);

$w = $session !== '' ? ' WHERE exam_session = ?' : ''; $wa = $session !== '' ? [$session] : [];
$st = $pdo->prepare("SELECT * FROM papers$w ORDER BY id"); $st->execute($wa); $papers = $st->fetchAll();
$st = $pdo->prepare('SELECT s.*, p.created_by FROM papersetters s JOIN papers p ON p.id = s.paper_id' . ($session !== '' ? ' WHERE p.exam_session = ?' : '') . ' ORDER BY s.paper_id, s.sr_no');
$st->execute($wa); $setters = $st->fetchAll();

$setCount = []; foreach ($setters as $s) $setCount[$s['paper_id']] = ($setCount[$s['paper_id']] ?? 0) + 1;
$byKey = []; foreach ($papers as $p) $byKey[$p['programme_id'] . '|' . $p['subject_id']][] = $p;

/* ---------------- expected papers (catalogue) vs recorded ---------------- */
$hasExpected = src_has_structure();          // in API mode "subjects" is the whole course list, so there is no per-programme expectation
$prog = []; $pending = []; $tot = ['done' => 0, 'incomplete' => 0, 'nostud' => 0, 'pending' => 0, 'expected' => 0];
$seen = [];
if ($hasExpected) {
    foreach (src_programmes()['items'] as $pr) {
        $pid = (string)$pr['id'];
        $prog[$pid] = ['id' => $pid, 'name' => $pr['name'], 'code' => $pr['code'] ?? '', 'faculty' => $pr['faculty'] ?? '', 'done' => 0, 'incomplete' => 0, 'nostud' => 0, 'pending' => 0];
        foreach (src_subjects($pid)['items'] as $sub) {
            $key = $pid . '|' . $sub['id']; $seen[$key] = 1;
            $list = $byKey[$key] ?? [];
            $state = 'pending';
            foreach ($list as $p) { if (($setCount[$p['id']] ?? 0) > 0) { $state = 'done'; break; } }
            if ($state === 'pending' && $list) { $state = 'incomplete'; foreach ($list as $p) if ($p['no_students']) { $state = 'nostud'; break; } }
            $prog[$pid][$state]++; $tot[$state]++; $tot['expected']++;
            if ($state === 'pending') $pending[] = ['pid' => $pid, 'sid' => (string)$sub['id'], 'prog' => $pr['name'], 'code' => $sub['code'] ?? '', 'name' => $sub['name']];
        }
    }
    uasort($prog, fn($a, $b) => [$b['pending'], $b['incomplete'], $a['name']] <=> [$a['pending'], $a['incomplete'], $b['name']]);
}
$unmatched = 0; foreach ($byKey as $k => $l) if (!isset($seen[$k])) $unmatched += count($l);
$complete = $tot['done'] + $tot['nostud'];
$pct = $tot['expected'] ? round(100 * $complete / $tot['expected']) : null;

/* ---------------- who has sent data ---------------- */
$users = $pdo->query('SELECT id, name, email, role, is_active, last_login FROM users ORDER BY name')->fetchAll();
$per = [];                                     // created_by (0 = unknown) -> stats
foreach ($papers as $p) {
    $u = (int)($p['created_by'] ?? 0);
    $per[$u]['papers'] = ($per[$u]['papers'] ?? 0) + 1;
    $per[$u]['progs'][$p['programme_id']] = 1;
    if (($per[$u]['last'] ?? '') < $p['created_at']) $per[$u]['last'] = $p['created_at'];
}
foreach ($setters as $s) { $u = (int)($s['created_by'] ?? 0); $per[$u]['setters'] = ($per[$u]['setters'] ?? 0) + 1; }
$rowsU = [];
foreach ($users as $u) {
    $d = $per[(int)$u['id']] ?? [];
    $rowsU[] = ['name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'active' => (int)$u['is_active'], 'login' => $u['last_login'],
                'papers' => $d['papers'] ?? 0, 'setters' => $d['setters'] ?? 0, 'progs' => count($d['progs'] ?? []), 'last' => $d['last'] ?? ''];
}
usort($rowsU, fn($a, $b) => [$b['papers'] > 0, $b['last'], $a['name']] <=> [$a['papers'] > 0, $a['last'], $b['name']]);
$unknown = $per[0] ?? null;
$contributors = count(array_filter($rowsU, fn($r) => $r['papers'] > 0));
$activeUsers  = count(array_filter($rowsU, fn($r) => $r['active']));
$notSent      = count(array_filter($rowsU, fn($r) => $r['papers'] === 0 && $r['active']));
$maxPapers    = max(1, ...array_column($rowsU, 'papers') ?: [1]);

/* ---------------- activity, roles, colleges ---------------- */
$days = []; for ($i = 29; $i >= 0; $i--) $days[date('Y-m-d', $dbNow - $i * 86400)] = 0;
foreach ($papers as $p) { $d = substr((string)$p['created_at'], 0, 10); if (isset($days[$d])) $days[$d]++; }
$roles = []; $colleges = []; $noContact = [];
foreach ($setters as $s) {
    $r = trim((string)$s['appointed_as']) ?: '(not set)'; $roles[$r] = ($roles[$r] ?? 0) + 1;
    $ck = trim($s['college_code'] . ' - ' . $s['college_name'], ' -') ?: '(not set)'; $colleges[$ck] = ($colleges[$ck] ?? 0) + 1;
    $miss = []; if (trim((string)$s['email']) === '') $miss[] = 'email'; if (trim((string)$s['mobile']) === '') $miss[] = 'mobile';
    if ($miss) $noContact[] = ['name' => $s['name'], 'college' => $ck, 'paper_id' => $s['paper_id'], 'miss' => implode(' + ', $miss)];
}
arsort($roles); arsort($colleges); $colleges = array_slice($colleges, 0, 10, true);
$papersById = []; foreach ($papers as $p) $papersById[$p['id']] = $p;
$incomplete = array_values(array_filter($papers, fn($p) => !$p['no_students'] && ($setCount[$p['id']] ?? 0) === 0));
$noStudPapers = count(array_filter($papers, fn($p) => $p['no_students']));

function ago(?string $ts, int $now): string {
    if (!$ts) return '—';
    $d = $now - strtotime($ts); if ($d < 0) $d = 0;
    if ($d < 90) return 'just now'; if ($d < 5400) return round($d / 60) . ' min ago';
    if ($d < 129600) return round($d / 3600) . ' h ago'; return round($d / 86400) . ' d ago';
}
function tip(string $title, array $rows): string { return h(json_encode(['t' => $title, 'r' => $rows], JSON_UNESCAPED_UNICODE)); }
$regLink = fn($pid, $sid = '') => 'index.php?fp=' . urlencode($pid) . ($sid !== '' ? '&fs=' . urlencode($sid) : '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Papersetter Dashboard</title>
<link rel="stylesheet" href="assets/dashboard.css">
</head>
<body>
<header class="bar">
  <strong>Papersetter Dashboard</strong>
  <span class="who"><a href="index.php">Register</a> &middot; <a href="users.php">Users</a> &middot; <?= h($me['name']) ?> &middot; <a href="logout.php">Log out</a></span>
</header>

<main>
  <form class="filters" method="get">
    <label>Exam session
      <select name="session" onchange="this.form.submit()">
        <option value="" <?= $session === '' ? 'selected' : '' ?>>All sessions</option>
        <?php foreach ($names as $n): ?><option value="<?= h($n) ?>" <?= $n === $session ? 'selected' : '' ?>><?= h($n) ?><?= $n === $cur ? ' (current)' : '' ?></option><?php endforeach; ?>
      </select></label>
    <noscript><button>Apply</button></noscript>
    <a class="refresh" href="?session=<?= urlencode($session) ?>">&#8635; Refresh</a>
    <a class="refresh" href="admin_report.php?session=<?= urlencode($session) ?>&print=1" target="_blank">&#11015; Programme/subject report (PDF)</a>
    <a class="refresh" href="export.php" title="All data, one sheet per programme">&#11015; Excel</a>
    <a class="refresh" href="college_report.php" target="_blank">College report</a>
    <span class="asof">As of <?= date('d M Y, H:i') ?></span>
  </form>

  <!-- ===== headline numbers ===== -->
  <section class="kpis">
    <div class="kpi"><div class="v"><?= $pct === null ? 'n/a' : $pct . '%' ?></div><div class="l">Papers with data</div>
      <div class="s"><?= $hasExpected ? $complete . ' of ' . $tot['expected'] . ' (incl. ' . $tot['nostud'] . ' with no students)' : 'needs a programme-to-paper list (not in the programme CSV)' ?></div></div>
    <div class="kpi"><div class="v"><?= $hasExpected ? $tot['pending'] : 'n/a' ?></div><div class="l">Still pending</div>
      <div class="s"><?= $hasExpected ? count(array_filter($prog, fn($p) => $p['pending'] > 0)) . ' programme(s) affected' : '' ?></div></div>
    <div class="kpi"><div class="v"><?= count($papers) ?></div><div class="l">Papers recorded</div>
      <div class="s"><?= count($incomplete) ?> without papersetters</div></div>
    <div class="kpi"><div class="v"><?= count($setters) ?></div><div class="l">Papersetters appointed</div>
      <div class="s"><?= count(array_unique(array_map(fn($s) => $s['college_code'], $setters))) ?> college(s)</div></div>
    <div class="kpi"><div class="v"><?= $contributors ?><small> / <?= $activeUsers ?></small></div><div class="l">Users who sent data</div>
      <div class="s"><?= $notSent ?> active user(s) not yet</div></div>
  </section>

  <!-- ===== coverage by programme ===== -->
  <section class="card" data-card>
    <div class="card-head"><h2>Coverage by programme</h2>
      <button type="button" class="tgl" data-toggle>Table view</button></div>
    <?php if (!$hasExpected): ?>
      <p class="muted">Coverage needs the list of expected papers, which comes from the Excel catalogue or the database (config.php &rarr; 'source').</p>
    <?php else: ?>
    <ul class="legend">
      <li><i class="sw s1"></i>Entered <b><?= $tot['done'] ?></b></li>
      <li><i class="sw s2"></i>Saved, no papersetters <b><?= $tot['incomplete'] ?></b></li>
      <li><i class="sw s3"></i>No students opted <b><?= $tot['nostud'] ?></b></li>
      <li><i class="sw s0"></i>Pending <b><?= $tot['pending'] ?></b></li>
    </ul>
    <div class="viz">
      <?php $i = 0; foreach ($prog as $p): $n = $p['done'] + $p['incomplete'] + $p['nostud'] + $p['pending']; if (!$n) continue; $i++; ?>
      <div class="hrow<?= $i > 25 ? ' more' : '' ?>">
        <a class="hl" href="<?= h($regLink($p['id'])) ?>" title="<?= h($p['name']) ?>"><?= h($p['name']) ?></a>
        <div class="stack" role="img" aria-label="<?= h($p['name']) ?>: <?= $p['done'] ?> entered, <?= $p['incomplete'] ?> without papersetters, <?= $p['nostud'] ?> no students, <?= $p['pending'] ?> pending">
          <?php foreach (['done' => ['s1', 'Entered'], 'incomplete' => ['s2', 'Saved, no papersetters'], 'nostud' => ['s3', 'No students opted'], 'pending' => ['s0', 'Pending']] as $k => [$cls, $lab]): if (!$p[$k]) continue; ?>
            <span class="seg <?= $cls ?>" style="flex:<?= $p[$k] ?>" tabindex="0" data-tip="<?= tip($p['name'], [[$lab, $p[$k], $cls]]) ?>"></span>
          <?php endforeach; ?>
        </div>
        <span class="hv"><?= $p['done'] + $p['nostud'] ?>/<?= $n ?></span>
      </div>
      <?php endforeach; ?>
      <?php if ($i > 25): ?><button type="button" class="linkbtn" data-showall>Show all <?= $i ?> programmes</button><?php endif; ?>
      <?php if ($unmatched): ?><p class="muted small"><?= $unmatched ?> saved paper(s) belong to a programme/subject that is no longer in the catalogue (e.g. saved before the Excel format changed) and are not counted above.</p><?php endif; ?>
    </div>
    <div class="tbl" hidden><div class="tw"><table>
      <thead><tr><th>Programme</th><th class="n">Entered</th><th class="n">Saved, no papersetters</th><th class="n">No students opted</th><th class="n">Pending</th></tr></thead>
      <tbody><?php foreach ($prog as $p): ?><tr><td><a href="<?= h($regLink($p['id'])) ?>"><?= h($p['name']) ?></a></td><td class="n"><?= $p['done'] ?></td><td class="n"><?= $p['incomplete'] ?></td><td class="n"><?= $p['nostud'] ?></td><td class="n"><?= $p['pending'] ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <?php endif; ?>
  </section>

  <!-- ===== who has sent data ===== -->
  <section class="card">
    <div class="card-head"><h2>Who has sent data</h2></div>
    <div class="tw"><table>
      <thead><tr><th>User</th><th>Status</th><th class="n">Papers</th><th class="bar-col"></th><th class="n">Papersetters</th><th class="n">Programmes</th><th>Last entry</th><th>Last login</th></tr></thead>
      <tbody>
      <?php foreach ($rowsU as $r): ?>
        <tr<?= $r['active'] ? '' : ' class="off"' ?>>
          <td><b><?= h($r['name']) ?></b><div class="muted small"><?= h($r['email']) ?> &middot; <?= h($r['role']) ?><?= $r['active'] ? '' : ' &middot; disabled' ?></div></td>
          <td><?= $r['papers'] > 0 ? '<span class="chip ok">&#10003; Sent</span>' : '<span class="chip warn">&#9888; Not yet</span>' ?></td>
          <td class="n"><?= $r['papers'] ?></td>
          <td class="bar-col"><span class="mini" data-tip="<?= tip($r['name'], [['Papers', $r['papers'], 's1']]) ?>"><i style="width:<?= round(100 * $r['papers'] / $maxPapers) ?>%"></i></span></td>
          <td class="n"><?= $r['setters'] ?></td><td class="n"><?= $r['progs'] ?></td>
          <td><?= h(ago($r['last'], $dbNow)) ?></td>
          <td><?= $r['login'] ? h(ago(date('Y-m-d H:i:s', (int)$r['login']), time())) : 'never' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($unknown): ?>
        <tr class="off"><td><b>Unknown</b><div class="muted small">entered before entries were tracked, or by a deleted user</div></td><td></td><td class="n"><?= $unknown['papers'] ?? 0 ?></td><td class="bar-col"></td><td class="n"><?= $unknown['setters'] ?? 0 ?></td><td class="n"><?= count($unknown['progs'] ?? []) ?></td><td><?= h(ago($unknown['last'] ?? '', $dbNow)) ?></td><td></td></tr>
      <?php endif; ?>
      </tbody>
    </table></div>
  </section>

  <div class="two">
    <!-- ===== entries per day ===== -->
    <section class="card" data-card>
      <div class="card-head"><h2>Papers entered per day <span class="muted small">last 30 days</span></h2><button type="button" class="tgl" data-toggle>Table view</button></div>
      <?php $maxD = max(1, ...array_values($days)); ?>
      <div class="viz"><div class="cols" role="img" aria-label="Papers entered per day, last 30 days">
        <?php foreach ($days as $d => $n): ?>
          <div class="col" tabindex="0" data-tip="<?= tip(date('D, d M', strtotime($d)), [['Papers', $n, 's1']]) ?>"><i class="s1" style="height:<?= $n ? max(3, round(100 * $n / $maxD)) : 0 ?>%"></i></div>
        <?php endforeach; ?>
      </div>
      <div class="xaxis"><span><?= date('d M', $dbNow - 29 * 86400) ?></span><span>today</span></div></div>
      <div class="tbl" hidden><div class="tw"><table><thead><tr><th>Date</th><th class="n">Papers</th></tr></thead>
        <tbody><?php foreach (array_reverse($days, true) as $d => $n): ?><tr><td><?= h(date('D, d M Y', strtotime($d))) ?></td><td class="n"><?= $n ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    </section>

    <!-- ===== roles ===== -->
    <section class="card" data-card>
      <div class="card-head"><h2>Appointments by role</h2><button type="button" class="tgl" data-toggle>Table view</button></div>
      <?php $maxR = max(1, ...array_values($roles) ?: [1]); ?>
      <div class="viz"><?php if (!$roles): ?><p class="muted">No papersetters yet.</p><?php endif; foreach ($roles as $r => $n): ?>
        <div class="hrow"><span class="hl plain" title="<?= h($r) ?>"><?= h($r) ?></span>
          <div class="stack single"><span class="seg s1" style="flex:0 0 <?= round(100 * $n / $maxR) ?>%" tabindex="0" data-tip="<?= tip($r, [['Appointments', $n, 's1']]) ?>"></span></div><span class="hv"><?= $n ?></span></div>
      <?php endforeach; ?></div>
      <div class="tbl" hidden><div class="tw"><table><thead><tr><th>Role</th><th class="n">Appointments</th></tr></thead>
        <tbody><?php foreach ($roles as $r => $n): ?><tr><td><?= h($r) ?></td><td class="n"><?= $n ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    </section>
  </div>

  <!-- ===== colleges ===== -->
  <section class="card" data-card>
    <div class="card-head"><h2>Colleges with most appointments <span class="muted small">top 10</span></h2><button type="button" class="tgl" data-toggle>Table view</button></div>
    <?php $maxC = max(1, ...array_values($colleges) ?: [1]); ?>
    <div class="viz"><?php if (!$colleges): ?><p class="muted">No papersetters yet.</p><?php endif; foreach ($colleges as $c => $n): ?>
      <div class="hrow"><span class="hl plain" title="<?= h($c) ?>"><?= h($c) ?></span>
        <div class="stack single"><span class="seg s1" style="flex:0 0 <?= round(100 * $n / $maxC) ?>%" tabindex="0" data-tip="<?= tip($c, [['Appointments', $n, 's1']]) ?>"></span></div><span class="hv"><?= $n ?></span></div>
    <?php endforeach; ?></div>
    <div class="tbl" hidden><div class="tw"><table><thead><tr><th>College</th><th class="n">Appointments</th></tr></thead>
      <tbody><?php foreach ($colleges as $c => $n): ?><tr><td><?= h($c) ?></td><td class="n"><?= $n ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  </section>

  <!-- ===== needs attention ===== -->
  <section class="card">
    <div class="card-head"><h2>Needs attention</h2></div>

    <h3>Pending papers <span class="count"><?= count($pending) ?></span></h3>
    <?php if (!$hasExpected): ?><p class="muted">Not available with the API data source.</p>
    <?php elseif (!$pending): ?><p class="ok-note">&#10003; Every expected paper has data.</p>
    <?php else: ?>
      <input class="search" type="search" placeholder="Filter pending papers&hellip;" data-filter="pendingList">
      <div class="tw scroll"><table id="pendingList"><thead><tr><th>Programme</th><th>Paper code</th><th>Subject</th></tr></thead>
        <tbody><?php foreach (array_slice($pending, 0, 500) as $x): ?><tr><td><?= h($x['prog']) ?></td><td><?= h($x['code']) ?></td><td><a href="<?= h($regLink($x['pid'], $x['sid'])) ?>"><?= h($x['name']) ?></a></td></tr><?php endforeach; ?></tbody></table></div>
      <?php if (count($pending) > 500): ?><p class="muted small">Showing the first 500 of <?= count($pending) ?>.</p><?php endif; ?>
    <?php endif; ?>

    <h3>Saved without any papersetter <span class="count"><?= count($incomplete) ?></span></h3>
    <?php if (!$incomplete): ?><p class="ok-note">&#10003; None.</p><?php else: ?>
      <div class="tw scroll"><table><thead><tr><th>Programme</th><th>Paper</th><th>Entered by</th><th>When</th></tr></thead>
        <tbody><?php $uname = array_column($users, 'name', 'id'); foreach (array_slice($incomplete, 0, 200) as $p): ?>
          <tr><td><?= h($p['programme_name']) ?></td><td><a href="<?= h($regLink($p['programme_id'], $p['subject_id'])) ?>"><?= h($p['paper_code']) ?> : <?= h($p['subject_name']) ?></a></td>
              <td><?= h($uname[$p['created_by'] ?? 0] ?? 'Unknown') ?></td><td><?= h(ago($p['created_at'], $dbNow)) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>

    <h3>Papersetters with missing email or mobile <span class="count"><?= count($noContact) ?></span></h3>
    <?php if (!$noContact): ?><p class="ok-note">&#10003; None.</p><?php else: ?>
      <div class="tw scroll"><table><thead><tr><th>Name</th><th>College</th><th>Paper</th><th>Missing</th></tr></thead>
        <tbody><?php foreach (array_slice($noContact, 0, 200) as $x): $p = $papersById[$x['paper_id']] ?? null; ?>
          <tr><td><?= h($x['name']) ?></td><td><?= h($x['college']) ?></td>
              <td><?= $p ? '<a href="' . h($regLink($p['programme_id'], $p['subject_id'])) . '">' . h($p['paper_code'] . ' : ' . $p['subject_name']) . '</a>' : '' ?></td>
              <td><span class="chip warn">&#9888; <?= h($x['miss']) ?></span></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </section>
</main>

<div id="tip" role="tooltip" hidden></div>
<script src="assets/dashboard.js"></script>
</body>
</html>
