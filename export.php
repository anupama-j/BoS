<?php
/**
 * Excel export of the saved papers + papersetters -- ADMINISTRATORS ONLY.
 *   export.php            -> one workbook:  "Summary" sheet, "All programmes" sheet, then one sheet per programme.
 * Everything is sorted programme-wise, then subject-wise. Optional filters (same as the register page): ?fp=<programme id>&fs=<subject id>
 * Normal users get the PDF report instead (report.php).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/xlsx_writer.php';

date_default_timezone_set('Asia/Kolkata');
$pdo = db();
$fp  = $_GET['fp'] ?? '';
$fs  = $_GET['fs'] ?? '';

$where = 'WHERE 1=1'; $args = [];
if ($fp !== '') { $where .= ' AND p.programme_id = ?'; $args[] = $fp; }
if ($fs !== '') { $where .= ' AND p.subject_id = ?';   $args[] = $fs; }

$st = $pdo->prepare("SELECT p.*, s.sr_no, s.name AS ps_name, s.college_code, s.college_name, s.address, s.appointed_as, s.email, s.mobile, s.refused
                       FROM papers p LEFT JOIN papersetters s ON s.paper_id = p.id
                       $where ORDER BY p.programme_name, p.programme_id, p.subject_name, p.subject_id, p.id DESC, s.sr_no");
$st->execute($args);

$head = ['Faculty', 'Exam Session', 'Programme Code', 'Programme', 'Paper Code', 'Subject', 'Course Name (Devanagari)', 'No Students Opted',
         'Remark', 'Generated On', 'Sr No', 'Papersetter Name', 'College Code', 'College Name', 'Papersetter Address', 'Appointed As', 'Email', 'Mobile', 'Letter', 'Saved At'];
$widths = [20, 22, 14, 45, 12, 30, 30, 12, 20, 20, 6, 28, 12, 50, 40, 36, 30, 13, 14, 19];

$all = [$head]; $byProg = []; $sum = [];
foreach ($st as $r) {
    $row = [$r['faculty'], $r['exam_session'], $r['programme_code'], $r['programme_name'], $r['paper_code'], $r['subject_name'],
            $r['course_name_dev'], $r['no_students'] ? 'Yes' : '', $r['remark'], $r['generated_on'], $r['sr_no'], $r['ps_name'], $r['college_code'],
            $r['college_name'], $r['address'], $r['appointed_as'], $r['email'], $r['mobile'], $r['ps_name'] !== null && $r['ps_name'] !== '' ? ($r['refused'] ? 'Refused' : 'Accepted') : '', $r['created_at']];
    $all[] = $row;
    $k = (string)$r['programme_id'];
    $byProg[$k]['name'] = $r['programme_code'] !== '' && $r['programme_code'] !== null ? $r['programme_code'] : $r['programme_name'];
    $byProg[$k]['rows'][] = $row;

    $S = &$sum[$k];
    $S['code'] = $r['programme_code']; $S['name'] = $r['programme_name']; $S['faculty'] = $r['faculty'];
    $S['papers'][$r['id']] = (int)$r['no_students'];
    $S['subjects'][$r['subject_id']] = 1;
    if ($r['ps_name'] !== null && $r['ps_name'] !== '') $S['setters'] = ($S['setters'] ?? 0) + 1;
    unset($S);
}

$summary = [['Programme Code', 'Programme', 'Faculty', 'Subjects', 'Papers Recorded', 'No Students Opted', 'Papersetters']];
$tot = [0, 0, 0, 0];
foreach ($sum as $S) {
    $np = count($S['papers']); $ns = array_sum($S['papers']); $nsub = count($S['subjects']); $nps = $S['setters'] ?? 0;
    $summary[] = [$S['code'], $S['name'], $S['faculty'], $nsub, $np, $ns, $nps];
    $tot[0] += $nsub; $tot[1] += $np; $tot[2] += $ns; $tot[3] += $nps;
}
if (count($summary) > 1) $summary[] = ['', 'TOTAL', '', $tot[0], $tot[1], $tot[2], $tot[3]];

$sheets = [
    ['name' => 'Summary',        'rows' => $summary, 'widths' => [14, 60, 22, 10, 16, 18, 14]],
    ['name' => 'All programmes', 'rows' => $all,     'widths' => $widths],
];
foreach ($byProg as $p) $sheets[] = ['name' => $p['name'], 'rows' => array_merge([$head], $p['rows']), 'widths' => $widths];

$tmp = tempnam(sys_get_temp_dir(), 'xlsx');
try {
    xlsx_write_sheets($tmp, $sheets);
} catch (Throwable $e) {
    @unlink($tmp);
    http_response_code(500);
    exit('Excel export failed: ' . $e->getMessage());
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="papersetters_' . date('Ymd_His') . '.xlsx"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
