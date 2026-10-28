<?php
/**
 * Login helpers. Include at the top of every protected page:
 *     require_once __DIR__ . '/auth.php';  require_login();          // or require_admin();
 * Flow: login.php (email + password) -> verify_otp.php (6-digit code emailed) -> session.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

function auth_cfg(): array { return cfg()['auth']; }

function auth_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // "Secure" cookies are only sent over https. Auto = on when this request itself is https.
    // (Do not trust X-Forwarded-Proto: on some free hosts it says https while the visitor is on http, and the cookie is then lost.)
    $sec = auth_cfg()['cookie_secure'] ?? null;
    $https = ($sec === null) ? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') : (bool)$sec;
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_name('PSREG');

    // If PHP's default session folder is not writable (some shared hosts), keep sessions in data/sessions/
    $path = session_save_path();
    if ($path === '' || !is_dir($path) || !is_writable($path)) {
        $dir = __DIR__ . '/data/sessions';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    }
    session_start();
}

function auth_user(): ?array {
    auth_session();
    if (empty($_SESSION['uid'])) return null;
    $idle = auth_cfg()['session_idle'] ?? 3600;
    if (time() - ($_SESSION['last'] ?? 0) > $idle) { auth_logout(false); return null; }
    static $u = null;
    if ($u === null || (int)$u['id'] !== (int)$_SESSION['uid']) {
        $st = db()->prepare('SELECT id, name, email, role, is_active, college_code, college_name FROM users WHERE id = ?');
        $st->execute([(int)$_SESSION['uid']]);
        $u = $st->fetch() ?: null;
    }
    if (!$u || !(int)$u['is_active']) { auth_logout(false); return null; }
    $_SESSION['last'] = time();
    return $u;
}

function auth_logout(bool $destroy = true): void {
    auth_session();
    $_SESSION = [];
    if ($destroy && session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

/** Redirect to the login page (or return JSON 401 for api.php) unless someone is logged in. */
function require_login(bool $json = false): array {
    if (PHP_SAPI === 'cli') return ['id' => 0, 'name' => 'cli', 'email' => '', 'role' => 'admin', 'is_active' => 1];
    $u = auth_user();
    if ($u) return $u;
    if ($json) { http_response_code(401); header('Content-Type: application/json'); exit(json_encode(['error' => 'Not logged in'])); }
    header('Location: login.php'); exit;
}
function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') { http_response_code(403); exit('Administrators only.'); }
    return $u;
}

function auth_csrf(): string {
    auth_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function auth_csrf_ok(): bool { auth_session(); return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? ''); }

function auth_user_count(): int { return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(); }

function auth_password_ok(string $p): bool { return strlen($p) >= 8; }

function auth_create_user(string $name, string $email, string $password, string $role = 'user'): int {
    $email = strtolower(trim($email));
    if (trim($name) === '') throw new InvalidArgumentException('Name is required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if (!auth_password_ok($password)) throw new InvalidArgumentException('Password must be at least 8 characters.');
    $st = db()->prepare('SELECT 1 FROM users WHERE email = ?'); $st->execute([$email]);
    if ($st->fetch()) throw new InvalidArgumentException('A user with this email already exists.');
    db()->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)')
        ->execute([trim($name), $email, password_hash($password, PASSWORD_DEFAULT), $role === 'admin' ? 'admin' : 'user']);
    return (int)db()->lastInsertId();
}

/**
 * Step 1. Returns the user row on success, or a string error message.
 * Same message for "no such user" and "wrong password" so emails cannot be probed.
 */
function auth_check_password(string $email, string $password) {
    $a = auth_cfg();
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([strtolower(trim($email))]);
    $u = $st->fetch();
    $bad = 'Incorrect email or password.';
    if (!$u) { password_verify($password, '$2y$10$usesomesillystringforsaltOuUZ1x2s1b5v3v7Q8d1W3bN7m0uC'); return $bad; }   // equalise timing
    if (!(int)$u['is_active']) return 'This account is disabled. Contact the administrator.';
    if ((int)$u['locked_until'] > time()) return 'Too many failed attempts. Try again in ' . max(1, (int)ceil(((int)$u['locked_until'] - time()) / 60)) . ' minute(s).';
    if (!password_verify($password, $u['password_hash'])) {
        $n = (int)$u['failed_attempts'] + 1;
        if ($n >= $a['max_failed']) { db()->prepare('UPDATE users SET failed_attempts = 0, locked_until = ? WHERE id = ?')->execute([time() + 60 * $a['lock_minutes'], $u['id']]); }
        else                        { db()->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')->execute([$n, $u['id']]); }
        return $bad;
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    db()->prepare('UPDATE users SET failed_attempts = 0, locked_until = 0 WHERE id = ?')->execute([$u['id']]);
    return $u;
}

function otp_hash(int $uid, string $code): string { return hash_hmac('sha256', "$uid:$code", auth_cfg()['secret']); }

/** Create a new 6-digit code, email it. Throws if the email cannot be sent. */
function otp_send(array $u): void {
    $a = auth_cfg(); $pdo = db();
    $pdo->prepare('UPDATE login_otps SET used = 1 WHERE user_id = ? AND used = 0')->execute([$u['id']]);   // old codes die
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare('INSERT INTO login_otps (user_id, code_hash, expires_at, ip, created_at) VALUES (?,?,?,?,?)')
        ->execute([$u['id'], otp_hash((int)$u['id'], $code), time() + $a['otp_ttl'], $_SERVER['REMOTE_ADDR'] ?? '', time()]);
    $mins = (int)round($a['otp_ttl'] / 60);
    mail_send($u['email'], "Your login code: $code",
        "Hello {$u['name']},\n\nYour one-time login code for the BoS Papersetter Register is:\n\n    $code\n\n"
      . "It is valid for $mins minutes and can be used once. If you did not try to log in, ignore this email and tell the administrator.\n");
}

/** Returns true when the code is right; otherwise an error string. */
function otp_verify(int $uid, string $code) {
    $a = auth_cfg(); $pdo = db();
    $st = $pdo->prepare('SELECT * FROM login_otps WHERE user_id = ? AND used = 0 ORDER BY id DESC LIMIT 1');
    $st->execute([$uid]);
    $o = $st->fetch();
    if (!$o || (int)$o['expires_at'] < time()) return 'This code has expired. Request a new one.';
    if ((int)$o['attempts'] >= $a['otp_tries']) { $pdo->prepare('UPDATE login_otps SET used = 1 WHERE id = ?')->execute([$o['id']]); return 'Too many wrong codes. Request a new one.'; }
    $pdo->prepare('UPDATE login_otps SET attempts = attempts + 1 WHERE id = ?')->execute([$o['id']]);
    if (!hash_equals($o['code_hash'], otp_hash($uid, trim($code)))) return 'Incorrect code.';
    $pdo->prepare('UPDATE login_otps SET used = 1 WHERE id = ?')->execute([$o['id']]);
    return true;
}

function auth_login_session(array $u): void {
    auth_session();
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$u['id'], 'last' => time(), 'csrf' => bin2hex(random_bytes(16))];
    db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([time(), $u['id']]);
}

function mask_email(string $e): string {
    [$l, $d] = explode('@', $e) + [1 => ''];
    return substr($l, 0, 2) . str_repeat('*', max(1, strlen($l) - 2)) . '@' . $d;
}

/** Shared page shell for login / otp / users screens */
function auth_page_head(string $title): void { ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title><link rel="stylesheet" href="assets/style.css"></head>
<body class="authbody"><div class="authbox card"><h2><?= h($title) ?></h2>
<?php }
function auth_page_foot(): void { echo '</div></body></html>'; }
