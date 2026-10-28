<?php
require_once __DIR__ . '/auth.php';
auth_session();
if (auth_user()) { header('Location: index.php'); exit; }
$p = $_SESSION['pending'] ?? null;
$a = auth_cfg();
if (!$p || time() - $p['at'] > $a['otp_ttl'] + 300) { unset($_SESSION['pending']); header('Location: login.php'); exit; }

$st = db()->prepare('SELECT id, name, email, role, is_active FROM users WHERE id = ?'); $st->execute([$p['uid']]);
$u = $st->fetch();
if (!$u || !(int)$u['is_active']) { unset($_SESSION['pending']); header('Location: login.php'); exit; }

$error = ''; $info = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_ok()) { $error = 'Session expired. Please try again.'; }
    elseif (($_POST['do'] ?? '') === 'resend') {
        $wait = $a['resend_after'] - (time() - $p['sent']);
        if ($wait > 0) $error = "Please wait $wait second(s) before requesting a new code.";
        else {
            try { otp_send($u); $_SESSION['pending']['sent'] = time(); $p['sent'] = time(); $info = 'A new code has been sent.'; }
            catch (Throwable $e) { error_log('OTP email failed: ' . $e->getMessage()); $error = 'Could not send the email. Contact the administrator.'; }
        }
    } else {
        $r = otp_verify((int)$u['id'], preg_replace('/\D/', '', $_POST['code'] ?? ''));
        if ($r === true) { auth_login_session($u); header('Location: index.php'); exit; }
        $error = $r;
    }
}
auth_page_head('Enter login code');
if ($info)  echo '<div class="flash">' . h($info) . '</div>';
if ($error) echo '<div class="flash err">' . h($error) . '</div>';
?>
<p class="muted">We emailed a 6-digit code to <strong><?= h(mask_email($u['email'])) ?></strong>. It is valid for <?= (int)round($a['otp_ttl'] / 60) ?> minutes.</p>
<form method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h(auth_csrf()) ?>">
  <label>6-digit code</label>
  <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus class="otpinput" autocomplete="one-time-code">
  <button>Verify &amp; sign in</button>
</form>
<form method="post" style="margin-top:10px">
  <input type="hidden" name="csrf" value="<?= h(auth_csrf()) ?>"><input type="hidden" name="do" value="resend">
  <button class="sec">Send a new code</button> <a href="logout.php" style="font-size:13px;margin-left:8px">Cancel</a>
</form>
<?php auth_page_foot();
