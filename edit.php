<?php
/**
 * Edit a saved record: correct details, ADD papersetters (e.g. a replacement), and mark a papersetter who
 * REFUSED the appointment letter. Refused names stay on the record and are printed struck through on the reports.
 * Administrators can edit any record; other users only the ones they entered.
 */
require_once __DIR__ . '/data_source.php';
require_once __DIR__ . '/auth.php';
$me = require_login();
$pdo = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$st = $pdo->prepare('SELECT * FROM papers WHERE id = ?'); $st->execute([$id]);
$paper = $st->fetch();
if (!$paper) { http_response_code(404); exit('Record not found.'); }
if ($me['role'] !== 'admin' && (int)$paper['created_by'] !== (int)$me['id']) { http_response_code(403); exit('You can only edit records you entered.'); }

try {   // older databases: add the column here too, in case db.php / schema.sql were not uploaded yet
    if (!$pdo->query("SHOW COLUMNS FROM papersetters LIKE 'refused'")->fetch()) $pdo->exec('ALTER TABLE papersetters ADD COLUMN refused TINYINT(1) NOT NULL DEFAULT 0');
} catch (Throwable $e) { /* SQLite stand-in used in tests has no SHOW COLUMNS */ }

$ROLES = ['Chairperson/Paper Setter/Examiner/Moderator', 'Paper Setter/Examiner/Moderator', 'Paper Setter', 'Examiner', 'Moderator', 'Translator'];
$csrf = auth_csrf();
$flash = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_ok()) { http_response_code(400); exit('Bad CSRF token'); }
    $dev = trim($_POST['course_name_dev'] ?? '');
    $noStudents = !empty($_POST['no_students']) ? 1 : 0;
    if ($dev !== '' && (mb_strlen($dev) > 255 || !preg_match('/\p{Devanagari}/u', $dev))) {
        $error = 'Course name (Devanagari) must be written in Devanagari script, or left empty.';
    } else try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE papers SET course_name_dev = ?, no_students = ?, remark = ?, generated_on = ? WHERE id = ?')
            ->execute([$dev !== '' ? $dev : null, $noStudents, trim($_POST['remark'] ?? ''), trim($_POST['generated_on'] ?? ''), $id]);
        $n = 0;
        {
            $pdo->prepare('DELETE FROM papersetters WHERE paper_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO papersetters (paper_id, sr_no, name, college_code, college_name, address, appointed_as, email, mobile, refused)
                                  VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach (($_POST['ps_name'] ?? []) as $i => $name) {
                if (trim($name) === '') continue;
                $n++;
                $ins->execute([$id, $n, trim($name), trim($_POST['ps_ccode'][$i] ?? ''), trim($_POST['ps_college'][$i] ?? ''),
                               trim($_POST['ps_address'][$i] ?? ''), trim($_POST['ps_role'][$i] ?? ''), trim($_POST['ps_email'][$i] ?? ''),
                               trim($_POST['ps_mobile'][$i] ?? ''), ($_POST['ps_refused'][$i] ?? '0') === '1' ? 1 : 0]);
            }
        }
        $pdo->commit();
        $flash = "Saved. $n papersetter(s) on this record." . ($noStudents ? ' Marked: no students opted.' : '');
        $st->execute([$id]); $paper = $st->fetch();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Could not save: ' . $e->getMessage();
    }
}
$ps = $pdo->prepare('SELECT * FROM papersetters WHERE paper_id = ? ORDER BY sr_no'); $ps->execute([$id]);
$rows = $ps->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) {          // keep what the user typed
    $rows = [];
    foreach (($_POST['ps_name'] ?? []) as $i => $nm) $rows[] = ['name' => $nm, 'college_code' => $_POST['ps_ccode'][$i] ?? '', 'college_name' => $_POST['ps_college'][$i] ?? '',
        'address' => $_POST['ps_address'][$i] ?? '', 'appointed_as' => $_POST['ps_role'][$i] ?? '', 'email' => $_POST['ps_email'][$i] ?? '',
        'mobile' => $_POST['ps_mobile'][$i] ?? '', 'refused' => $_POST['ps_refused'][$i] ?? 0];
}
$colleges = src_colleges()['items'] ?? [];
$back = 'index.php?fp=' . urlencode($paper['programme_id']) . '&fs=' . urlencode($paper['subject_id']);

function ps_row(array $r, array $ROLES): string {
    $o = '';
    foreach ($ROLES as $x) $o .= '<option' . ($x === ($r['appointed_as'] ?? '') ? ' selected' : '') . '>' . h($x) . '</option>';
    return '<tr' . (!empty($r['refused']) ? ' class="refused"' : '') . '><td class="sr"></td>'
        . '<td><input name="ps_name[]" value="' . h($r['name'] ?? '') . '"></td>'
        . '<td><input name="ps_ccode[]" value="' . h($r['college_code'] ?? '') . '" autocomplete="off"></td>'
        . '<td><input name="ps_college[]" value="' . h($r['college_name'] ?? '') . '" autocomplete="off"></td>'
        . '<td><input name="ps_address[]" value="' . h($r['address'] ?? '') . '" placeholder="Address"></td>'
        . '<td><select name="ps_role[]">' . $o . '</select></td>'
        . '<td><input name="ps_email[]" type="email" value="' . h($r['email'] ?? '') . '"></td>'
        . '<td><input name="ps_mobile[]" value="' . h($r['mobile'] ?? '') . '"></td>'
        . '<td><select name="ps_refused[]" class="st"><option value="0">Accepted</option><option value="1"' . (!empty($r['refused']) ? ' selected' : '') . '>Refused letter</option></select></td>'
        . '<td><button type="button" class="del" title="Remove">&times;</button></td></tr>';
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit record</title><link rel="stylesheet" href="assets/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/style.css') ?>">
<style>tr.refused input { text-decoration:line-through; color:#888; } tr.refused select.st { background:#fdecea; } .ro { background:#f1f3f8; }</style></head>
<body>
<header>Edit record &mdash; <a href="<?= h($back) ?>" style="color:#fff">&larr; back to register</a>
  <span class="who"><?= h($me['name']) ?> &middot; <a href="logout.php">Log out</a></span></header>
<div style="max-width:1200px;margin:16px auto;padding:0 16px">
<?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

<form method="post" class="card" id="editForm">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$id ?>">
  <h2><?= h($paper['paper_code']) ?> : <?= h($paper['subject_name']) ?></h2>
  <p class="muted"><?= h($paper['programme_code']) ?> : <?= h($paper['programme_name']) ?> &middot; <?= h($paper['exam_session']) ?> &mdash; these are locked; to change them delete the record and enter it again.</p>
  <div class="grid">
    <div class="full"><label>Course name in Devanagari <span class="muted">(optional)</span></label>
      <input name="course_name_dev" lang="mr" maxlength="255" value="<?= h($paper['course_name_dev']) ?>" autocomplete="off"></div>
    <div class="full"><label class="chk"><input type="checkbox" name="no_students" id="no_students" value="1" <?= $paper['no_students'] ? 'checked' : '' ?>> No students opted for this course</label></div>
    <div><label>Remark</label><input name="remark" value="<?= h($paper['remark']) ?>"></div>
    <div><label>Generated on</label><input name="generated_on" value="<?= h($paper['generated_on']) ?>"></div>
  </div>

  <h2 style="margin-top:16px">Papersetters</h2>
  <p class="muted">If a papersetter refuses the letter, set their status to <b>Refused letter</b> (the name stays on the record and is printed struck through), then use <b>+ Add papersetter</b> for the replacement.</p>
  <div class="tablewrap" id="psWrap"><table id="psTable">
    <thead><tr><th style="width:30px">#</th><th>Name</th><th style="width:70px">Col. code</th><th>College name</th><th>Address</th><th style="width:170px">Appointed as</th>
      <th>Email</th><th style="width:105px">Mobile</th><th style="width:130px">Letter</th><th style="width:28px"></th></tr></thead>
    <tbody><?php foreach ($rows as $r) echo ps_row($r, $ROLES); ?></tbody>
  </table></div>
  <div class="actions"><button type="button" class="sec" id="addRow">+ Add papersetter</button><button type="submit">Save changes</button></div>
</form>
</div>
<script src="assets/collegepick.js?v=<?= (int)@filemtime(__DIR__ . '/assets/collegepick.js') ?>"></script>
<template id="newRow"><?= ps_row([], $ROLES) ?></template>
<script>
const COLLEGES = <?= json_encode($colleges, JSON_UNESCAPED_UNICODE) ?>;
const tb = document.querySelector('#psTable tbody');
function wire(tr) {
  tr.querySelector('.del').onclick = () => { tr.remove(); renum(); };
  const code = tr.querySelector('[name="ps_ccode[]"]'), name = tr.querySelector('[name="ps_college[]"]');
  window.attachCollegePicker(code, name, () => COLLEGES);
  tr.querySelector('select.st').addEventListener('change', e => tr.classList.toggle('refused', e.target.value === '1'));
}
function renum() { tb.querySelectorAll('.sr').forEach((td, i) => td.textContent = i + 1); }
document.querySelector('#addRow').onclick = () => { tb.appendChild(document.querySelector('#newRow').content.cloneNode(true)); wire(tb.lastElementChild); renum(); };
tb.querySelectorAll('tr').forEach(wire); renum();

</script>
</body></html>
