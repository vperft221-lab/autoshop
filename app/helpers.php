<?php
/**
 * Helpers: escaping, validation, flash, CSRF, formatting, and the
 * server-rendered UI component system (layout + reusable widgets).
 */

/* ---------- compatibility shims (PHP 7.4 / missing mbstring) ---------- */
if (!function_exists('str_starts_with')) {
    function str_starts_with($h, $n) { return $n === '' || strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($h, $n) { return $n === '' || substr($h, -strlen($n)) === $n; }
}
if (!function_exists('str_contains')) {
    function str_contains($h, $n) { return $n === '' || strpos($h, $n) !== false; }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = null) { return $length === null ? substr($s, $start) : substr($s, $start, $length); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return strlen($s); }
}
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth($s, $start, $width, $trim = '', $enc = null) {
        $s = substr($s, $start);
        return strlen($s) > $width ? substr($s, 0, $width) . $trim : $s;
    }
}

function config(?string $key = null)
{
    static $cfg = null;
    if ($cfg === null) $cfg = require dirname(__DIR__) . '/config.php';
    return $key ? ($cfg[$key] ?? null) : $cfg;
}

/* ---------- escaping & input ---------- */
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function input(string $key, $default = ''): string
{
    return trim((string)($_POST[$key] ?? $_GET[$key] ?? $default));
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/* ---------- base path (lets the app run in a subfolder) ---------- */
function url(string $path): string
{
    $base = defined('BASE') ? BASE : '';
    if ($base === '' || $path === '' || $path[0] !== '/') return $path;
    if ($path === $base || str_starts_with($path, $base . '/')) return $path; // already prefixed
    return $base . $path;
}

function base_rewrite(string $html): string
{
    $base = defined('BASE') ? BASE : '';
    if ($base === '') return $html;
    return str_replace(
        ['href="/', 'src="/', 'action="/'],
        ['href="' . $base . '/', 'src="' . $base . '/', 'action="' . $base . '/'],
        $html
    );
}

/* ---------- flash messages ---------- */
function flash(string $type, string $msg): void { $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg]; }
function take_flash(): array { $f = $_SESSION['_flash'] ?? []; unset($_SESSION['_flash']); return $f; }

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_token" value="' . csrf_token() . '">'; }
function csrf_verify(): void
{
    $t = $_POST['_token'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['_csrf'] ?? '', $t)) {
        http_response_code(419);
        exit('Invalid or expired security token. Please go back and try again.');
    }
}

/* ---------- formatting ---------- */
function money($v): string { return 'GH₵ ' . number_format((float)$v, 2); }
function dt($v): string { return $v ? date('d/m/Y H:i', strtotime($v)) : '—'; }
function d($v): string { return $v ? date('d/m/Y', strtotime($v)) : '—'; }

/**
 * Calculate Ghana VAT/NHIL/GETFund on a taxable value (labour + parts).
 * Each levy is charged on the same taxable value (Act 1151, from 1 Jan 2026),
 * not compounded on top of each other.
 */
function calc_tax(float $subtotal): array
{
    $t = config('tax') ?? ['vat_rate' => 15.0, 'nhil_rate' => 2.5, 'getfund_rate' => 2.5];
    $vat     = round($subtotal * $t['vat_rate'] / 100, 2);
    $nhil    = round($subtotal * $t['nhil_rate'] / 100, 2);
    $getfund = round($subtotal * $t['getfund_rate'] / 100, 2);
    return [
        'vat_rate' => $t['vat_rate'], 'nhil_rate' => $t['nhil_rate'], 'getfund_rate' => $t['getfund_rate'],
        'vat_amount' => $vat, 'nhil_amount' => $nhil, 'getfund_amount' => $getfund,
        'tax_total' => round($vat + $nhil + $getfund, 2),
        'total' => round($subtotal + $vat + $nhil + $getfund, 2),
    ];
}

/**
 * Renders the Paddy's Auto Tech logo image if it's been placed at
 * public/assets/images/logo.png, otherwise falls back to a plain letter mark
 * so the layout never breaks if the file is missing.
 */
function brand_mark_html(string $fallbackLetter = 'P'): string
{
    $path = __DIR__ . '/../public/assets/images/logo.png';
    if (is_file($path)) {
        return '<img src="/assets/images/logo.png" alt="Paddy\'s Auto Tech" class="brand-logo">';
    }
    return '<span class="brand-mark">' . e($fallbackLetter) . '</span>';
}

/* ---------- email ---------- */
/** Render an email address as a clickable mailto: link (or a dash if empty). */
function mailto_link($email, string $label = ''): string
{
    $email = trim((string)$email);
    if ($email === '') return '—';
    $label = $label !== '' ? $label : $email;
    return '<a class="link" href="mailto:' . e($email) . '">' . e($label) . '</a>';
}

/**
 * Send an email from the system using PHP's built-in mail() function.
 * Zero-dependency by design (matches the rest of the app's no-Composer approach).
 * On shared hosting with sendmail/SMTP configured in php.ini this sends for real;
 * locally without a configured MTA it will simply fail and we surface that via flash.
 */
function send_system_email(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $cfg = config();
    $from = $cfg['mail_from'] ?? ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'autoshop.local'));
    $headers = "From: {$cfg['app_name']} <{$from}>\r\n";
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers .= "Reply-To: {$replyTo}\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    // @ suppresses the notice mail() throws when no MTA is configured; we check the return value instead.
    return @mail($to, $subject, $body, $headers);
}
/**
 * Send an SMS via mNotify's Quick SMS API (https://developer.mnotify.com/).
 */
function send_sms(string $to, string $message): bool
{
    $cfg = config();
    $apiKey = $cfg['mnotify']['api_key'] ?? '';
    $sender = $cfg['mnotify']['sender_id'] ?? 'AutoShop';
    if ($apiKey === '' || $apiKey === '') return false;

    // mNotify expects local Ghanaian format, e.g. 0241234567 — strip spaces/dashes/+233.
    $to = preg_replace('/[^0-9]/', '', $to);
    if (str_starts_with($to, '233')) $to = '0' . substr($to, 3);

    $payload = json_encode([
        'recipient'     => [$to],
        'sender'        => $sender,
        'message'       => $message,
        'is_schedule'   => false,
        'schedule_date' => '',
    ]);

    $ch = curl_init('https://api.mnotify.com/api/sms/quick?key=' . urlencode($apiKey));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $response !== false && $httpCode < 300;
}
/** The ~20 most popular car makes seen in the Ghanaian market, for the vehicle registration dropdown. */
function popular_car_makes(): array
{
    return [
        'Toyota', 'Honda', 'Hyundai', 'Kia', 'Nissan', 'Ford', 'Chevrolet',
        'Mercedes-Benz', 'BMW', 'Audi', 'Volkswagen', 'Mitsubishi', 'Mazda',
        'Suzuki', 'Peugeot', 'Land Rover', 'Jeep', 'Isuzu', 'Volvo', 'Renault',
        'Lamborghini', 'Rolls Royce', 'Ferrari', 'Dodge', 'Cadillac'
    ];
}

/* ---------- validation ---------- */
function validate(array $rules, array $data): array
{
    $errors = [];
    foreach ($rules as $field => $ruleset) {
        $val = trim((string)($data[$field] ?? ''));
        foreach (explode('|', $ruleset) as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            if ($name === 'required' && $val === '')         $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            elseif ($name === 'min' && $val !== '' && mb_strlen($val) < (int)$arg) $errors[$field] = ucfirst(str_replace('_',' ',$field)) . " must be at least {$arg} characters.";
            elseif ($name === 'email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) $errors[$field] = 'Enter a valid email address.';
            elseif ($name === 'numeric' && $val !== '' && !is_numeric($val)) $errors[$field] = ucfirst($field) . ' must be a number.';
            if (isset($errors[$field])) break;
        }
    }
    return $errors;
}

/* ================================================================
 *  UI COMPONENT SYSTEM
 * ================================================================ */

/** Master layout. $content is pre-rendered HTML for the page body. */
function layout(string $title, string $content, string $active = ''): string
{
    $u = current_user();
    $cfg = config();
    $brand = e($cfg['app_name']);
    $flash = render_flash();
    $nav = render_sidebar($active);
    $initials = strtoupper(mb_substr($u['name'] ?? '?', 0, 1));
    $role = e(ucfirst($u['role'] ?? ''));
    $name = e($u['name'] ?? '');
    $mark = brand_mark_html('P');
    $page = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} · {$brand} Staff Portal</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<input type="checkbox" id="navtoggle" hidden>
<aside class="sidebar">
  <div class="brand">{$mark}<span class="brand-text">{$brand}<small>Staff Portal</small></span></div>
  {$nav}
</aside>
<div class="main">
  <header class="topbar">
    <label for="navtoggle" class="hamburger" aria-label="Toggle menu">
      <span></span><span></span><span></span>
    </label>
    <h1 class="page-title">{$title}</h1>
    <div class="userbox">
      <div class="avatar">{$initials}</div>
      <div class="usermeta"><strong>{$name}</strong><span>{$role}</span></div>
      <a class="logout" href="/logout" title="Sign out" data-confirm="Sign out now?">⎋</a>
    </div>
  </header>
  <main class="content">
    {$flash}
    {$content}
  </main>
</div>
<script src="/assets/app.js" defer></script>
</body>
</html>
HTML;
    return base_rewrite($page);
}

function render_flash(): string
{
    $out = '';
    foreach (take_flash() as $f) {
        $type = e($f['type']);
        $msg = e($f['msg']);
        $out .= "<div class=\"flash flash-{$type}\" role=\"alert\">{$msg}<button class=\"flash-x js-flash-close\">&times;</button></div>";
    }
    return $out;
}

function render_sidebar(string $active): string
{
    $role = current_user()['role'] ?? '';
    // [path, label, icon, roles]
    $items = [
        ['/',             'Dashboard',    'grid',     ['admin','manager','receptionist','mechanic']],
        ['/jobcards',     'Job Cards',    'wrench',   ['admin','manager','receptionist','mechanic']],
        ['/customers',    'Customers',    'users',    ['admin','manager','receptionist']],
        ['/vehicles',     'Vehicles',     'car',      ['admin','manager','receptionist']],
        ['/appointments', 'Appointments', 'calendar', ['admin','manager','receptionist']],
        ['/inventory',    'Inventory',    'box',      ['admin','manager','receptionist','mechanic']],
        ['/services',     'Services',     'list',     ['admin','manager','receptionist']],
        ['/invoices',     'Invoices',     'receipt',  ['admin','manager','receptionist']],
        ['/reports',      'Reports',      'chart',    ['admin','manager']],
        ['/users',        'Users',        'shield',   ['admin']],
        ['/audit',        'Audit Log',    'log',      ['admin']],
    ];
    $html = '<nav class="nav">';
    foreach ($items as [$path, $label, $icon, $roles]) {
        if (!in_array($role, $roles, true)) continue;
        $is = ($active === trim($path, '/') || ($active === 'dashboard' && $path === '/')) ? ' class="active"' : '';
        $svg = icon($icon);
        $html .= "<a href=\"{$path}\"{$is}>{$svg}<span>" . e($label) . "</span></a>";
    }
    $html .= '</nav>';
    return $html;
}

/** Tiny inline icon set (stroke icons, no external deps). */
function icon(string $n): string
{
    $p = [
        'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'wrench'  => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.2L4 17l3 3 5.5-5.3a4 4 0 0 0 5.2-5.4l-2.6 2.6-2.2-.4-.4-2.2z"/>',
        'users'   => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 6a3 3 0 0 1 0 6"/><path d="M18 20a6 6 0 0 0-3-5"/>',
        'car'     => '<path d="M3 13l2-5a2 2 0 0 1 2-1h10a2 2 0 0 1 2 1l2 5"/><rect x="2" y="13" width="20" height="5" rx="1"/><circle cx="7" cy="18" r="1.5"/><circle cx="17" cy="18" r="1.5"/>',
        'calendar'=> '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        'box'     => '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>',
        'list'    => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'receipt' => '<path d="M5 3v18l2-1 2 1 2-1 2 1 2-1 2 1V3l-2 1-2-1-2 1-2-1-2 1-2-1z"/><path d="M8 8h8M8 12h8"/>',
        'chart'   => '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>',
        'shield'  => '<path d="M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6l8-4z"/>',
        'log'     => '<path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/>',
    ];
    $body = $p[$n] ?? '';
    return "<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\">{$body}</svg>";
}

/* ---------- page widgets ---------- */
function page_header(string $title, string $actions = ''): string
{
    return "<div class=\"page-head\"><div></div><div class=\"page-actions\">{$actions}</div></div>";
}

function btn(string $label, string $href, string $variant = 'primary'): string
{
    return "<a class=\"btn btn-{$variant}\" href=\"" . e($href) . "\">" . $label . "</a>";
}

function badge(string $text, string $tone = 'gray'): string
{
    return "<span class=\"badge badge-{$tone}\">" . e($text) . "</span>";
}

function status_badge(string $status): string
{
    $map = ['open'=>'blue','in_progress'=>'amber','completed'=>'green','closed'=>'gray',
            'unpaid'=>'red','partial'=>'amber','paid'=>'green',
            'scheduled'=>'blue','cancelled'=>'gray','pending'=>'amber','declined'=>'red'];
    $tone = $map[$status] ?? 'gray';
    return badge(ucwords(str_replace('_', ' ', $status)), $tone);
}

function stat_card(string $label, string $value, string $ic, string $tone = 'blue'): string
{
    $svg = icon($ic);
    return "<div class=\"stat stat-{$tone}\"><div class=\"stat-ic\">{$svg}</div><div><div class=\"stat-val\">" . $value . "</div><div class=\"stat-lbl\">" . e($label) . "</div></div></div>";
}

/**
 * Render a data table.
 * $columns: ['Header' => fn($row) => html] (closures may return raw html)
 */
function data_table(array $columns, array $rows, string $empty = 'No records yet.'): string
{
    if (!$rows) return "<div class=\"card empty\">" . icon('box') . "<p>" . e($empty) . "</p></div>";
    $head = '';
    foreach (array_keys($columns) as $h) $head .= '<th>' . e($h) . '</th>';
    $body = '';
    foreach ($rows as $r) {
        $body .= '<tr>';
        foreach ($columns as $render) $body .= '<td>' . $render($r) . '</td>';
        $body .= '</tr>';
    }
    return "<div class=\"card table-wrap\"><table class=\"table\"><thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table></div>";
}

/* ---------- form builders ---------- */
function field(string $label, string $name, array $o = []): string
{
    $type = $o['type'] ?? 'text';
    $val  = e($o['value'] ?? '');
    $err  = $o['error'] ?? '';
    $req  = !empty($o['required']) ? ' required' : '';
    $ph   = isset($o['placeholder']) ? ' placeholder="' . e($o['placeholder']) . '"' : '';
    $cls  = $err ? ' class="invalid"' : '';
    $hint = isset($o['hint']) ? "<small class=\"hint\">" . e($o['hint']) . "</small>" : '';
    $emsg = $err ? "<small class=\"err\">" . e($err) . "</small>" : '';
    $star = !empty($o['required']) ? ' <span class="req">*</span>' : '';

    if ($type === 'textarea') {
        $ctrl = "<textarea name=\"{$name}\"{$cls}{$req}{$ph} rows=\"" . ($o['rows'] ?? 3) . "\">{$val}</textarea>";
    } elseif ($type === 'select') {
        $opts = '';
        foreach ($o['options'] as $ov => $ol) {
            $sel = ((string)($o['value'] ?? '') === (string)$ov) ? ' selected' : '';
            $opts .= "<option value=\"" . e($ov) . "\"{$sel}>" . e($ol) . "</option>";
        }
        $ctrl = "<select name=\"{$name}\"{$cls}{$req}>{$opts}</select>";
    } else {
        $step = $type === 'number' ? ' step="' . ($o['step'] ?? 'any') . '"' : '';
        if ($type === 'password') {
            $pwId = 'pwd_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name) . '_' . substr(md5($name . microtime()), 0, 6);
            $ctrl = "<div class=\"pwd-wrap\">"
                  . "<input type=\"password\" id=\"{$pwId}\" name=\"{$name}\" value=\"{$val}\"{$cls}{$req}{$ph}>"
                  . "<button type=\"button\" class=\"pwd-toggle\" data-target=\"{$pwId}\">Show</button>"
                  . "</div>";
       } else {
            $min = isset($o['min']) ? ' min="' . e($o['min']) . '"' : '';
            $max = isset($o['max']) ? ' max="' . e($o['max']) . '"' : '';
            $ctrl = "<input type=\"{$type}\" name=\"{$name}\" value=\"{$val}\"{$cls}{$req}{$ph}{$step}{$min}{$max}>";
        }
    }
    return "<div class=\"form-group\"><label>" . e($label) . "{$star}</label>{$ctrl}{$hint}{$emsg}</div>";
}

function form_card(string $heading, string $action, string $fields, string $submit = 'Save', string $back = ''): string
{
    $backbtn = $back ? "<a class=\"btn btn-ghost\" href=\"" . e($back) . "\">Cancel</a>" : '';
    $csrf = csrf_field();
    return <<<HTML
<div class="card form-card">
  <h2 class="card-title">{$heading}</h2>
  <form method="post" action="{$action}" novalidate>
    {$csrf}
    {$fields}
    <div class="form-actions">{$backbtn}<button type="submit" class="btn btn-primary">{$submit}</button></div>
  </form>
</div>
HTML;
}
