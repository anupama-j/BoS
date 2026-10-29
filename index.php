<?php
require_once __DIR__ . '/data_source.php';   // also loads db.php
require_once __DIR__ . '/auth.php';
$me = require_login();                      // not logged in -> login.php

$pdo = db();
$_SESSION['csrf'] = auth_csrf();
$flash = '';
$error = '';

/* ---------- SAVE / DELETE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad CSRF token'); }
    $do = $_POST['do'] ?? '';

    if ($do === 'delete') {
        $pdo->prepare('DELETE FROM papers WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        $flash = 'Record deleted.';
    } elseif ($do === 'save') {
        $f = fn($k) => trim($_POST[$k] ?? '');

        // LOCKED FIELDS: faculty, exam session, programme code/name, subject and paper code are never taken
        // from the browser. They are looked up again in the data source (Excel / database / API) using only
        // the selected programme id and subject id.
        $prog = src_find_programme($f('programme_id'));
        $subj = $prog ? src_find_subject((string)$prog['id'], $f('subject_id')) : null;
        $sess = src_current_session();

        $dev = $f('course_name_dev');                       // optional, Devanagari script
        $noStudents = !empty($_POST['no_students']) ? 1 : 0;

        if (!$prog || !$subj) {
            $error = 'Please pick a programme and a subject from the right-hand panel before saving.';
        } elseif ($dev !== '' && (mb_strlen($dev) > 255 || !preg_match('/\p{Devanagari}/u', $dev))) {
            $error = 'Course name (Devanagari) must be written in Devanagari script (e.g. मराठी / हिंदी), or left empty.';
        } elseif (!$sess) {
            $error = 'No exam session is defined in the data source (see data/exam_sessions.xlsx / exam_sessions table).';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO papers (faculty, exam_session, paper_code, programme_code, programme_name, programme_id,
                           subject_name, subject_id, course_name_dev, no_students, paper_title, remark, generated_on, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([(string)($prog['faculty'] ?? ''), $sess, (string)$subj['code'], (string)$prog['code'], $prog['name'],
                           (string)$prog['id'], $subj['name'], (string)$subj['id'],
                           $dev !== '' ? $dev : null, $noStudents,
                           (string)($subj['title'] ?? $subj['name']),
                           $f('remark'), $f('generated_on'), (int)$me['id']]);
            $pid = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare('INSERT INTO papersetters (paper_id, sr_no, name, college_code, college_name, address, appointed_as, email, mobile)
                                  VALUES (?,?,?,?,?,?,?,?,?)');
            $n = 0;
            foreach (($_POST['ps_name'] ?? []) as $i => $name) {
                if (trim($name) === '') continue;
                $n++;
                $ins->execute([$pid, $n, trim($name), trim($_POST['ps_ccode'][$i] ?? ''), trim($_POST['ps_college'][$i] ?? ''),
                               trim($_POST['ps_address'][$i] ?? ''), trim($_POST['ps_role'][$i] ?? ''), trim($_POST['ps_email'][$i] ?? ''), trim($_POST['ps_mobile'][$i] ?? '')]);
            }
            $pdo->commit();
            $flash = "Saved paper with $n papersetter(s)." . ($noStudents ? ' Marked: no students opted (the panel will take care if any student appears).' : '');
        }
    }
}

/* ---------- LIST (filtered from the right-hand panel) ---------- */
$fp = $_GET['fp'] ?? '';
$fs = $_GET['fs'] ?? '';
$sql = 'SELECT * FROM papers WHERE 1=1';
$args = [];
if ($fp !== '') { $sql .= ' AND programme_id = ?'; $args[] = $fp; }
if ($fs !== '') { $sql .= ' AND subject_id = ?';   $args[] = $fs; }
$sql .= ' ORDER BY programme_name, programme_id, subject_name, subject_id, id DESC';   // programme-wise, then subject-wise
$st = $pdo->prepare($sql); $st->execute($args);
$papers = $st->fetchAll();
$setters = $pdo->prepare('SELECT * FROM papersetters WHERE paper_id = ? ORDER BY sr_no');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Papersetter Register</title>
<link rel="stylesheet" href="assets/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body data-fp="<?= h($fp) ?>" data-fs="<?= h($fs) ?>">
<header>Papersetter Register &mdash; Confidential
  <span class="who"><?= h($me['name']) ?> &middot; <?php if ($me['role'] === 'admin'): ?><a href="dashboard.php">Dashboard</a> &middot; <a href="users.php">Users</a> &middot; <?php endif; ?><a href="college_report.php" target="_blank">My college report</a> &middot; <a href="logout.php">Log out</a></span></header>

<div class="layout">
  <main>
    <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="card" id="entryForm">
      <h2>Add paper &amp; papersetters</h2>
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="programme_id" id="programme_id">
      <input type="hidden" name="subject_id" id="subject_id">

      <div class="grid">
        <!-- Locked fields: read-only here, filled from the data source, and re-checked on the server when saving -->
        <div><label>&#128274; Faculty</label><input id="faculty" class="locked" readonly tabindex="-1" placeholder="from selected programme"></div>
        <div><label>&#128274; Exam session</label><input id="exam_session" class="locked" readonly tabindex="-1" placeholder="loading&hellip;"></div>
        <div><label>&#128274; Programme code</label><input id="programme_code" class="locked" readonly tabindex="-1" placeholder="pick a programme &rarr;"></div>
        <div><label>&#128274; Programme (pick from right panel)</label><input id="programme_name" class="locked" readonly tabindex="-1" placeholder="pick a programme &rarr;"></div>
        <div><label>&#128274; Paper code</label><input id="paper_code" class="locked" readonly tabindex="-1" placeholder="pick a subject &rarr;"></div>
        <div><label>&#128274; Subject (pick from right panel)</label><input id="subject_name" class="locked" readonly tabindex="-1" placeholder="pick a subject &rarr;"></div>
        <div class="full"><label>Course name in Devanagari <span class="muted">(optional)</span></label>
          <input name="course_name_dev" id="course_name_dev" lang="mr" maxlength="255" autocomplete="off" placeholder="उदा. माहिती तंत्रज्ञान"></div>
        <div class="full"><label class="chk"><input type="checkbox" name="no_students" id="no_students" value="1">
          No students opted for this course</label></div>
        <div><label>Remark</label><input name="remark"></div>
        <div><label>Generated on</label><input name="generated_on" placeholder="27 Jan, 2025 10:15:52"></div>
      </div>

      <h2 style="margin-top:16px">Papersetters</h2>
      <p class="muted" id="noStudNote" hidden>No students opted &mdash; you can still add the papersetters; the report will say the panel takes care of the paper if any student appears.</p>
      <div class="tablewrap" id="psWrap">
      <table id="psTable">
        <thead><tr><th style="width:30px">#</th><th>Name</th><th style="width:70px">Col. code</th><th>College name</th><th>Address</th>
          <th style="width:170px">Appointed as</th><th>Email</th><th style="width:105px">Mobile</th><th style="width:28px"></th></tr></thead>
        <tbody></tbody>
      </table>
      </div>
      <div class="actions">
        <button type="button" class="sec" id="addRow">+ Add papersetter</button>
        <button type="submit">Save</button>
      </div>
    </form>

    <div class="card">
      <?php $exportQs = http_build_query(array_filter(['fp' => $fp, 'fs' => $fs], fn($v) => $v !== '')); ?>
      <h2>Saved records
        <?php if ($fp !== '' || $fs !== ''): ?><span class="tag">filtered</span> <a href="index.php" style="font-size:12px">clear</a><?php endif; ?>
        <span class="export">
          <a class="btn" href="report.php?<?= h(http_build_query(array_merge(array_filter(['fp' => $fp, 'fs' => $fs], fn($v) => $v !== ''), ['print' => 1]))) ?>" target="_blank" title="Printable report, programme-wise. Choose Save as PDF in the print window.">&#11015; PDF report</a>
          <?php if ($me['role'] === 'admin'): ?>
          <a class="btn sec" href="export.php<?= $exportQs ? '?' . h($exportQs) : '' ?>" title="Download all information as Excel (administrators only)">&#11015; Excel</a>
          <?php endif; ?>
        </span>
      </h2>
      <?php if (!$papers): ?><p class="muted">No records yet.</p><?php endif; ?>

      <?php $curProg = null; $curSub = null;
      foreach ($papers as $p):
          $setters->execute([$p['id']]); $rows = $setters->fetchAll();
          if ($curProg !== $p['programme_id']): $curProg = $p['programme_id']; $curSub = null; ?>
            <h3 class="grp-prog"><?= h($p['programme_code']) ?> : <?= h($p['programme_name']) ?> <span class="tag"><?= h($p['faculty']) ?></span></h3>
          <?php endif;
          if ($curSub !== $p['subject_id']): $curSub = $p['subject_id']; ?>
            <h4 class="grp-sub"><?= h($p['paper_code']) ?> : <?= h($p['subject_name']) ?></h4>
          <?php endif; ?>
        <div class="paper">
          <div class="paper-head">
            <div>
              <span class="tag"><?= h($p['exam_session']) ?></span>
              <?php if ($p['no_students']): ?><span class="tag nostud">No students opted</span><?php endif; ?>
              <?php if (!empty($p['course_name_dev'])): ?><br><strong lang="mr"><?= h($p['course_name_dev']) ?></strong><?php endif; ?>
              <?php if ($p['remark']): ?><br><span class="muted">Remark: <?= h($p['remark']) ?></span><?php endif; ?>
            </div>
            <div style="display:flex;gap:6px;align-items:flex-start">
            <?php if ($me['role'] === 'admin' || (int)$p['created_by'] === (int)$me['id']): ?><a class="btn sec" href="edit.php?id=<?= (int)$p['id'] ?>">Edit</a><?php endif; ?>
            <form method="post" onsubmit="return confirm('Delete this record?')">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="del">Delete</button>
            </form></div>
          </div>
          <?php if ($p['no_students'] && !$rows): ?>
            <p class="muted">No students opted for this course &mdash; no papersetters entered.</p>
          <?php elseif (!$rows): ?>
            <p class="muted">No papersetters entered.</p>
          <?php else: ?>
          <table>
            <tr><th>Sr.</th><th>Papersetter (College code - name, address)</th><th>Appointed as</th><th>Email / Mobile</th></tr>
            <?php foreach ($rows as $r): ?>
              <tr><td><?= (int)$r['sr_no'] ?></td>
                  <td><strong><?= !empty($r['refused']) ? '<s>' . h($r['name']) . '</s> <span class="tag nostud">refused letter</span>' : h($r['name']) ?></strong><br><?= h($r['college_code']) ?> - <?= h($r['college_name']) ?>
                      <?php if (!empty($r['address'])): ?><br><span class="muted"><?= h($r['address']) ?></span><?php endif; ?></td>
                  <td><?= h($r['appointed_as']) ?></td>
                  <td><?= h($r['email']) ?><br><?= h($r['mobile']) ?></td></tr>
            <?php endforeach; ?>
          </table>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </main>

  <aside class="card">
    <h2>Programmes &amp; Subjects <a href="?refresh=1" title="Re-fetch from e-Samarth" style="font-size:12px">&#8635; refresh</a></h2>
    <div id="apiNote"></div>
    <input id="progSearch" type="search" placeholder="Search programme / code&hellip;" style="width:100%;margin-bottom:8px" hidden>
    <div id="progList" class="muted">Loading from e-Samarth&hellip;</div>
  </aside>
</div>

<script src="assets/collegepick.js?v=<?= (int)@filemtime(__DIR__ . '/assets/collegepick.js') ?>"></script>
<script src="assets/app.js?v=<?= (int)@filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
