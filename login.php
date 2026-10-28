<?php
require_once __DIR__ . '/auth.php';
auth_session();
if (auth_user()) { header('Location: index.php'); exit; }

$error = ''; $first = (auth_user_count() === 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_ok()) { $error = 'Session expired. Please try again.'; }
    elseif ($first) {
        // very first run: create the administrator account
        try {
            if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) throw new InvalidArgumentException('Passwords do not match.');
            auth_create_user($_POST['name'] ?? '', $_POST['email'] ?? '', $_POST['password'] ?? '', 'admin');
            $first = false;
            $ok = 'Administrator created. Please log in.';
        } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    } else {
        $r = auth_check_password($_POST['email'] ?? '', $_POST['password'] ?? '');
        if (is_string($r)) { $error = $r; }
        elseif (!(auth_cfg()['otp'] ?? true)) { auth_login_session($r); header('Location: index.php'); exit; }
        else {
            try {
                otp_send($r);
                $_SESSION['pending'] = ['uid' => (int)$r['id'], 'at' => time(), 'sent' => time()];
                header('Location: verify_otp.php'); exit;
            } catch (Throwable $e) {
                error_log('OTP email failed: ' . $e->getMessage());
                $error = 'Could not send the login code by email. Contact the administrator.';
            }
        }
    }
}
auth_page_head($first ? 'Create administrator' : 'Sign in');
if (!empty($ok)) echo '<div class="flash">' . h($ok) . '</div>';
if ($error) echo '<div class="flash err">' . h($error) . '</div>';
?>
<form method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h(auth_csrf()) ?>">
  <?php if ($first): ?>
    <p class="muted">No users exist yet. Create the first administrator account.</p>
    <label>Name</label><input name="name" required value="<?= h($_POST['name'] ?? '') ?>">
    <label>Email</label><input name="email" type="email" required value="<?= h($_POST['email'] ?? '') ?>">
    <label>Password (min 8 characters)</label><input name="password" type="password" required minlength="8">
    <label>Repeat password</label><input name="password2" type="password" required minlength="8">
    <button>Create administrator</button>
  <?php else: ?>
    <label>Email</label><input name="email" type="email" required autofocus value="<?= h($_POST['email'] ?? '') ?>">
    <label>Password</label><input name="password" type="password" required autocomplete="current-password">
    <button><?= (auth_cfg()['otp'] ?? true) ? 'Continue &rarr; email code' : 'Sign in' ?></button>
  <?php endif; ?>
</form>
<?php auth_page_foot();
