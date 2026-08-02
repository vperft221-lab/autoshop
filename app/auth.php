<?php
/**
 * Authentication & access control.
 *  - Passwords hashed with password_hash() (bcrypt) / verified with password_verify().
 *  - Sessions hardened (httponly, samesite, regenerate on login, idle timeout).
 *  - Brute-force throttling via login_attempts table.
 *  - Role-based access control + tamper-evident audit log.
 */

function start_session(): void
{
    $cfg = config();
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name($cfg['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    session_start();

    // Idle timeout
    $now = time();
    if (isset($_SESSION['_last']) && ($now - $_SESSION['_last']) > $cfg['session_idle']) {
        session_unset();
        session_destroy();
        session_start();
        flash('info', 'Your session expired. Please sign in again.');
    }
    $_SESSION['_last'] = $now;
}

function current_user(): ?array
{
    static $user = null;
    if ($user !== null) return $user ?: null;
    $id = $_SESSION['uid'] ?? null;
    if (!$id) { $user = false; return null; }
    $user = one('SELECT * FROM users WHERE id = ? AND active = 1', [$id]) ?: false;
    return $user ?: null;
}

function require_login(): void
{
    if (!current_user()) {
        $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? '/';
        redirect('/login');
    }
}

function require_role(array $roles): void
{
    require_login();
    if (!in_array(current_user()['role'], $roles, true)) {
        http_response_code(403);
        echo layout('Access denied',
            '<div class="card empty"><h2>403 — Access denied</h2><p>You don\'t have permission to view this page.</p>' . btn('Back to dashboard', '/') . '</div>');
        exit;
    }
}

function can(string $role): bool
{
    $u = current_user();
    return $u && $u['role'] === $role;
}

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

/* ---------- login throttling ---------- */
function login_locked(string $username): bool
{
    $cfg = config();
    $since = date('Y-m-d H:i:s', time() - $cfg['login_lock_window']);
    $fails = (int) scalar(
        'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip = ? AND success = 0 AND created_at > ?',
        [$username, client_ip(), $since]
    );
    return $fails >= $cfg['login_max_tries'];
}

function record_attempt(string $username, bool $success): void
{
    insert('login_attempts', ['username' => $username, 'ip' => client_ip(), 'success' => $success ? 1 : 0]);
}

/* ---------- core auth actions ---------- */
function attempt_login(string $username, string $password): bool
{
    $user = one('SELECT * FROM users WHERE username = ? AND active = 1', [$username]);
    // Always run a hash compare to reduce username-enumeration timing differences.
    $hash = $user['password_hash'] ?? '$2y$12$............................................................';
    $ok = password_verify($password, $hash) && $user;

    record_attempt($username, (bool)$ok);
    if (!$ok) return false;

    // Re-hash if the algorithm/cost has since been strengthened.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $user['id']]);
    }

    session_regenerate_id(true);   // prevent session fixation
    $_SESSION['uid'] = $user['id'];
    audit('login', 'user', $user['id']);
    return true;
}

function logout(): void
{
    if ($u = current_user()) audit('logout', 'user', $u['id']);
    session_unset();
    session_destroy();
}

function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);   // bcrypt; auto-upgrades over time
}

/* ---------- audit ---------- */
function audit(string $action, ?string $entity = null, ?int $entityId = null, ?string $details = null): void
{
    insert('audit_logs', [
        'user_id'   => $_SESSION['uid'] ?? null,
        'action'    => $action,
        'entity'    => $entity,
        'entity_id' => $entityId,
        'details'   => $details,
        'ip'        => client_ip(),
    ]);
}
/* ---------- customer portal auth ---------- */

function current_customer(): ?array
{
    static $customer = null;
    if ($customer !== null) return $customer ?: null;
    $id = $_SESSION['customer_id'] ?? null;
    if (!$id) { $customer = false; return null; }
    $customer = one('SELECT * FROM customers WHERE id = ? AND portal_active = 1', [$id]) ?: false;
    return $customer ?: null;
}

function customer_require_login(): void
{
    if (!current_customer()) {
        $_SESSION['_customer_intended'] = $_SERVER['REQUEST_URI'] ?? '/customer';
        redirect('/customer/login');
    }
}

function attempt_customer_login(string $contact, string $password): bool
{
    if (login_locked('customer:' . $contact)) return false;

    $customer = one('SELECT * FROM customers WHERE (phone = ? OR email = ?) AND portal_active = 1', [$contact, $contact]);
    $hash = $customer['password_hash'] ?? '$2y$12$............................................................';
    $ok = password_verify($password, $hash) && $customer;

    record_attempt('customer:' . $contact, (bool)$ok);
    if (!$ok) return false;

    session_regenerate_id(true);
    unset($_SESSION['uid']); // clear any lingering staff identity in this browser session
    $_SESSION['customer_id'] = $customer['id'];
    audit('login', 'customer', $customer['id']);
    return true;
}

function customer_logout(): void
{
    if ($c = current_customer()) audit('logout', 'customer', $c['id']);
    unset($_SESSION['customer_id']);
}

function generate_temp_password(int $length = 8): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $pw = '';
    for ($i = 0; $i < $length; $i++) $pw .= $chars[random_int(0, strlen($chars) - 1)];
    return $pw;
}
