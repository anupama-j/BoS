<?php
/** Administrators: add users, reset passwords, disable / delete accounts. */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/data_source.php';
$me = require_admin();
$pdo = db();
$msg = ''; $err = '';
$colleges = src_colleges()['items'] ?? [];
/** Store the user's college; the code is checked against the catalogue when one is available. */
function user_set_college(PDO $pdo, int $id, string $code): void {
    global $colleges;
    $code = trim($code); $name = '';
    if ($code !== '' && $colleges) {
        $hit = null; foreach ($colleges as $c) if ((string)$c['code'] === norm_college_code($code)) $hit = $c;
        if (!$hit) throw new InvalidArgumentException("College code '$code' is not in the catalogue.");
        $code = (string)$hit['code']; $name = $hit['name'];
    }
    $pdo->prepare('UPDATE users SET college_code = ?, college_name = ? WHERE id = ?')->execute([$code ?: null, $name ?: null, $id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!auth_csrf_ok()) { http_response_code(400); exit('Bad CSRF token'); }
    $id = (int)($_POST['id'] ?? 0);
    try {
        switch ($_POST['do'] ?? '') {
            case 'create':
                $uid = auth_create_user($_POST['name'] ?? '', $_POST['email'] ?? '', $_POST['password'] ?? '', $_POST['role'] ?? 'user');
                user_set_college($pdo, $uid, $_POST['college'] ?? '');
                $msg = 'User created. Give them the password you set (they can only log in with the email code as well).'; break;
            case 'reset':
                if (!auth_password_ok($_POST['password'] ?? '')) throw new InvalidArgumentException('Password must be at least 8 characters.');
                $pdo->prepare('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = 0 WHERE id = ?')
                    ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
                $msg = 'Password changed.'; break;
            case 'college':
                user_set_college($pdo, $id, $_POST['college'] ?? ''); $msg = 'College saved.'; break;
            case 'toggle':
                if ($id === (int)$me['id']) throw new InvalidArgumentException('You cannot disable your own account.');
                $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]); $msg = 'Updated.'; break;
            case 'unlock':
                $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = 0 WHERE id = ?')->execute([$id]); $msg = 'Account unlocked.'; break;
            case 'role':
                if ($id === (int)$me['id']) throw new InvalidArgumentException('You cannot change your own role.');
                $pdo->prepare("UPDATE users SET role = CASE WHEN role = 'admin' THEN 'user' ELSE 'admin' END WHERE id = ?")->execute([$id]); $msg = 'Role changed.'; break;
            case 'delete':
                if ($id === (int)$me['id']) throw new InvalidArgumentException('You cannot delete your own account.');
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]); $msg = 'User deleted.'; break;
        }
    } catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
}
$users = $pdo->query('SELECT * FROM users ORDER BY name')->fetchAll();
$csrf = auth_csrf();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Users</title><link rel="stylesheet" href="assets/style.css"></head><body>
<header>Users &mdash; <a href="index.php" style="color:#fff">&larr; back to register</a> &middot; <a href="dashboard.php" style="color:#fff">Dashboard</a>
  <span class="who"><?= h($me['name']) ?> &middot; <a href="logout.php">Log out</a></span></header>
<div style="max-width:1000px;margin:16px auto;padding:0 16px">
<?php if ($msg): ?><div class="flash"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>

<div class="card"><h2>Add user</h2>
<form method="post" class="grid" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="create">
  <div><label>Name</label><input name="name" required></div>
  <div><label>Email (the login code goes here)</label><input name="email" type="email" required></div>
  <div><label>Initial password (min 8)</label><input name="password" type="text" minlength="8" required></div>
  <div><label>Role</label><select name="role"><option value="user">User</option><option value="admin">Administrator</option></select></div>
  <div><label>College code (for the college report)</label><input name="college" list="collegeCodes" placeholder="e.g. 503" autocomplete="off"></div>
  <div><label>&nbsp;</label><button>Add user</button></div>
</form></div>

<div class="card"><h2>All users</h2>
<div class="tablewrap"><table>
<tr><th>Name</th><th>Email</th><th>College</th><th>Role</th><th>Status</th><th>Last login</th><th style="width:330px">Actions</th></tr>
<?php foreach ($users as $u): $locked = (int)$u['locked_until'] > time(); $self = (int)$u['id'] === (int)$me['id']; ?>
<tr>
  <td><?= h($u['name']) ?><?= $self ? ' <span class="tag">you</span>' : '' ?></td>
  <td><?= h($u['email']) ?></td>
  <td><form method="post" style="display:flex;gap:4px;align-items:center">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="do" value="college">
    <input name="college" list="collegeCodes" value="<?= h($u['college_code']) ?>" style="width:70px;padding:4px" title="<?= h($u['college_name']) ?>" autocomplete="off"><button class="sec">Save</button></form>
    <small><?= h($u['college_name']) ?></small></td>
  <td><?= h($u['role']) ?></td>
  <td><?= (int)$u['is_active'] ? 'Active' : '<b style="color:var(--danger)">Disabled</b>' ?><?= $locked ? ' <span class="tag">locked</span>' : '' ?></td>
  <td><?= $u['last_login'] ? h(date('d M Y H:i', (int)$u['last_login'])) : '&mdash;' ?></td>
  <td>
    <form method="post" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <input name="password" type="text" placeholder="new password" style="width:120px;padding:4px">
      <button name="do" value="reset" class="sec">Set password</button>
      <?php if ($locked): ?><button name="do" value="unlock" class="sec">Unlock</button><?php endif; ?>
      <?php if (!$self): ?>
        <button name="do" value="toggle" class="sec"><?= (int)$u['is_active'] ? 'Disable' : 'Enable' ?></button>
        <button name="do" value="role" class="sec">Make <?= $u['role'] === 'admin' ? 'user' : 'admin' ?></button>
        <button name="do" value="delete" class="del" formnovalidate onclick="return confirm('Delete this user?')">Delete</button>
      <?php endif; ?>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</table></div></div>
</div><datalist id="collegeCodes"><?php foreach ($colleges as $c): ?><option value="<?= h($c['code']) ?>"><?= h($c['name']) ?></option><?php endforeach; ?></datalist>
</body></html>
